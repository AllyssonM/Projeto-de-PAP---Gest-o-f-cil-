<?php
/* =========================================================================
   ENDEREÇO IP DO CLIENTE ATRÁS DE PROXIES  (includes/client_ip.php)
   -------------------------------------------------------------------------
   POR OMISSÃO o Lumina usa só REMOTE_ADDR (o endereço que se ligou ao servidor) e IGNORA o cabeçalho X-Forwarded-For: qualquer pessoa
   consegue escrever esse cabeçalho, por isso confiar nele deixaria um atacante escolher o IP que quiser (e fugir aos limites de pedidos).

   Se o Lumina estiver atrás de um proxy ou CDN (nginx, Cloudflare, o Apache de outra máquina...), todos os pedidos chegam com o IP do proxy e os
   limites por IP passariam a valer para toda a gente ao mesmo tempo. Nesse caso (e só nesse) indique os proxies em que confia:
       LUMINA_TRUSTED_PROXIES=127.0.0.1,10.0.0.0/8,2001:db8::/32      (IPs ou redes CIDR, separados por vírgulas)
   O X-Forwarded-For só é lido quando o pedido vem de um desses proxies; percorre-se da direita para a esquerda e o cliente é o primeiro
   endereço que NÃO é um proxy de confiança. Entradas inválidas são ignoradas; "toda a Internet" (/0) nunca é aceite.
   ========================================================================= */
declare(strict_types=1);

require_once __DIR__ . '/env.php';

/** IPv4 mapeado em IPv6 (::ffff:1.2.3.4) passa a 4 bytes, para se comparar com redes IPv4. */
function ip_binary_normalize(string $bin): string
{
    return strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff") ? substr($bin, 12) : $bin;
}

/** Lista de proxies de confiança a partir de texto ("ip" ou "ip/bits", separados por vírgulas, espaços ou ;). @return list<array{0:string,1:int}> [bytes do endereço, bits da rede] */
function trusted_proxy_list(?string $raw): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $item) {
        $parts = explode('/', $item, 2);
        $bin = @inet_pton($parts[0]);
        if ($bin === false) { continue; }
        $normalized = ip_binary_normalize($bin);
        if (isset($parts[1]) && $normalized !== $bin) { continue; }      // rede escrita como ::ffff:a.b.c.d/N: ambíguo, ignora-se (escreva a rede em IPv4)
        $max = strlen($normalized) * 8;
        $bits = isset($parts[1]) ? (ctype_digit($parts[1]) ? (int)$parts[1] : 0) : $max;
        if ($bits < 1 || $bits > $max) { continue; }                      // inválido ou /0: confiar em toda a Internet anularia a proteção
        $out[] = [$normalized, $bits];
    }
    return $out;
}

/** Os primeiros $bits bits dos dois endereços (em bytes) são iguais? */
function ip_prefix_match(string $a, string $b, int $bits): bool
{
    if (strlen($a) !== strlen($b)) { return false; }
    $bytes = intdiv($bits, 8);
    if ($bytes > 0 && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) { return false; }
    $rest = $bits % 8;
    if ($rest === 0) { return true; }
    $mask = (0xFF << (8 - $rest)) & 0xFF;
    return ((ord($a[$bytes]) ^ ord($b[$bytes])) & $mask) === 0;
}

/** Este endereço (texto) pertence a algum dos proxies/redes de confiança? */
function ip_is_trusted(string $ip, array $trusted): bool
{
    $bin = @inet_pton($ip);
    if ($bin === false) { return false; }
    $bin = ip_binary_normalize($bin);
    foreach ($trusted as [$net, $bits]) {
        if (ip_prefix_match($bin, $net, $bits)) { return true; }
    }
    return false;
}

/**
 * O IP do cliente: $remote (quem se ligou ao servidor) ou, se $remote for um proxy de confiança, o cliente indicado em X-Forwarded-For.
 * Qualquer dúvida (cabeçalho com lixo, só proxies na lista...) devolve $remote: nunca se escolhe um endereço que não se possa confirmar.
 */
function forwarded_client_ip(string $remote, string $forwardedFor, array $trusted): string
{
    if ($trusted === [] || trim($forwardedFor) === '' || !ip_is_trusted($remote, $trusted)) { return $remote; }
    $hops = array_slice(array_reverse(array_map('trim', explode(',', $forwardedFor))), 0, 20);
    foreach ($hops as $hop) {
        if (@inet_pton($hop) === false) { return $remote; }       // lixo (ou porta, ou formato estranho): não adivinhar
        if (!ip_is_trusted($hop, $trusted)) { return $hop; }
    }
    return $remote;
}

/** IP deste pedido (máx. 45 caracteres: cabe numa coluna VARCHAR(45)). */
function client_ip(): string
{
    static $trusted = null;
    $remote = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $trusted ??= trusted_proxy_list(lumina_env('LUMINA_TRUSTED_PROXIES'));
    if ($trusted === []) { return $remote; }
    return substr(forwarded_client_ip($remote, (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''), $trusted), 0, 45);
}

<?php
/* =========================================================================
   LIMITE DE PEDIDOS  (includes/rate_limit.php)
   -------------------------------------------------------------------------
   PARA QUÊ:  travar abuso sem atrapalhar quem usa o Lumina normalmente: inundar a API, tentar palavras-passe, criar contas em massa,
              usar o "esqueci-me" para encher a caixa de correio de alguém, mandar emails em série a partir do servidor.
   COMO:      contador por JANELA FIXA na tabela rate_limits (migração v17). Cada pedido faz um INSERT ... ON DUPLICATE KEY UPDATE (atómico:
              pedidos em simultâneo não se perdem). Passou do máximo na janela atual: resposta 429 com «Retry-After» (segundos) e nada é processado.
   QUEM CONTA:  API autenticada: cada UTILIZADOR (as páginas não contam; uma rede partilhada não prejudica ninguém).
                Ações anónimas (login, registo, recuperar palavra-passe...): cada ENDEREÇO IP (IPv6 por /64); o «esqueci-me» também por email-alvo.
   PRIVACIDADE:  a tabela guarda só um HMAC-SHA256 de «âmbito|chave» (nunca o IP nem o email) e as janelas com mais de 2 horas são apagadas.
   SE A BASE DE DADOS FALHAR (ou a tabela não existir): o pedido passa (falha «aberta») e fica uma linha no log do servidor. O limite é uma defesa
              extra; nunca pode deitar o Lumina abaixo. O /health avisa se a migração v17 faltar.
   AJUSTAR (sem mexer no código), por variável de ambiente:
       LUMINA_RATE_<ÂMBITO>="máximo/segundos"   ex.: LUMINA_RATE_AUTH_REGISTER=50/3600
       LUMINA_RATE_MULTIPLIER=2                  multiplica TODOS os máximos (ex.: uma escola inteira atrás do mesmo IP)
   O limite por IP precisa do IP certo: atrás de um proxy, ver includes/client_ip.php (LUMINA_TRUSTED_PROXIES).
   ========================================================================= */
declare(strict_types=1);

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/client_ip.php';

/** Limites por omissão: âmbito => [máximo de pedidos, janela em segundos]. Medido: uso intenso do painel (2 voltas por todas as secções) ≈ 75 pedidos/min. */
const RATE_LIMIT_DEFAULTS = [
    'api'               => [300, 60],      // por utilizador: qualquer pedido à API autenticada (≈ 5 por segundo, 4× o pico medido)
    'auth_login'        => [120, 600],     // por IP: todas as tentativas de login (as FALHAS por conta têm o limite próprio, mais apertado, em includes/auth.php)
    'auth_register'     => [20, 3600],     // por IP: contas novas
    'auth_forgot'       => [15, 3600],     // por IP: pedidos de «esqueci a palavra-passe»
    'auth_forgot_email' => [5, 3600],      // por email-alvo: ninguém recebe mais de 5 emails de recuperação por hora, venha o pedido de onde vier
    'auth_token'        => [60, 3600],     // por IP: usar links de recuperação/confirmação
    'mail'              => [20, 3600],     // por utilizador: ações que enviam email (convites de equipa, teste de envio)
    'export'            => [10, 3600],     // por utilizador: exportar os dados da conta
    'import'            => [30, 3600],     // por utilizador: pré-visualizar/importar ficheiros CSV
];
const RATE_LIMIT_KEEP_SECONDS = 7200;      // janelas mais antigas do que isto são apagadas (a maior janela é de 1 hora)

/** [máximo, janela] de um âmbito, já com os ajustes de LUMINA_RATE_<ÂMBITO> e LUMINA_RATE_MULTIPLIER. Valores inválidos são ignorados. @return array{0:int,1:int} */
function rate_limit_config(string $scope): array
{
    [$max, $window] = RATE_LIMIT_DEFAULTS[$scope] ?? [60, 60];                 // âmbito desconhecido: valor prudente (um teste garante que não acontece)
    $own = lumina_env('LUMINA_RATE_' . strtoupper($scope));
    if ($own !== null && preg_match('#^\s*(\d{1,7})\s*/\s*(\d{1,6})\s*$#', $own, $m) && (int)$m[1] >= 1 && (int)$m[2] >= 1 && (int)$m[2] <= 86400) {
        $max = (int)$m[1];
        $window = (int)$m[2];
    }
    $factor = lumina_env('LUMINA_RATE_MULTIPLIER');
    if ($factor !== null && is_numeric($factor) && (float)$factor >= 0.01 && (float)$factor <= 100000) {
        $max = max(1, (int)round($max * (float)$factor));
    }
    return [$max, $window];
}

/** Segredo para o HMAC dos baldes. Se a chave da aplicação já existe usa-a (sem a criar: um pedido anónimo não escreve ficheiros); senão, uma constante. */
function rate_limit_pepper(): string
{
    static $pepper = null;
    if ($pepper !== null) { return $pepper; }
    try {
        if (is_file(__DIR__ . '/../config/app_key.php') || (string)lumina_env('GF_APP_KEY') !== '') {
            require_once __DIR__ . '/crypto.php';
            return $pepper = hash_hmac('sha256', 'lumina-rate-limit-v1', app_key(), true);
        }
    } catch (Throwable $e) {
        // sem chave utilizável: segue com a constante
    }
    return $pepper = 'lumina-rate-limit-v1';
}

/** Identificação do balde guardada na base de dados (64 caracteres hexadecimais). */
function rate_limit_bucket(string $scope, string $key): string
{
    return hash_hmac('sha256', $scope . '|' . $key, rate_limit_pepper());
}

/** Chave de um endereço IP: IPv4 tal como está, IPv6 pelos primeiros 64 bits (cada ligação recebe uma rede /64 inteira: limitar por endereço seria inútil). */
function rate_limit_ip_key(string $ip): string
{
    $bin = @inet_pton($ip);
    if ($bin === false) { return 'ip:desconhecido'; }
    $bin = ip_binary_normalize($bin);
    return strlen($bin) === 4 ? 'v4:' . bin2hex($bin) : 'v6:' . bin2hex(substr($bin, 0, 8));
}

/** Apaga as janelas com mais de RATE_LIMIT_KEEP_SECONDS. Devolve quantas linhas apagou. */
function rate_limit_cleanup(?int $now = null): int
{
    $st = db()->prepare('DELETE FROM rate_limits WHERE window_start < ?');
    $st->execute([($now ?? time()) - RATE_LIMIT_KEEP_SECONDS]);
    return $st->rowCount();
}

/**
 * Conta UM pedido no balde e diz se ainda está dentro do limite. Nunca lança erro (falha «aberta»).
 * @return array{allowed:bool,count:int,max:int,window:int,retry_after:int}  retry_after = segundos até a janela acabar (só se não permitido)
 */
function rate_limit_hit(string $scope, string $key, ?int $now = null): array
{
    [$max, $window] = rate_limit_config($scope);
    $now ??= time();
    $start = intdiv($now, $window) * $window;
    $out = ['allowed' => true, 'count' => 0, 'max' => $max, 'window' => $window, 'retry_after' => 0];
    try {
        $pdo = db();
        $bucket = rate_limit_bucket($scope, $key);
        // LAST_INSERT_ID(expr) devolve o valor que ESTE pedido deu ao contador, sem outra leitura: com pedidos em simultâneo, cada um recebe o seu número (1, 2, 3...).
        // Linha nova: o MySQL não define esse valor (fica 0) e o contador é 1.
        $pdo->prepare('INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(hits + 1)')->execute([$bucket, $start]);
        $id = (int)$pdo->lastInsertId();
        $out['count'] = $id > 0 ? $id : 1;
        if ($out['count'] > $max) {
            $out['allowed'] = false;
            $out['retry_after'] = max(1, $start + $window - $now);
            if ($out['count'] === $max + 1) {                                    // uma linha no log por balde e janela (sem IP nem email)
                error_log('[lumina] limite de pedidos excedido: âmbito ' . $scope . ' (máximo ' . $max . ' em ' . $window . ' s)');
            }
        }
        if (random_int(1, 100) === 1) { rate_limit_cleanup($now); }              // limpeza ocasional das janelas antigas
    } catch (Throwable $e) {
        static $reported = false;                                                // uma só linha por pedido, mesmo que haja vários âmbitos
        if (!$reported) { $reported = true; error_log('[lumina] limite de pedidos desligado neste pedido (' . get_class($e) . '): ' . $e->getMessage()); }
        return ['allowed' => true, 'count' => 0, 'max' => $max, 'window' => $window, 'retry_after' => 0];
    }
    return $out;
}

/** Conta o pedido e, se passou o limite, responde 429 (JSON + Retry-After) e termina. */
function rate_limit_enforce(string $scope, string $key): void
{
    $r = rate_limit_hit($scope, $key);
    if ($r['allowed']) { return; }
    $text = 'Demasiados pedidos. Aguarda um momento e tenta outra vez.';
    header('Retry-After: ' . $r['retry_after']);
    json_response(['success' => false, 'error' => function_exists('L') ? L($text) : $text, 'retry_after' => $r['retry_after']], 429);
}

/** O mesmo, para um limite POR ENDEREÇO IP (ações anónimas). */
function rate_limit_enforce_ip(string $scope): void
{
    rate_limit_enforce($scope, rate_limit_ip_key(client_ip()));
}

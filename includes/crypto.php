<?php
/* =========================================================================
   CIFRA DE SEGREDOS  (includes/crypto.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  cifra e decifra pequenos segredos antes de irem para a base de
               dados: o segredo do 2.º passo (TOTP) e os tokens do Google Calendar.
               Assim, mesmo que a base de dados fosse copiada, estes valores não
               ficam legíveis.
   COMO:       libsodium (secretbox: cifra + autenticação, com "nonce" aleatório).
   A CHAVE:    32 bytes aleatórios guardados em config/app_key.php, criado
               automaticamente na primeira utilização. Esse ficheiro está
               protegido pelo .htaccess da pasta config e NÃO deve ser partilhado
               nem enviado para o GitHub. Se o perderes, os segredos cifrados
               deixam de poder ser lidos (o utilizador volta a ligar o Google /
               a ativar o 2.º passo).
   ========================================================================= */
declare(strict_types=1);

const APP_KEY_FILE = __DIR__ . '/../config/app_key.php';

/** Devolve a chave da aplicação (32 bytes). Cria-a se ainda não existir. */
function app_key(): string
{
    $env = getenv('GF_APP_KEY');                              // alternativa: variável de ambiente (hospedagem)
    if (is_string($env) && $env !== '') {
        return hash('sha256', $env, true);
    }
    if (!is_file(APP_KEY_FILE)) {
        $key = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $php = "<?php\n// Chave secreta da aplicação. NÃO partilhar. Ver includes/crypto.php.\nreturn '" . $key . "';\n";
        if (@file_put_contents(APP_KEY_FILE, $php, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível criar config/app_key.php (sem permissão de escrita na pasta config).');
        }
        @chmod(APP_KEY_FILE, 0600);
    }
    $key = base64_decode((string)(require APP_KEY_FILE), true);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('A chave config/app_key.php é inválida.');
    }
    return $key;
}

/** Cifra um texto. Devolve texto seguro para guardar (base64 de nonce + cifra). */
function encrypt_secret(string $plain): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, app_key()));
}

/** Decifra. Devolve null se o valor foi alterado ou a chave mudou. */
function decrypt_secret(?string $stored): ?string
{
    if (!$stored) return null;
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), app_key());
    return $plain === false ? null : $plain;
}

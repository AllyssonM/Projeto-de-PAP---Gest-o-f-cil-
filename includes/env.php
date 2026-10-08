<?php
/* =========================================================================
   VARIÁVEIS DE AMBIENTE E FICHEIROS LOCAIS  (includes/env.php)
   -------------------------------------------------------------------------
   REGRA: palavras-passe, chaves e tokens NUNCA ficam no código nem no GitHub.
   Há duas formas de os dar ao Lumina:
     1. Variáveis de ambiente (ex.: LUMINA_DB_PASS): a melhor numa hospedagem.
     2. Um ficheiro config/<nome>.local.php (ex.: config/database.local.php). Está no .gitignore,
        só existe nesta máquina e o .htaccess da pasta config impede o acesso por URL.
   Prioridade: variável de ambiente  >  ficheiro .local.php  >  ficheiro config/<nome>.php (valores por omissão, sem segredos).
   ========================================================================= */
declare(strict_types=1);

/** Valor de uma variável de ambiente. Inexistente ou vazia = null. */
function lumina_env(string $name): ?string
{
    $v = getenv($name);
    if ($v === false || $v === '') {
        $v = $_SERVER[$name] ?? $_ENV[$name] ?? false;      // SetEnv do Apache / PHP-FPM
    }
    return is_string($v) && $v !== '' ? $v : null;
}

/** Conteúdo (array) de config/<nome>.local.php, se existir. Ficheiro por máquina, ignorado pelo Git. */
function lumina_local_config(string $name, ?string $dir = null): array
{
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
        return [];
    }
    $file = ($dir ?? __DIR__ . '/../config') . '/' . $name . '.local.php';
    if (!is_file($file)) {
        return [];
    }
    $value = require $file;
    return is_array($value) ? $value : [];
}

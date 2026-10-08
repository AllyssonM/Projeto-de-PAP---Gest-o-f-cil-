<?php
/* Testes (estáticos) dos cabeçalhos de segurança do .htaccess. Carregado por tests/run.php.
   O comportamento REAL está noutros dois sítios: tests/apache/check_htaccess.py (Apache a sério) e tests/browser/csp_audit.py (Chromium a sério).
   Aqui garante-se, sem servidor, que a configuração não se desvia sem ninguém dar por isso. */

section('Cabeçalhos de segurança (.htaccess): configuração', function () {
    $root = dirname(__DIR__, 2);
    $ht = (string)file_get_contents($root . '/.htaccess');
    /** valor de uma linha  Header always set <Nome> "<valor>"  (null se não existir) */
    $header = function (string $name) use ($ht): ?string {
        return preg_match('/^Header always set ' . preg_quote($name, '/') . ' "([^"]*)"/m', $ht, $m) ? $m[1] : null;
    };
    /** diretivas de uma CSP:  nome => lista de valores */
    $directives = function (?string $csp): array {
        $out = [];
        foreach (array_filter(array_map('trim', explode(';', (string)$csp))) as $part) {
            $bits = preg_split('/\s+/', $part);
            $out[array_shift($bits)] = $bits;
        }
        return $out;
    };

    $xfo = $header('X-Frame-Options');
    $enforced = $header('Content-Security-Policy');
    $report = $header('Content-Security-Policy-Report-Only');
    $perm = $header('Permissions-Policy');
    $de = $directives($enforced);
    $dr = $directives($report);

    check('X-Frame-Options: SAMEORIGIN (ninguém põe o Lumina numa moldura)', $xfo === 'SAMEORIGIN');
    check('CSP aplicada: frame-ancestors, base-uri e form-action só do próprio site; sem plugins (object-src none)',
        ($de['frame-ancestors'] ?? null) === ["'self'"] && ($de['base-uri'] ?? null) === ["'self'"] && ($de['form-action'] ?? null) === ["'self'"] && ($de['object-src'] ?? null) === ["'none'"], (string)$enforced);
    check('CSP aplicada NÃO restringe scripts, estilos, imagens nem ligações (nada disso muda o aspeto ou o funcionamento; torná-la obrigatória é uma decisão a tomar com o dono do projeto)',
        array_diff(array_keys($de), ['frame-ancestors', 'base-uri', 'form-action', 'object-src']) === [], implode(' | ', array_keys($de)));
    check('o cabeçalho "X-Frame-Options" e o frame-ancestors dizem o mesmo (moldura só do próprio site)', ($de['frame-ancestors'] ?? []) === ["'self'"] && ($dr['frame-ancestors'] ?? []) === ["'self'"]);

    check('CSP só de relatório existe e tem a estrutura certa (default-src self; sem plugins; sem frames)',
        $report !== null && ($dr['default-src'] ?? null) === ["'self'"] && ($dr['object-src'] ?? null) === ["'none'"] && ($dr['frame-src'] ?? null) === ["'none'"]);
    $scriptSrc = $dr['script-src'] ?? [];
    check("relatório: script-src sem 'unsafe-eval' nem 'unsafe-inline' (a razão de existir desta política)", $scriptSrc !== [] && !in_array("'unsafe-eval'", $scriptSrc, true) && !in_array("'unsafe-inline'", $scriptSrc, true) && in_array("'self'", $scriptSrc, true));
    check("relatório: nenhum script de terceiros permitido (só 'self' e hashes)", array_filter($scriptSrc, fn($v) => $v !== "'self'" && !str_starts_with($v, "'sha256-")) === [], implode(' ', $scriptSrc));
    check('relatório: as únicas origens externas em connect-src são as do tempo (Open-Meteo)', array_values(array_diff($dr['connect-src'] ?? [], ["'self'"])) === ['https://api.open-meteo.com', 'https://geocoding-api.open-meteo.com']);

    // O hash do script do tema (o mesmo em todas as páginas) tem de ser o do código que as páginas realmente têm.
    $hashes = array_map(fn($v) => substr($v, 8, -1), array_values(array_filter($scriptSrc, fn($v) => str_starts_with($v, "'sha256-"))));
    check('relatório: há um hash (sha256) para o script do tema', count($hashes) === 1, (string)count($hashes));
    foreach (['index.php', 'dashboard.php', 'area-pessoal.php', 'includes/legal.php'] as $file) {
        $src = (string)file_get_contents($root . '/' . $file);
        preg_match_all('#<script>(try\{document\.documentElement\.dataset\.tema=[^<]*)</script>#', $src, $m);
        $got = array_map(fn($code) => base64_encode(hash('sha256', $code, true)), $m[1]);
        check("$file: o script do tema existe uma vez e o seu sha256 é o que a política declara", count($got) === 1 && $got === $hashes, implode(',', $got) . ' vs ' . implode(',', $hashes));
    }

    // Origens externas no JavaScript: nenhuma pode aparecer sem estar na política (senão, ao torná-la obrigatória, a funcionalidade partia-se em silêncio).
    $allowedHosts = ['api.open-meteo.com', 'geocoding-api.open-meteo.com',                    // tempo (connect-src)
        'unpkg.com',                                                                         // visualizador 3D, só se alguém configurar data-spline-url (adormecido; ver docs/SEGURANCA_CABECALHOS.md)
        'www.w3.org',                                                                        // namespaces SVG/XML (não são pedidos de rede)
        'www.opensource.org', 'www.denso-wave.com', 'www.d-project.com', 'stackoverflow.com']; // só aparecem em comentários da biblioteca de códigos QR
    $found = [];        // origem => ficheiros onde aparece
    $scan = function (string $path) use (&$found): void {
        if (preg_match_all('#https?://([a-z0-9.-]+)#i', (string)file_get_contents($path), $mm)) {
            foreach ($mm[1] as $host) { $host = rtrim(strtolower($host), '.'); if ($host !== '') { $found[$host][] = basename($path); } }
        }
    };
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/assets/js', FilesystemIterator::SKIP_DOTS)) as $js) {
        if ($js->getExtension() === 'js') { $scan($js->getPathname()); }
    }
    $scan($root . '/sw.js');
    $unknown = array_diff_key($found, array_flip($allowedHosts));
    check('o JavaScript do site não fala com origens externas fora da lista conhecida (se falhar: acrescentar a origem à CSP em .htaccess e a esta lista, e rever a privacidade)',
        $unknown === [], json_encode(array_map('array_unique', $unknown)));

    check('Permissions-Policy: câmara, microfone, pagamentos, USB e sensores desligados; localização só no próprio site (tempo no painel)',
        $perm !== null && str_contains($perm, 'camera=()') && str_contains($perm, 'microphone=()') && str_contains($perm, 'payment=()') && str_contains($perm, 'usb=()')
        && str_contains($perm, 'geolocation=(self)') && !str_contains($perm, '*'));
    check('X-Permitted-Cross-Domain-Policies: none', $header('X-Permitted-Cross-Domain-Policies') === 'none');
    check('a versão do PHP não é anunciada: "Header unset" (respostas normais do PHP) e "Header always unset" (erros)', (bool)preg_match('/^Header unset X-Powered-By\s*$/m', $ht) && (bool)preg_match('/^Header always unset X-Powered-By\s*$/m', $ht));
    check('HSTS só quando a ligação é segura (não parte o XAMPP em http)', (bool)preg_match('/^Header always set Strict-Transport-Security "max-age=\d{7,}"[^\n]*expr=%\{HTTPS\}/m', $ht));

    // Ficheiros que o Lumina cria e que nunca podem ser descarregados por URL.
    preg_match('/<FilesMatch "\\\\\.\(([^)]*)\)\$">\s*Require all denied/', $ht, $fm);
    $denied = explode('|', $fm[1] ?? '');
    $need = ['sql', 'gz', 'enc', 'sha256', 'parcial', 'log', 'env', 'ini', 'bak', 'md', 'sh', 'bat'];
    check('FilesMatch: cópias de segurança (.sql.gz, .enc, .sha256, .parcial), registos, ambientes, scripts e documentação negados', array_diff($need, $denied) === [], 'faltam: ' . implode(',', array_diff($need, $denied)));
    foreach (['bin', 'config', 'includes', 'database', 'tests', 'docs', 'storage'] as $dir) {
        check("$dir/.htaccess nega tudo", is_file("$root/$dir/.htaccess") && (bool)preg_match('/^Require all denied\s*$/m', (string)file_get_contents("$root/$dir/.htaccess")));
    }
});

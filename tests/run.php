<?php
/* =========================================================================
   TESTES AUTOMÁTICOS DO LUMINA  (tests/run.php)
   -------------------------------------------------------------------------
   COMO CORRER (precisa do Lumina a funcionar e da base de dados instalada):
       php tests/run.php                      testa http://localhost/lumina/
       LUMINA_URL=http://127.0.0.1:8080/ php tests/run.php
       php tests/run.php validator            só os testes cujo nome contém "validator"
   O QUE TESTA:  1) unidades (Validator, email): não precisam de servidor;
                 2) API: registo, recuperar palavra-passe, confirmar email, exportar e eliminar
                    a conta (RGPD), permissões e isolamento entre negócios.
   Os testes criam contas com emails "teste-*@lumina.test" e apagam-nas no fim.
   Sai com código 0 se tudo passar, 1 se algo falhar (serve para automatizar).
   ========================================================================= */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }          // só se corre na linha de comandos, nunca pela web
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validator.php';
require_once __DIR__ . '/../includes/account_tokens.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/mail_templates.php';
require_once __DIR__ . '/../includes/legal.php';
require_once __DIR__ . '/../includes/fiscal.php';
require_once __DIR__ . '/../includes/recurring.php';
require_once __DIR__ . '/../includes/seo.php';
require_once __DIR__ . '/../includes/csv_import.php';
require_once __DIR__ . '/../includes/team.php';

$GLOBALS['BASE'] = rtrim(getenv('LUMINA_URL') ?: 'http://localhost/lumina/', '/') . '/';
$GLOBALS['FILTER'] = $argv[1] ?? '';
$GLOBALS['pass'] = $GLOBALS['fail'] = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    $GLOBALS[$ok ? 'pass' : 'fail']++;
    echo ($ok ? '  ok   ' : '  FALHOU ') . $name . ($detail !== '' && !$ok ? "  -> $detail" : '') . "\n";
}
function section(string $title, callable $fn): void
{
    if ($GLOBALS['FILTER'] !== '' && stripos($title, $GLOBALS['FILTER']) === false) return;
    echo "\n== $title ==\n";
    try { $fn(); } catch (Throwable $e) { check('erro inesperado: ' . $e->getMessage(), false); }
}

/** Cliente HTTP mínimo com cookies (uma "sessão de navegador"). */
final class Client
{
    private string $jar;
    public string $csrf = '';
    public function __construct() { $this->jar = tempnam(sys_get_temp_dir(), 'lt'); }
    public function __destruct() { @unlink($this->jar); }
    /** @return array{0:int,1:array,2:string} estado, JSON descodificado, corpo em bruto */
    public function req(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init($GLOBALS['BASE'] . ltrim($path, '/'));
        $headers = ['Accept: application/json'];
        if ($body !== null) { $body += $this->csrf !== '' && !isset($body['csrf']) ? ['csrf' => $this->csrf] : []; $headers[] = 'Content-Type: application/json'; }
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 20, CURLOPT_HEADER => false, CURLOPT_POSTFIELDS => $body !== null ? json_encode($body) : null]);
        $raw = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode($raw, true);
        return [$status, is_array($json) ? $json : [], $raw];
    }
    /** Envia um ficheiro (multipart). @return array{0:int,1:array,2:string} */
    public function upload(string $path, array $fields, string $filename, string $content, string $mime = 'application/octet-stream'): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up'); file_put_contents($tmp, $content);
        $ch = curl_init($GLOBALS['BASE'] . ltrim($path, '/'));
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 30,
            CURLOPT_POSTFIELDS => $fields + ['csrf' => $this->csrf, 'file' => new CURLFile($tmp, $mime, $filename)]]);
        $raw = (string)curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); @unlink($tmp);
        $json = json_decode($raw, true);
        return [$status, is_array($json) ? $json : [], $raw];
    }
    public function login(string $email, string $password): int
    {
        [$s, $d] = $this->req('POST', 'api/auth.php?action=login', ['email' => $email, 'password' => $password]);
        $this->csrf = $d['csrf'] ?? '';
        return $s;
    }
    public function register(string $name, string $email, string $password): array
    {
        [$s, $d] = $this->req('POST', 'api/auth.php?action=register', ['name' => $name, 'email' => $email, 'password' => $password]);
        $this->csrf = $d['csrf'] ?? '';
        return [$s, $d];
    }
}


/** GET sem seguir redirecionamentos, devolvendo também os cabeçalhos (em minúsculas). @return array{0:int,1:array,2:string} */
function http_get(string $path, array $extra = []): array
{
    $url = preg_match('#^https?://#', $path) ? $path : $GLOBALS['BASE'] . ltrim($path, '/');
    $ch = curl_init($url);
    $headers = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $extra, CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$headers) {
        if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $headers[strtolower(trim($k))] = trim($v); } return strlen($line); }]);
    $body = (string)curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$status, $headers, $body];
}
/** Lê uma página HTML e devolve o DOMXPath (para ver título, descrição, h1, imagens...). */
function html_xpath(string $html): DOMXPath
{
    $d = new DOMDocument(); libxml_use_internal_errors(true); $d->loadHTML('<?xml encoding="utf-8" ?>' . $html); libxml_clear_errors();
    return new DOMXPath($d);
}
$GLOBALS['PUBLIC'] = ['' => 'index.php', 'politica-privacidade' => 'politica-privacidade', 'politica-cookies' => 'politica-cookies', 'termos-e-condicoes' => 'termos-e-condicoes', 'informacao-legal' => 'informacao-legal'];

/** GET com a sessão de um Client, devolvendo os cabeçalhos da resposta (em minúsculas). */
function http_get_headers_with(Client $c, string $path): array
{
    $jar = (new ReflectionProperty($c, 'jar'))->getValue($c);
    $headers = []; $ch = curl_init($GLOBALS['BASE'] . ltrim($path, '/'));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_HEADERFUNCTION => function ($x, $l) use (&$headers) { if (str_contains($l, ':')) { [$k, $v] = explode(':', $l, 2); $headers[strtolower(trim($k))] = trim($v); } return strlen($l); }]);
    curl_exec($ch); $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$st, $headers];
}

function cleanup_test_users(): void
{
    $ids = db()->query("SELECT id FROM users WHERE email LIKE 'teste-%@lumina.test'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        db()->exec("DELETE FROM audit_log WHERE user_id IN ($in) OR tenant_id IN ($in)");
        db()->exec("DELETE FROM users WHERE id IN ($in)");
    }
    db()->exec("DELETE FROM login_attempts WHERE email LIKE '%@lumina.test'");
}
/** Lê o último email gravado pelo driver "log" e devolve o link que ele contém. */
function last_mail_link(string $needle, array $before): ?string
{
    $new = array_values(array_diff(glob(__DIR__ . '/../storage/mail/*.eml') ?: [], $before));
    if (!$new) return null;
    $msg = file_get_contents(end($new));
    if (!preg_match('#Content-Type: text/html[^\n]*\r?\nContent-Transfer-Encoding: base64\r?\n\r?\n([A-Za-z0-9+/=\r\n]+)#', $msg, $m)) return null;
    return preg_match('#href="([^"]*' . preg_quote($needle, '#') . '[^"]*)"#', base64_decode(preg_replace('/\s+/', '', $m[1])), $l) ? html_entity_decode($l[1]) : null;
}
function mail_files(): array { return glob(__DIR__ . '/../storage/mail/*.eml') ?: []; }

/* ============================ 1) UNIDADES ============================ */

section('Validator: texto', function () {
    $v = Validator::make(['a' => '  olá  ', 'b' => str_repeat('x', 300), 'c' => ''])->text('a', 'A', 10)->text('b', 'B', 255)->text('c', 'C', 20, false, 'padrão');
    check('apara espaços', $v->data()['a'] === 'olá');
    check('recusa texto acima do máximo', isset($v->errors()['b']));
    check('opcional vazio fica com o valor por omissão', $v->data()['c'] === 'padrão');
    check('obrigatório vazio falha', Validator::make([])->text('x', 'X')->fails());
    check('recusa arrays no lugar de texto', Validator::make(['x' => ['a']])->text('x', 'X')->fails());
});
section('Validator: dinheiro', function () {
    $ok = fn($val, bool $pos = true) => !Validator::make(['m' => $val])->money('m', 'M', $pos)->fails();
    check('aceita 12.5 e "12,5"', $ok(12.5) && $ok('12,5'));
    check('arredonda a 2 casas', Validator::make(['m' => '10.005'])->money('m', 'M')->data()['m'] === 10.01);
    check('recusa zero e negativos quando tem de ser positivo', !$ok(0) && !$ok(-5));
    check('aceita zero quando pode ser zero', $ok(0, false));
    check('recusa texto, NaN e infinito', !$ok('abc') && !$ok(NAN) && !$ok(INF));
    check('recusa valores absurdos', !$ok(1e15));
});
section('Validator: inteiros, listas e identificadores', function () {
    check('inteiro dentro do intervalo', !Validator::make(['n' => '5'])->int('n', 'N', 0, 10)->fails());
    check('recusa 1.5 e fora do intervalo', Validator::make(['n' => '1.5'])->int('n', 'N')->fails() && Validator::make(['n' => 11])->int('n', 'N', 0, 10)->fails());
    check('enum só aceita a lista', !Validator::make(['t' => 'income'])->enum('t', 'T', ['income', 'expense'])->fails() && Validator::make(['t' => 'x'])->enum('t', 'T', ['income', 'expense'])->fails());
    check('id vazio ou 0 -> null; negativo falha', Validator::make(['i' => ''])->id('i')->data()['i'] === null && Validator::make(['i' => '0'])->id('i')->data()['i'] === null && Validator::make(['i' => -3])->id('i')->fails());
});
section('Validator: datas e emails', function () {
    check('data válida', !Validator::make(['d' => '2026-02-28'])->date('d', 'D')->fails());
    check('recusa 2026-02-30 e lixo', Validator::make(['d' => '2026-02-30'])->date('d', 'D')->fails() && Validator::make(['d' => 'amanhã'])->date('d', 'D')->fails());
    check('ano 3000 recusado', Validator::make(['d' => '3000-01-01'])->date('d', 'D')->fails());
    $dt = Validator::make(['d' => '2026-10-03T14:30'])->datetime('d', 'D');
    check('datetime aceita o "T" e normaliza', $dt->data()['d'] === '2026-10-03 14:30:00');
    check('datetime recusa hora 25:00', Validator::make(['d' => '2026-10-03 25:00'])->datetime('d', 'D')->fails());
    check('email válido em minúsculas', Validator::make(['e' => ' Ana@Exemplo.PT '])->email('e')->data()['e'] === 'ana@exemplo.pt');
    check('email inválido', Validator::make(['e' => 'ana@'])->email('e')->fails());
});
section('Email: segurança dos cabeçalhos', function () {
    check('remove quebras de linha de um cabeçalho', mail_header_safe("a\r\nBcc: x@y.pt") === 'a Bcc: x@y.pt');
    $b = ''; $msg = mail_build("vitima@x.pt\r\nBcc: espiao@y.pt", "Assunto\r\nBcc: z@y.pt", '<p>olá</p>', 'olá', $b);
    check('uma tentativa de injeção não cria um cabeçalho Bcc', !preg_match('/^Bcc:/mi', $msg));
    check('mensagem multipart com texto e HTML', str_contains($msg, 'text/plain') && str_contains($msg, 'text/html'));
    check('endereço inválido é recusado', send_mail('isto-nao-e-email', 'x', '<p>x</p>') === false);
});


section('Markdown: segurança e formato', function () {
    $h = md_render("## Título\n\nTexto com <script>alert(1)</script> e **negrito** e [link](javascript:alert(1)) e [ok](https://exemplo.pt).\n\n- um\n- dois\n\nTabela: legenda\n| A | B |\n|---|---|\n| 1 | 2 |", ['nome' => 'X']);
    check('HTML e <script> são escapados', !str_contains($h, '<script') && str_contains($h, '&lt;script&gt;'));
    check('ligação javascript: é removida (fica só o texto)', !str_contains($h, 'javascript:') && str_contains($h, 'link'));
    check('ligação https abre num separador seguro', str_contains($h, 'href="https://exemplo.pt"') && str_contains($h, 'rel="noopener noreferrer"'));
    check('títulos, negrito, listas e tabela com legenda', str_contains($h, '<h2>') && str_contains($h, '<strong>negrito</strong>') && substr_count($h, '<li>') === 2 && str_contains($h, '<caption class="sr-only">legenda</caption>'));
    check('marcadores {{x}} trocados por HTML seguro', str_contains(md_render('Olá {{nome}}', ['nome' => '&lt;b&gt;']), '&lt;b&gt;') && !str_contains(md_render('Olá {{nome}}', ['nome' => 'Lumina']), '{{'));
    check('o título vem da primeira linha', md_title("# O Meu Título\n\ntexto") === 'O Meu Título');
});
section('Páginas legais e de conta (públicas)', function () {
    foreach (['politica-privacidade' => 'Política de Privacidade', 'politica-cookies' => 'Política de Cookies', 'termos-e-condicoes' => 'Termos e Condições', 'informacao-legal' => 'Informação legal',
              'esqueci-palavra-passe.php' => 'Recuperar a palavra-passe'] as $slug => $title) {
        [$s, , $raw] = (new Client())->req('GET', $slug);
        check("$slug abre sem login e mostra «$title»", $s === 200 && str_contains($raw, "<h1>$title</h1>"));
    }
    [, , $raw] = (new Client())->req('GET', 'redefinir-palavra-passe.php?token=' . str_repeat('a', 43));
    check('link de recuperação inventado mostra «já não é válido»', str_contains($raw, 'já não é válido'));
    [, , $raw] = (new Client())->req('GET', 'politica-privacidade');
    check('sem {{marcadores}} por substituir nem PHP exposto', !str_contains($raw, '{{') && !str_contains($raw, '<?'));
    check('o rodapé tem as 4 ligações legais', substr_count($raw, 'href="politica-privacidade"') >= 1 && str_contains($raw, 'href="informacao-legal"') && str_contains($raw, 'href="termos-e-condicoes"') && str_contains($raw, 'href="politica-cookies"'));
});


section('Calendário fiscal: regras', function () {
    $tz = new DateTimeZone('Europe/Lisbon'); $from = new DateTimeImmutable('2026-10-03', $tz);
    $titles = fn(array $a, int $d = 75) => array_map(fn($x) => $x['date'] . ' ' . $x['title'], fiscal_deadlines($a, $from, $d)['deadlines']);
    $q = $titles(['vat' => 'quarterly']);
    check('IVA trimestral: declaração a 15 e pagamento a 25 de novembro', in_array('2026-11-15 IVA: entregar a declaração periódica', $q, true) && in_array('2026-11-25 IVA: pagar', $q, true));
    $m = $titles(['vat' => 'monthly']);
    check('IVA mensal: dias 10 e 25 de cada mês', in_array('2026-10-10 IVA: entregar a declaração periódica', $m, true) && in_array('2026-12-10 IVA: entregar a declaração periódica', $m, true) === true);
    check('isento de IVA: nenhuma data de IVA', count(array_filter($titles(['vat' => 'exempt']), fn($t) => str_contains($t, 'IVA'))) === 0);
    $ss = $titles(['ss' => 'independent']);
    check('Segurança Social independente: declaração trimestral no último dia de outubro', in_array('2026-10-31 Segurança Social: declaração trimestral', $ss, true));
    check('sem respostas de IVA/SS não há datas dessas áreas', count(array_filter($titles([]), fn($t) => str_contains($t, 'IVA') || str_contains($t, 'Segurança'))) === 0);
    $apr = array_map(fn($x) => $x['date'], fiscal_deadlines([], new DateTimeImmutable('2027-03-20', $tz), 120)['deadlines']);
    check('IRS: 1 de abril e 30 de junho', in_array('2027-04-01', $apr, true) && in_array('2027-06-30', $apr, true));
    $all = fiscal_deadlines(['vat' => 'monthly', 'ss' => 'independent'], $from, 400)['deadlines'];
    check('ordenadas por data, nunca no passado e com "dias" certos', $all === array_values(array_filter($all, fn($x) => $x['days'] >= 0)) && array_column($all, 'date') === (function ($d) { sort($d); return $d; })(array_column($all, 'date')) && $all[0]['days'] === (int)$from->diff(new DateTimeImmutable($all[0]['date'], $tz))->format('%a'));
    check('fevereiro: dia 25 existe e a data "último dia" cai no fim do mês', (function () use ($tz) { $r = fiscal_deadlines(['ss' => 'independent'], new DateTimeImmutable('2028-01-01', $tz), 40)['deadlines']; return in_array('2028-01-31', array_column($r, 'date'), true); })());
});


section('Recorrentes: datas', function () {
    $tz = new DateTimeZone('Europe/Lisbon'); $seq = function (string $start, string $freq, int $n) use ($tz) { $d = new DateTimeImmutable($start, $tz); $anchor = (int)$d->format('j'); $o = []; for ($i = 0; $i < $n; $i++) { $o[] = $d->format('Y-m-d'); $d = recurring_next($d, $freq, $anchor); } return $o; };
    check('mensal a 31: fev fica a 28 e março volta a 31 (sem derivar)', $seq('2026-01-31', 'monthly', 4) === ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30']);
    check('mensal em ano bissexto: fev a 29', $seq('2028-01-31', 'monthly', 2)[1] === '2028-02-29');
    check('semanal: de 7 em 7 dias', $seq('2026-10-03', 'weekly', 3) === ['2026-10-03', '2026-10-10', '2026-10-17']);
    check('trimestral atravessa o ano', $seq('2026-11-15', 'quarterly', 3) === ['2026-11-15', '2027-02-15', '2027-05-15']);
    check('anual a 29 de fevereiro: 28 nos anos normais e 29 no bissexto', $seq('2028-02-29', 'yearly', 5) === ['2028-02-29', '2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29']);
});


section('SEO: peças (unidades)', function () {
    check('meta descrição corta em palavras inteiras e com reticências', (function () { $t = seo_trim(str_repeat('palavra ', 60), 158); return mb_strlen($t) <= 158 && str_ends_with($t, '…') && !str_contains($t, 'palavr…'); })());
    check('texto curto não é cortado nem leva reticências', seo_trim('Olá mundo.') === 'Olá mundo.');
    check('md_description usa a linha «Descrição:» e limpa a formatação', md_description("# T\n\nDescrição: Isto é **forte** e [link](x).\n\nOutro.") === 'Isto é forte e link.');
    check('sem «Descrição:», usa o primeiro parágrafo (não títulos nem tabelas)', md_description("# T\n\n## Sub\n\n| a | b |\n\nPrimeiro parágrafo aqui.") === 'Primeiro parágrafo aqui.');
    ob_start(); seo_head(['title' => 'Privada', 'index' => false]); $h = ob_get_clean();
    check('página privada: noindex e NADA para partilhar (sem canonical nem Open Graph)', str_contains($h, 'noindex, nofollow') && !str_contains($h, 'canonical') && !str_contains($h, 'og:') && !str_contains($h, 'description'));
    $_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['SCRIPT_NAME'] = '/index.php';
    ob_start(); seo_head(['title' => 'Pública <script>', 'description' => 'D "aspas" & <b>', 'path' => 'x', 'schema' => [['@type' => 'WebPage', 'name' => '</script><script>alert(1)</script>']]]); $h = ob_get_clean();
    check('página pública: canonical, Open Graph, Twitter e robots index', str_contains($h, 'rel="canonical" href="http://localhost/x"') && str_contains($h, 'og:image') && str_contains($h, 'twitter:card') && str_contains($h, 'index, follow'));
    check('título, descrição e dados estruturados são escapados (sem injeção)', !str_contains($h, '<script>alert') && !str_contains($h, '<b>') && str_contains($h, '&lt;script&gt;'));
    check('o JSON-LD continua a ser JSON válido mesmo com texto malicioso', preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $h, $m) === 1 && json_decode($m[1], true) !== null);
    $home = seo_schema_home();
    check('dados estruturados da página inicial: Organization, WebSite e SoftwareApplication', array_column($home, '@type') === ['Organization', 'WebSite', 'SoftwareApplication']);
    check('SEM preços, avaliações nem classificações inventadas', !str_contains(json_encode($home), 'offers') && !str_contains(json_encode($home), 'aggregateRating') && !str_contains(json_encode($home), 'price'));
    $_SERVER['HTTP_HOST'] = 'Ex<ample>"x.com'; check('o anfitrião do pedido é limpo (sem injeção no canonical)', !preg_match('/[<>"]/', app_base_url()));
});
section('SEO: páginas públicas', function () {
    $titles = []; $descs = [];
    foreach ($GLOBALS['PUBLIC'] as $path => $file) {
        $label = $path === '' ? 'início' : $path;
        [$s, $h, $html] = http_get($path === '' ? 'index.php' : $path);
        $x = html_xpath($html);
        $title = trim($x->evaluate('string(//title)')); $desc = trim($x->evaluate('string(//meta[@name="description"]/@content)')); $canon = $x->evaluate('string(//link[@rel="canonical"]/@href)');
        $titles[] = $title; $descs[] = $desc;
        check("[$label] responde 200", $s === 200);
        check("[$label] título com 15 a 65 caracteres ($title)", mb_strlen($title) >= 15 && mb_strlen($title) <= 65, (string)mb_strlen($title));
        check("[$label] meta descrição com 70 a 160 caracteres", mb_strlen($desc) >= 70 && mb_strlen($desc) <= 160, (string)mb_strlen($desc));
        $expected = rtrim($GLOBALS['BASE'], '/') . ($path === '' ? '/' : '/' . $path);
        check("[$label] canonical absoluto e igual ao endereço limpo", $canon === $expected || ($path === '' && rtrim($canon, '/') === rtrim($GLOBALS['BASE'], '/')), "$canon != $expected");
        foreach (['og:title', 'og:description', 'og:url', 'og:image', 'og:type', 'og:locale'] as $p) { if ($x->evaluate("string(//meta[@property='$p']/@content)") === '') check("[$label] falta $p", false); }
        check("[$label] Twitter card grande e htmlLang pt-PT", $x->evaluate('string(//meta[@name="twitter:card"]/@content)') === 'summary_large_image' && $x->evaluate('string(//html/@lang)') === 'pt-PT');
        check("[$label] tem viewport", str_contains($x->evaluate('string(//meta[@name="viewport"]/@content)'), 'width=device-width'));
        check("[$label] SEM noindex (nem etiqueta nem cabeçalho)", !str_contains(strtolower($x->evaluate('string(//meta[@name="robots"]/@content)')), 'noindex') && !str_contains($h['x-robots-tag'] ?? '', 'noindex'));
        check("[$label] exatamente um h1", (int)$x->evaluate('count(//h1)') === 1);
        $levels = array_map(fn($n) => (int)substr($n->nodeName, 1), iterator_to_array($x->query('//h1|//h2|//h3|//h4|//h5|//h6')));
        $skips = 0; for ($i = 1; $i < count($levels); $i++) if ($levels[$i] > $levels[$i - 1] + 1) $skips++;
        check("[$label] hierarquia de títulos sem saltos (h1 > h2 > h3)", $skips === 0 && ($levels[0] ?? 0) === 1, implode('', $levels));
        $semAlt = []; foreach ($x->query('//img') as $img) if (!$img->hasAttribute('alt')) $semAlt[] = $img->getAttribute('src');
        check("[$label] todas as imagens têm alt (decorativas: alt vazio)", $semAlt === [], implode(',', $semAlt));
        $semDim = []; foreach ($x->query('//img') as $img) if (!$img->getAttribute('width') || !$img->getAttribute('height')) $semDim[] = $img->getAttribute('src');
        check("[$label] imagens com largura e altura (evita saltos de layout)", $semDim === [], implode(',', $semDim));
        // i18n-boot.js (3 KB, local) é síncrono de propósito: decide o idioma antes do primeiro desenho e evita o "flash" de português.
        $bloq = []; foreach ($x->query('//script[@src]') as $sc) if (!str_contains($sc->getAttribute('src'), 'i18n-boot.js') && !$sc->hasAttribute('defer') && !$sc->hasAttribute('async')) $bloq[] = $sc->getAttribute('src');
        check("[$label] nenhum script bloqueia o desenho da página", $bloq === [], implode(',', $bloq));
        $ld = $x->query('//script[@type="application/ld+json"]'); $j = $ld->length ? json_decode($ld->item(0)->textContent, true) : null;
        check("[$label] dados estruturados (JSON-LD) válidos", is_array($j) && !empty($j['@graph']) && ($j['@context'] ?? '') === 'https://schema.org');
    }
    check('os títulos das páginas são todos diferentes', count(array_unique($titles)) === count($titles));
    check('as meta descrições são todas diferentes', count(array_unique($descs)) === count($descs));
});
section('SEO: páginas privadas ficam fora do Google', function () {
    foreach (['dashboard.php', 'area-pessoal.php', 'esqueci-palavra-passe.php', 'redefinir-palavra-passe.php?token=x', 'verificar-email.php?token=x', 'api/auth.php?action=me', 'api/data.php?module=summary'] as $p) {
        [, $h, $html] = http_get($p);
        $meta = str_contains($html, 'content="noindex, nofollow"');
        $isApi = str_starts_with($p, 'api/');
        check("$p: cabeçalho X-Robots-Tag noindex" . ($isApi ? '' : ' e etiqueta'), str_contains($h['x-robots-tag'] ?? '', 'noindex') && ($isApi || $meta || str_contains($h['location'] ?? '', 'index.php')));
    }
    [, , $robots] = http_get('robots.txt');
    check('robots.txt bloqueia as pastas internas e indica o sitemap', str_contains($robots, 'Disallow: /api/') && str_contains($robots, 'Disallow: /config/') && str_contains($robots, 'Sitemap: ') && str_contains($robots, 'Allow: /'));
    check('robots.txt NÃO bloqueia as páginas públicas', !preg_match('#Disallow: /(politica|termos|informacao)#', $robots) && !preg_match('#Disallow:\s*/\s*$#m', $robots));
});
section('SEO: sitemap e links quebrados', function () {
    [$s, $h, $xml] = http_get('sitemap.xml');
    $d = new DOMDocument(); $valid = @$d->loadXML($xml); $locs = $valid ? array_map(fn($n) => $n->textContent, iterator_to_array($d->getElementsByTagName('loc'))) : [];
    check('sitemap.xml é XML válido com 5 páginas', $s === 200 && $valid && count($locs) === 5, (string)count($locs));
    check('o sitemap só tem páginas públicas (nunca painel, Área pessoal ou API)', !preg_grep('#dashboard|area-pessoal|/api/|redefinir|esqueci|verificar#', $locs));
    check('todas as páginas do sitemap respondem 200', (function () use ($locs) { foreach ($locs as $l) if (http_get($l)[0] !== 200) return false; return true; })());
    $seo = file_get_contents(__DIR__ . '/../.htaccess'); preg_match('#\^\(([^)]*)\)\$ \$1\.php#', $seo, $m);
    check('a lista de endereços limpos do .htaccess é igual a SEO_PUBLIC_PAGES', $m && explode('|', $m[1]) === SEO_PUBLIC_PAGES);
    // rastreador: todos os links e recursos INTERNOS das páginas públicas têm de existir
    $broken = []; $checked = [];
    foreach ($GLOBALS['PUBLIC'] as $path => $file) {
        $pageUrl = $GLOBALS['BASE'] . ($path === '' ? 'index.php' : $path); [, , $html] = http_get($pageUrl); $x = html_xpath($html);
        foreach ($x->query('//a[@href]|//link[@href]|//script[@src]|//img[@src]|//source[@src]') as $el) {
            $u = $el->getAttribute('href') ?: $el->getAttribute('src');
            if ($u === '' || preg_match('#^(mailto:|tel:|javascript:|data:)#i', $u)) continue;
            if ($u[0] === '#') { if ($x->query('//*[@id="' . substr($u, 1) . '"]')->length === 0) $broken[] = "$file $u (âncora sem destino)"; continue; }
            if (preg_match('#^https?://#i', $u) && !str_starts_with($u, rtrim($GLOBALS['BASE'], '/'))) continue;     // externos: não se testam aqui
            $abs = preg_match('#^https?://#i', $u) ? $u : $GLOBALS['BASE'] . ltrim(preg_replace('#[?\#].*$#', '', $u) ?: $u, '/');
            $abs = preg_replace('#\#.*$#', '', $abs);
            if (isset($checked[$abs])) continue;
            $checked[$abs] = http_get($abs)[0];
            if ($checked[$abs] >= 400) $broken[] = "$file -> $u ({$checked[$abs]})";
        }
    }
    check('NENHUM link interno nem recurso quebrado (' . count($checked) . ' verificados)', $broken === [], implode(' | ', array_slice($broken, 0, 4)));
});
section('SEO: peso das imagens (guarda contra regressões)', function () {
    $big = []; $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../assets', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if (preg_match('/\.(png|jpe?g|webp|gif)$/i', $f->getFilename()) && $f->getSize() > 120 * 1024) $big[] = $f->getFilename() . ' (' . intdiv($f->getSize(), 1024) . ' KB)';
    check('nenhuma imagem em assets/ pesa mais de 120 KB', $big === [], implode(', ', $big));
    check('o fundo é um vídeo leve (desktop < 1 MB, telemóvel < 600 KB) com poster em WebP', is_file(__DIR__ . '/../assets/video/fundo.mp4') && filesize(__DIR__ . '/../assets/video/fundo.mp4') < 1024 * 1024 && filesize(__DIR__ . '/../assets/video/fundo-m.mp4') < 600 * 1024 && is_file(__DIR__ . '/../assets/video/fundo-poster.webp') && !is_file(__DIR__ . '/../assets/css/fundo.webp'));
    $og = @getimagesize(__DIR__ . '/../assets/img/og-lumina.jpg');
    check('a imagem de partilha tem 1200x630 e menos de 200 KB', $og && $og[0] === 1200 && $og[1] === 630 && filesize(__DIR__ . '/../assets/img/og-lumina.jpg') < 200 * 1024);
});

/* ============================ 2) API ============================ */

section('Conta: registo, confirmação de email e recuperação de palavra-passe', function () {
    cleanup_test_users();
    $c = new Client();
    [$s] = $c->register('Teste Curta', 'teste-curta@lumina.test', '1234567');
    check('registo recusa palavra-passe com menos de ' . PASSWORD_MIN_LENGTH . ' caracteres', $s === 400);
    $before = mail_files();
    [$s, $d] = $c->register('Teste Um', 'teste-um@lumina.test', 'palavra-passe-1');
    check('registo ok (201)', $s === 201 && ($d['user']['email_verified'] ?? null) === false);
    $link = last_mail_link('verificar-email.php', $before);
    check('email de confirmação enviado com link', $link !== null);
    if ($link) {
        $html = (string)file_get_contents($link);
        check('o link confirma o email', str_contains($html, 'Email confirmado'));
        check('o mesmo link não funciona duas vezes', str_contains((string)file_get_contents($link), 'Link inválido'));
        [, $me] = $c->req('GET', 'api/auth.php?action=me');
        check('o utilizador passa a ter email confirmado', ($me['user']['email_verified'] ?? false) === true);
    }
    $a = new Client(); $before = mail_files();
    [$s1, $d1] = $a->req('POST', 'api/auth.php?action=forgot', ['email' => 'teste-um@lumina.test']);
    [$s2, $d2] = $a->req('POST', 'api/auth.php?action=forgot', ['email' => 'nao-existe@lumina.test']);
    check('resposta igual exista o email ou não (não revela contas)', $s1 === 200 && $s2 === 200 && ($d1['message'] ?? 1) === ($d2['message'] ?? 2));
    $link = last_mail_link('redefinir-palavra-passe.php', $before);
    check('email de recuperação enviado (só para quem existe)', $link !== null && count(array_diff(mail_files(), $before)) === 1);
    $tok = $link ? substr($link, strpos($link, 'token=') + 6) : 'x';
    [$s] = $a->req('POST', 'api/auth.php?action=reset_password', ['token' => $tok, 'password' => 'curta', 'purpose' => 'reset']);
    check('palavra-passe nova curta é recusada sem gastar o link', $s === 400 && token_peek($tok, 'reset') !== null);
    [$s] = $a->req('POST', 'api/auth.php?action=reset_password', ['token' => $tok, 'password' => 'nova-palavra-2', 'purpose' => 'invite']);
    check('token de recuperação não serve como convite', $s === 400);
    [$s] = $a->req('POST', 'api/auth.php?action=reset_password', ['token' => $tok, 'password' => 'nova-palavra-2', 'purpose' => 'reset']);
    check('guardar a palavra-passe nova', $s === 200);
    [$s] = $a->req('POST', 'api/auth.php?action=reset_password', ['token' => $tok, 'password' => 'outra-palavra-3', 'purpose' => 'reset']);
    check('o link só funciona uma vez', $s === 400);
    check('login com a antiga falha e com a nova funciona', (new Client())->login('teste-um@lumina.test', 'palavra-passe-1') === 401 && (new Client())->login('teste-um@lumina.test', 'nova-palavra-2') === 200);
    $st = db()->prepare('SELECT token_hash FROM account_tokens WHERE user_id = (SELECT id FROM users WHERE email = ?)');
    $st->execute(['teste-um@lumina.test']);
    check('na base de dados só existem resumos (hash), nunca o token', !in_array($tok, $st->fetchAll(PDO::FETCH_COLUMN), true));
    cleanup_test_users();
});

section('Mudar palavra-passe: uma só regra nas duas rotas', function () {
    cleanup_test_users();
    $c = new Client(); $c->register('Teste Dois', 'teste-dois@lumina.test', 'palavra-passe-1');
    foreach (['api/auth.php?action=change_password', 'api/security.php?action=change_password'] as $route) {
        [$s] = $c->req('POST', $route, ['current_password' => 'palavra-passe-1', 'new_password' => 'curta']);
        check("$route recusa palavra-passe curta", $s === 400);
        [$s] = $c->req('POST', $route, ['current_password' => 'errada-errada', 'new_password' => 'nova-palavra-2']);
        check("$route recusa a palavra-passe atual errada", $s === 403);
    }
    [$s] = $c->req('POST', 'api/security.php?action=change_password', ['current_password' => 'palavra-passe-1', 'new_password' => 'nova-palavra-2']);
    check('mudança válida', $s === 200);
    cleanup_test_users();
});

section('RGPD: exportar os meus dados', function () {
    cleanup_test_users();
    $o = new Client(); $o->register('Teste Dono', 'teste-dono@lumina.test', 'palavra-passe-1');
    $o->req('POST', 'api/data.php?module=transactions', ['type' => 'income', 'description' => 'Venda de teste', 'category' => 'Vendas', 'amount' => 25, 'occurred_at' => date('Y-m-d\TH:i')]);
    $o->req('POST', 'api/notes.php', ['action' => 'save', 'title' => 'Nota de teste', 'detail' => 'segredo-da-nota']);
    [$s, $d, $raw] = $o->req('GET', 'api/account.php?action=export');
    check('exportação devolve JSON', $s === 200 && isset($d['conta']));
    check('inclui a conta e o negócio', ($d['conta']['email'] ?? '') === 'teste-dono@lumina.test' && count($d['negocio']['transactions'] ?? []) === 1);
    check('inclui as notas da pessoa', str_contains($raw, 'segredo-da-nota'));
    check('NUNCA inclui segredos', !preg_match('/password_hash|totp_secret|totp_backup|access_token_enc|refresh_token_enc|\$2y\$/', $raw));
    $anon = new Client();
    [$s] = $anon->req('GET', 'api/account.php?action=export');
    check('sem sessão não exporta (401)', $s === 401);
    cleanup_test_users();
});

section('RGPD: eliminar a conta', function () {
    cleanup_test_users();
    $o = new Client(); $o->register('Teste Apagar', 'teste-apagar@lumina.test', 'palavra-passe-1');
    $uid = (int)db()->query("SELECT id FROM users WHERE email='teste-apagar@lumina.test'")->fetchColumn();
    $o->req('POST', 'api/data.php?module=transactions', ['type' => 'expense', 'description' => 'Compra', 'category' => 'Stock', 'amount' => 10, 'occurred_at' => date('Y-m-d\TH:i')]);
    [, $t] = $o->req('POST', 'api/team.php', ['action' => 'save', 'name' => 'Func Teste', 'email' => 'teste-func@lumina.test', 'permissions' => ['calendar']]);
    $fid = (int)($t['id'] ?? 0);
    check('funcionário criado', $fid > 0);
    db()->prepare("INSERT INTO ai_audit_log (user_id, tenant_id, tool_name, status) VALUES (?, ?, 'teste', 'ok')")->execute([$uid, $uid]);
    db()->prepare("INSERT INTO login_attempts (email, ip) VALUES ('teste-func@lumina.test', '127.0.0.1')")->execute();
    $f = new Client();
    $f->login('teste-func@lumina.test', $t['temp_password'] ?? 'x');
    [$s] = $f->req('POST', 'api/account.php', ['action' => 'delete_account', 'password' => 'x', 'confirm' => 'ELIMINAR']);
    check('o funcionário NÃO pode eliminar a conta do negócio', in_array($s, [401, 403], true));
    [$s] = $o->req('POST', 'api/account.php', ['action' => 'delete_account', 'password' => 'palavra-passe-1', 'confirm' => 'apagar']);
    check('exige escrever ELIMINAR', $s === 400);
    [$s] = $o->req('POST', 'api/account.php', ['action' => 'delete_account', 'password' => 'errada-errada', 'confirm' => 'ELIMINAR']);
    check('exige a palavra-passe certa', $s === 403);
    check('nada foi apagado nas tentativas falhadas', (int)db()->query("SELECT COUNT(*) FROM users WHERE id=$uid")->fetchColumn() === 1);
    [$s] = $o->req('POST', 'api/account.php', ['action' => 'delete_account', 'password' => 'palavra-passe-1', 'confirm' => 'ELIMINAR']);
    check('eliminação com tudo certo', $s === 200);
    $ids = "$uid" . ($fid ? ",$fid" : '');
    $left = [];
    foreach (db()->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('user_id','tenant_id','owner_id','employee_id','author_id')")->fetchAll() as $c) {
        $n = (int)db()->query("SELECT COUNT(*) FROM `{$c['TABLE_NAME']}` WHERE `{$c['COLUMN_NAME']}` IN ($ids)")->fetchColumn();
        if ($n > 0) $left[] = $c['TABLE_NAME'] . ".{$c['COLUMN_NAME']}=$n";
    }
    check('não sobra NENHUMA linha do dono nem da equipa em nenhuma tabela', $left === [], implode(', ', $left));
    check('não sobram tentativas de login com o email da equipa', (int)db()->query("SELECT COUNT(*) FROM login_attempts WHERE email LIKE 'teste-func@%'")->fetchColumn() === 0);
    [$s] = $o->req('GET', 'api/auth.php?action=me');
    check('a sessão deixou de existir', ($s === 401) || (($o->req('GET', 'api/auth.php?action=me')[1]['user'] ?? null) === null));
    cleanup_test_users();
});


section('Dados do negócio: validação, isolamento e gráficos no servidor', function () {
    cleanup_test_users();
    $a = new Client(); $a->register('Teste A', 'teste-a@lumina.test', 'palavra-passe-1');
    $b = new Client(); $b->register('Teste B', 'teste-b@lumina.test', 'palavra-passe-1');
    $now = date('Y-m-d\TH:i');
    [, $cl] = $b->req('POST', 'api/clients.php', ['name' => 'Cliente do B']);
    $clientB = (int)($cl['id'] ?? 0);
    $tx = fn($over = []) => $a->req('POST', 'api/data.php?module=transactions', $over + ['type' => 'income', 'description' => 'Venda', 'category' => 'Vendas', 'amount' => 10, 'occurred_at' => $now]);
    check('movimento válido (201)', $tx()[0] === 201);
    check('valor zero ou negativo recusado', $tx(['amount' => 0])[0] === 400 && $tx(['amount' => -5])[0] === 400);
    check('valor em texto recusado', $tx(['amount' => 'abc'])[0] === 400);
    check('data inventada recusada', $tx(['occurred_at' => '2026-02-30T10:00'])[0] === 400 && $tx(['occurred_at' => 'amanhã'])[0] === 400);
    check('descrição gigante recusada', $tx(['description' => str_repeat('x', 5000)])[0] === 400);
    check('tipo inválido recusado', $tx(['type' => 'roubo'])[0] === 400);
    if ($clientB) check('NÃO se liga um movimento a um cliente de OUTRO negócio', $tx(['client_id' => $clientB])[0] === 400);
    $bill = fn($over = []) => $a->req('POST', 'api/data.php?module=bills', $over + ['direction' => 'payable', 'title' => 'Renda', 'amount' => 500, 'due_date' => date('Y-m-d', strtotime('+5 days'))]);
    check('conta válida (201)', $bill()[0] === 201);
    check('conta com valor negativo ou data inválida recusada', $bill(['amount' => -1])[0] === 400 && $bill(['due_date' => '2026-13-01'])[0] === 400);
    $prod = fn($over = []) => $a->req('POST', 'api/data.php?module=products', $over + ['name' => 'Produto', 'cost_price' => 1, 'sale_price' => 2, 'stock_quantity' => 5, 'minimum_stock' => 1]);
    check('produto válido (201)', $prod()[0] === 201);
    check('produto com stock negativo ou preço em texto recusado', $prod(['stock_quantity' => -3])[0] === 400 && $prod(['sale_price' => 'x'])[0] === 400);
    check('conta interna com tipo desconhecido recusada', $a->req('POST', 'api/data.php?module=accounts', ['name' => 'X', 'type' => 'cofre'])[0] === 400);
    // gráficos: calculados no servidor
    $a->req('POST', 'api/data.php?module=transactions', ['type' => 'expense', 'description' => 'Gasto', 'category' => 'G', 'amount' => 4, 'occurred_at' => $now]);
    $a->req('POST', 'api/data.php?module=transactions', ['type' => 'income', 'description' => 'Previsto', 'category' => 'V', 'amount' => 999, 'status' => 'planned', 'occurred_at' => $now]);
    foreach (['day' => 14, 'week' => 8, 'month' => 6, 'months12' => 12, 'year' => 5] as $period => $n) {
        [$s, $d] = $a->req('GET', "api/data.php?module=series&period=$period");
        $ser = $d['series'] ?? ['labels' => [], 'income' => [], 'expense' => []];
        check("série «{$period}»: $n períodos, rótulos e valores alinhados", $s === 200 && count($ser['labels']) === $n && count($ser['income']) === $n && count($ser['expense']) === $n);
    }
    [, $d] = $a->req('GET', 'api/data.php?module=series&period=day');
    check('o dia de hoje (último) soma 10 de entradas e 4 de saídas, e ignora o previsto (999)', end($d['series']['income']) == 10 && end($d['series']['expense']) == 4, json_encode([end($d['series']['income']), end($d['series']['expense'])]));
    [, $d] = $b->req('GET', 'api/data.php?module=series&period=day');
    check('o negócio B não vê os totais do A', array_sum($d['series']['income'] ?? [1]) == 0);
    [$s, $d] = $a->req('GET', 'api/data.php?module=series&period=lixo');
    check('período desconhecido cai no mensal (6)', $s === 200 && count($d['series']['labels']) === 6);
    [, $d] = $a->req('GET', 'api/data.php?module=transactions&limit=2');
    check('listas têm limite e total (paginação)', count($d['items']) === 2 && ($d['total'] ?? 0) === 3 && ($d['limit'] ?? 0) === 2);
    cleanup_test_users();
});

section('Questionário de arranque (API do perfil)', function () {
    cleanup_test_users();
    $a = new Client(); $a->register('Teste Q', 'teste-q@lumina.test', 'palavra-passe-1');
    $P = fn(array $b) => $a->req('POST', 'api/profile.php', $b);
    [, $d] = $P(['business_name' => 'Loja Q', 'business_type' => 'clothing', 'enabled_modules' => ['clients', 'inventado'], 'onboarding' => ['team' => 'small', 'pay' => ['cash', 'lixo'], 'vat' => 'inventado', 'goal' => 'vat', 'goal_amount' => 1500]]);
    check('aceita e devolve só valores conhecidos', ($d['profile']['onboarding']['team'] ?? '') === 'small' && ($d['profile']['onboarding']['pay'] ?? []) === ['cash'] && !isset($d['profile']['onboarding']['vat']));
    check('as 3 abas essenciais ficam sempre ligadas e as desconhecidas desaparecem', ($d['profile']['enabled_modules'] ?? []) === ['overview', 'cashflow', 'reports', 'clients']);
    check('cria a conta de reserva com a meta', ($d['goal_account_created'] ?? false) === true);
    [, $d2] = $P(['business_name' => 'Loja Q', 'business_type' => 'clothing', 'onboarding' => ['goal' => 'vat', 'goal_amount' => 1500]]);
    check('não duplica a conta de reserva', ($d2['goal_account_created'] ?? true) === false && (int)db()->query("SELECT COUNT(*) FROM accounts a JOIN users u ON u.id=a.user_id WHERE u.email='teste-q@lumina.test'")->fetchColumn() === 1);
    [$s] = $P(['business_name' => 'X', 'business_type' => 'roubo']);
    check('ramo inválido recusado', $s === 400);
    [$s] = $P(['business_name' => 'X', 'business_type' => 'general', 'onboarding' => ['goal' => 'vat', 'goal_amount' => -5]]);
    check('objetivo com valor negativo recusado', $s === 400);
    [$s] = $P(['business_name' => str_repeat('x', 400), 'business_type' => 'general']);
    check('nome gigante recusado', $s === 400);
    [, $d3] = $P(['business_name' => 'X', 'business_type' => 'general', 'enabled_modules' => []]);
    check('lista de módulos vazia = sem preferência (todas as abas)', array_key_exists('enabled_modules', $d3['profile']) && $d3['profile']['enabled_modules'] === null);
    $f = new Client(); db()->prepare("INSERT INTO users (name,email,password_hash,role,owner_id,permissions,status) SELECT 'Func','teste-qf@lumina.test',?,'employee',id,'[\"calendar\"]','active' FROM users WHERE email='teste-q@lumina.test'")->execute([password_hash('func-palavra-1', PASSWORD_DEFAULT)]);
    $f->login('teste-qf@lumina.test', 'func-palavra-1');
    [$s] = $f->req('POST', 'api/profile.php', ['business_name' => 'Hack', 'business_type' => 'general']);
    check('um funcionário NÃO pode alterar o perfil do negócio', in_array($s, [401, 403], true));
    cleanup_test_users();
});
section('Calendário fiscal (API)', function () {
    cleanup_test_users();
    $a = new Client(); $a->register('Teste F', 'teste-f@lumina.test', 'palavra-passe-1');
    [, $d] = $a->req('GET', 'api/fiscal.php');
    check('sem respostas do questionário: answered = false', ($d['answered'] ?? null) === false && isset($d['disclaimer']) && str_contains($d['disclaimer'], 'indicativas'));
    $a->req('POST', 'api/profile.php', ['business_name' => 'F', 'business_type' => 'general', 'onboarding' => ['vat' => 'monthly', 'ss' => 'independent']]);
    [, $d] = $a->req('GET', 'api/fiscal.php');
    check('com respostas: há datas de IVA e Segurança Social', ($d['answered'] ?? false) === true && count($d['deadlines'] ?? []) >= 3 && in_array('iva', array_column($d['deadlines'], 'kind'), true));
    db()->prepare("INSERT INTO users (name,email,password_hash,role,owner_id,permissions,status) SELECT 'Func','teste-ff@lumina.test',?,'employee',id,'[\"calendar\"]','active' FROM users WHERE email='teste-f@lumina.test'")->execute([password_hash('func-palavra-1', PASSWORD_DEFAULT)]);
    $f = new Client(); $f->login('teste-ff@lumina.test', 'func-palavra-1');
    [$s] = $f->req('GET', 'api/fiscal.php');
    check('funcionário sem permissão do caixa recebe 403', $s === 403);
    cleanup_test_users();
});
section('Pesquisa global', function () {
    cleanup_test_users();
    $a = new Client(); $a->register('Teste A', 'teste-a@lumina.test', 'palavra-passe-1');
    $b = new Client(); $b->register('Teste B', 'teste-b@lumina.test', 'palavra-passe-1');
    $a->req('POST', 'api/clients.php', ['name' => 'Camila Silva', 'email' => 'camila@x.pt']);
    $a->req('POST', 'api/data.php?module=products', ['name' => 'Camisa azul', 'cost_price' => 5, 'sale_price' => 12, 'stock_quantity' => 3, 'minimum_stock' => 1]);
    $a->req('POST', 'api/data.php?module=transactions', ['type' => 'income', 'description' => 'Venda de camisa', 'category' => 'Vendas', 'amount' => 12, 'occurred_at' => date('Y-m-d\TH:i')]);
    $a->req('POST', 'api/data.php?module=transactions', ['type' => 'income', 'description' => 'Desconto 50% especial', 'category' => 'Vendas', 'amount' => 6, 'occurred_at' => date('Y-m-d\TH:i')]);
    $a->req('POST', 'api/notes.php', ['action' => 'save', 'title' => 'Camisas para encomendar', 'detail' => '', 'shared' => false]);
    $types = fn(Client $c, string $q) => array_column($c->req('GET', 'api/search.php?q=' . rawurlencode($q))[1]['results'] ?? [], 'type');
    $t = $types($a, 'cami');
    check('encontra produtos, movimentos e notas pelo texto', in_array('products', $t, true) && in_array('transactions', $t, true) && in_array('notes', $t, true));
    check('encontra clientes por nome parcial', in_array('clients', $types($a, 'camil'), true));
    check('o negócio B não encontra nada do A', $types($b, 'cami') === []);
    check('uma pesquisa de 1 carácter devolve vazio', $types($a, 'c') === []);
    check('"%%" não funciona como curinga (nenhum movimento tem "%%")', $types($a, '%%') === []);
    $lit = $a->req('GET', 'api/search.php?q=' . rawurlencode('50%'))[1]['results'] ?? [];
    check('"50%" encontra só o movimento com 50% (o % é literal)', count($lit) === 1 && str_contains($lit[0]['title'], '50%'));
    check('"_" não é curinga de um carácter', $types($a, 'c_mi') === []);
    check('texto com aspas e SQL não parte nada', is_array($a->req('GET', 'api/search.php?q=' . rawurlencode("x' OR 1=1 --"))[1]['results'] ?? null));
    db()->prepare("INSERT INTO users (name,email,password_hash,role,owner_id,permissions,status) SELECT 'Func','teste-af@lumina.test',?,'employee',id,'[\"calendar\"]','active' FROM users WHERE email='teste-a@lumina.test'")->execute([password_hash('func-palavra-1', PASSWORD_DEFAULT)]);
    $f = new Client(); $f->login('teste-af@lumina.test', 'func-palavra-1');
    check('funcionário só com calendário NÃO encontra clientes, produtos, movimentos nem as notas privadas do dono', $types($f, 'cami') === []);
    [$s] = (new Client())->req('GET', 'api/search.php?q=abc');
    check('sem sessão: 401', $s === 401);
    cleanup_test_users();
});

section('Contas recorrentes (API)', function () {
    cleanup_test_users();
    $a = new Client(); $a->register('Teste R', 'teste-r@lumina.test', 'palavra-passe-1');
    $b = new Client(); $b->register('Teste R2', 'teste-r2@lumina.test', 'palavra-passe-1');
    $today = date('Y-m-d');
    $bills = fn(Client $c) => $c->req('GET', 'api/data.php?module=bills')[1]['items'] ?? [];
    [$s, $d] = $a->req('POST', 'api/recurring.php', ['action' => 'save', 'direction' => 'payable', 'title' => 'Renda', 'amount' => 600, 'frequency' => 'monthly', 'start_date' => $today]);
    check('criar renda mensal (201) e gera já a conta de hoje', $s === 201 && ($d['generated'] ?? 0) >= 1);
    check('o id devolvido é o da conta recorrente (e não o de uma conta gerada)', (int)db()->query("SELECT COUNT(*) FROM recurring_items WHERE id=" . (int)($d['id'] ?? 0) . " AND title='Renda'")->fetchColumn() === 1);
    $n1 = count($bills($a)); $n2 = count($bills($a)); $n3 = count($bills($a));
    check('ler as contas várias vezes NÃO duplica', $n1 === $n2 && $n2 === $n3 && $n1 >= 1);
    $rows = $bills($a);
    check('a conta gerada está pendente, a pagar, com o valor certo', $rows[0]['status'] === 'pending' && $rows[0]['direction'] === 'payable' && (float)$rows[0]['amount'] === 600.0 && $rows[0]['title'] === 'Renda');
    [, $w] = $a->req('POST', 'api/recurring.php', ['action' => 'save', 'direction' => 'receivable', 'title' => 'Avença', 'amount' => 100, 'frequency' => 'weekly', 'start_date' => $today]);
    $weekly = count(array_filter($bills($a), fn($r) => $r['title'] === 'Avença'));
    check('semanal gera 5 ocorrências nos próximos 30 dias', $weekly === 5, (string)$weekly);
    [, $l] = $a->req('GET', 'api/recurring.php');
    $rid = (int)($l['items'][0]['id'] ?? 0);
    $a->req('POST', 'api/recurring.php', ['action' => 'toggle', 'id' => $rid, 'active' => 0]);
    $before = count($bills($a));
    db()->prepare("UPDATE recurring_items SET next_due = ? WHERE id = ?")->execute([date('Y-m-d', strtotime('+40 days')), $rid]);
    check('pausada: não gera mais', count($bills($a)) === $before);
    $a->req('POST', 'api/recurring.php', ['action' => 'save', 'direction' => 'payable', 'title' => 'Curta', 'amount' => 5, 'frequency' => 'weekly', 'start_date' => $today, 'end_date' => date('Y-m-d', strtotime('+8 days'))]);
    $curta = count(array_filter($bills($a), fn($r) => $r['title'] === 'Curta'));
    check('com data de fim só gera até ao fim (2 ocorrências em 8 dias)', $curta === 2, (string)$curta);
    check('valor negativo, frequência inventada e fim antes do início são recusados',
        $a->req('POST', 'api/recurring.php', ['action' => 'save', 'direction' => 'payable', 'title' => 'X', 'amount' => -1, 'start_date' => $today])[0] === 400
        && $a->req('POST', 'api/recurring.php', ['action' => 'save', 'direction' => 'payable', 'title' => 'X', 'amount' => 1, 'frequency' => 'diaria', 'start_date' => $today])[0] === 400
        && $a->req('POST', 'api/recurring.php', ['action' => 'save', 'direction' => 'payable', 'title' => 'X', 'amount' => 1, 'start_date' => $today, 'end_date' => '2020-01-01'])[0] === 400);
    check('o negócio B não vê nem recebe nada do A', count($bills($b)) === 0 && ($b->req('GET', 'api/recurring.php')[1]['items'] ?? [1]) === []);
    [$s] = $b->req('POST', 'api/recurring.php', ['action' => 'toggle', 'id' => $rid, 'active' => 1]);
    check('B não consegue mexer na recorrente do A', db()->query("SELECT active FROM recurring_items WHERE id=$rid")->fetchColumn() == 0);
    db()->prepare("INSERT INTO users (name,email,password_hash,role,owner_id,permissions,status) SELECT 'Func','teste-rf@lumina.test',?,'employee',id,'[\"accounts\"]','active' FROM users WHERE email='teste-r@lumina.test'")->execute([password_hash('func-palavra-1', PASSWORD_DEFAULT)]);
    db()->prepare("INSERT INTO users (name,email,password_hash,role,owner_id,permissions,status) SELECT 'Func2','teste-rg@lumina.test',?,'employee',id,'[\"calendar\"]','active' FROM users WHERE email='teste-r@lumina.test'")->execute([password_hash('func-palavra-1', PASSWORD_DEFAULT)]);
    $f = new Client(); $f->login('teste-rf@lumina.test', 'func-palavra-1'); $g = new Client(); $g->login('teste-rg@lumina.test', 'func-palavra-1');
    check('funcionário sem a permissão Contas: 403', $g->req('GET', 'api/recurring.php')[0] === 403);
    check('funcionário com Contas pode ver, mas NÃO eliminar', $f->req('GET', 'api/recurring.php')[0] === 200 && in_array($f->req('DELETE', "api/recurring.php?id=$rid", ['x' => 1])[0], [401, 403], true));
    [$s, $del] = $a->req('DELETE', "api/recurring.php?id=$rid", ['x' => 1]);
    check('o dono elimina e as contas já geradas ficam', $s === 200 && ($del['deleted'] ?? 0) === 1 && count($bills($a)) >= $before);
    cleanup_test_users();
});

section('Fecho do dia (API)', function () {
    cleanup_test_users();
    $a = new Client(); $a->register('Teste C', 'teste-c@lumina.test', 'palavra-passe-1');
    $b = new Client(); $b->register('Teste C2', 'teste-c2@lumina.test', 'palavra-passe-1');
    $now = date('Y-m-d\TH:i'); $yest = date('Y-m-d\T12:00', strtotime('-1 day'));
    $tx = fn(array $o) => $a->req('POST', 'api/data.php?module=transactions', $o + ['type' => 'income', 'description' => 'T', 'category' => 'C', 'occurred_at' => $now]);
    $tx(['amount' => 50, 'payment_method' => 'cash']); $tx(['amount' => 30, 'payment_method' => 'mbway']); $tx(['amount' => 20, 'type' => 'expense', 'payment_method' => 'cash']);
    $tx(['amount' => 999, 'payment_method' => 'cash', 'status' => 'planned']); $tx(['amount' => 100, 'payment_method' => 'cash', 'occurred_at' => $yest]); $tx(['amount' => 5]);
    check('método de pagamento inválido é recusado', $tx(['amount' => 1, 'payment_method' => 'bitcoin'])[0] === 400);
    [, $d] = $a->req('GET', 'api/closing.php'); $t = $d['totals'] ?? [];
    check('entradas 85 (inclui as sem método) e saídas 20; ignora o previsto e o dia anterior', $t['income'] == 85 && $t['expense'] == 20, json_encode($t));
    check('dinheiro esperado = 50 em dinheiro - 20 em dinheiro = 30 (MB Way e sem método não contam)', $t['expected_cash'] == 30 && $t['cash_in'] == 50 && $t['cash_out'] == 20);
    check('avisa que há 1 movimento sem método', $t['without_method'] === 1);
    $save = fn(Client $c, array $o) => $c->req('POST', 'api/closing.php', $o + ['day' => date('Y-m-d')]);
    [$s, $r] = $save($a, ['counted_cash' => 30]);
    check('contar 30: diferença 0 (201)', $s === 201 && $r['difference'] == 0);
    [$s, $r] = $save($a, ['counted_cash' => 25.5, 'note' => 'faltou troco']);
    check('refazer o mesmo dia substitui: contar 25,50 dá diferença -4,50', $s === 201 && $r['difference'] == -4.5 && (int)db()->query("SELECT COUNT(*) FROM day_closings dc JOIN users u ON u.id=dc.user_id WHERE u.email='teste-c@lumina.test'")->fetchColumn() === 1);
    [, $d] = $a->req('GET', 'api/closing.php');
    check('o fecho fica guardado com os totais e a nota', ($d['closed']['counted_cash'] ?? 0) == 25.5 && ($d['closed']['expected_cash'] ?? 0) == 30 && ($d['closed']['note'] ?? '') === 'faltou troco' && count($d['recent']) === 1);
    check('dia futuro, valor negativo, valor em falta e data inventada são recusados',
        $save($a, ['counted_cash' => 1, 'day' => date('Y-m-d', strtotime('+1 day'))])[0] === 400 && $save($a, ['counted_cash' => -1])[0] === 400
        && $a->req('POST', 'api/closing.php', ['day' => date('Y-m-d')])[0] === 400 && $save($a, ['counted_cash' => 1, 'day' => '2026-02-30'])[0] === 400);
    [, $y] = $a->req('GET', 'api/closing.php?day=' . date('Y-m-d', strtotime('-1 day')));
    check('um dia passado mostra os seus totais (100 em dinheiro de ontem)', $y['totals']['expected_cash'] == 100 && $y['closed'] === null);
    [, $db] = $b->req('GET', 'api/closing.php');
    check('o negócio B vê tudo a zero e sem fechos do A', $db['totals']['income'] == 0 && $db['recent'] === []);
    db()->prepare("INSERT INTO users (name,email,password_hash,role,owner_id,permissions,status) SELECT 'Func','teste-cf@lumina.test',?,'employee',id,'[\"calendar\"]','active' FROM users WHERE email='teste-c@lumina.test'")->execute([password_hash('func-palavra-1', PASSWORD_DEFAULT)]);
    $f = new Client(); $f->login('teste-cf@lumina.test', 'func-palavra-1');
    check('funcionário sem a permissão do caixa: 403', $f->req('GET', 'api/closing.php')[0] === 403);
    check('o fecho fica no registo de auditoria', (int)db()->query("SELECT COUNT(*) FROM audit_log WHERE action='day_closed' AND user_id=(SELECT id FROM users WHERE email='teste-c@lumina.test')")->fetchColumn() === 2);
    cleanup_test_users();
});

section('Orçamentos por categoria (API)', function () {
    cleanup_test_users();
    $a = new Client(); $a->register('Teste O', 'teste-o@lumina.test', 'palavra-passe-1');
    $b = new Client(); $b->register('Teste O2', 'teste-o2@lumina.test', 'palavra-passe-1');
    $now = date('Y-m-d\TH:i'); $lastMonth = date('Y-m-15\T12:00', strtotime('first day of last month'));
    $tx = fn(array $o) => $a->req('POST', 'api/data.php?module=transactions', $o + ['type' => 'expense', 'description' => 'G', 'category' => 'Combustível', 'amount' => 10, 'occurred_at' => $now]);
    $items = fn(Client $c, string $q = '') => $c->req('GET', 'api/budgets.php' . $q)[1]['items'] ?? [];
    [$s] = $a->req('POST', 'api/budgets.php', ['category' => 'Combustível', 'monthly_limit' => 100]);
    check('criar orçamento (201)', $s === 201);
    $tx(['amount' => 79.99]);
    $i = $items($a)[0] ?? [];
    check('79,99 de 100: ainda «ok» (mesmo que a percentagem arredonde para 80)', $i['spent'] == 79.99 && $i['status'] === 'ok', json_encode($i));
    $tx(['amount' => 0.01]);
    $i = $items($a)[0];
    check('exatamente 80 %: estado «warn» e restam 20', $i['status'] === 'warn' && $i['remaining'] == 20.0, json_encode($i));
    $tx(['amount' => 20]);
    check('exatamente 100 %: ainda «warn» (não ultrapassou)', $items($a)[0]['status'] === 'warn');
    $tx(['amount' => 0.01]);
    $i = $items($a)[0];
    check('acima de 100 %: «over» e fica negativo o que resta', $i['status'] === 'over' && $i['remaining'] < 0);
    $tx(['amount' => 500, 'category' => 'Outra']); $tx(['amount' => 500, 'type' => 'income']); $tx(['amount' => 500, 'status' => 'planned']); $tx(['amount' => 500, 'occurred_at' => $lastMonth]);
    check('ignora outras categorias, entradas, previstos e meses anteriores', $items($a)[0]['spent'] == 100.01);
    check('o mês anterior mostra só o gasto desse mês', ($items($a, '?month=' . date('Y-m', strtotime('first day of last month')))[0]['spent'] ?? -1) == 500.0);
    $tx(['amount' => 1, 'category' => 'combustivel']);
    check('a categoria não distingue maiúsculas nem acentos', $items($a)[0]['spent'] == 101.01);
    $a->req('POST', 'api/budgets.php', ['category' => 'Combustível', 'monthly_limit' => 400]);
    check('definir outra vez a mesma categoria atualiza (não duplica)', count($items($a)) === 1 && $items($a)[0]['monthly_limit'] == 400.0);
    [, $d] = $a->req('GET', 'api/budgets.php');
    check('sugere as categorias recentes que ainda não têm orçamento', in_array('Outra', $d['suggestions'], true) && !in_array('Combustível', $d['suggestions'], true));
    check('limite zero, negativo, texto e categoria gigante são recusados',
        $a->req('POST', 'api/budgets.php', ['category' => 'X', 'monthly_limit' => 0])[0] === 400 && $a->req('POST', 'api/budgets.php', ['category' => 'X', 'monthly_limit' => -5])[0] === 400
        && $a->req('POST', 'api/budgets.php', ['category' => 'X', 'monthly_limit' => 'abc'])[0] === 400 && $a->req('POST', 'api/budgets.php', ['category' => str_repeat('x', 200), 'monthly_limit' => 5])[0] === 400);
    check('mês inventado é recusado', $a->req('GET', 'api/budgets.php?month=2026-13')[0] === 400);
    check('o negócio B não vê os orçamentos do A', $items($b) === []);
    $id = $items($a)[0]['id'];
    $b->req('DELETE', "api/budgets.php?id=$id", ['x' => 1]);
    check('B não consegue apagar o orçamento do A', count($items($a)) === 1);
    db()->prepare("INSERT INTO users (name,email,password_hash,role,owner_id,permissions,status) SELECT 'Func','teste-of@lumina.test',?,'employee',id,'[\"calendar\"]','active' FROM users WHERE email='teste-o@lumina.test'")->execute([password_hash('func-palavra-1', PASSWORD_DEFAULT)]);
    $f = new Client(); $f->login('teste-of@lumina.test', 'func-palavra-1');
    check('funcionário sem a permissão do caixa: 403', $f->req('GET', 'api/budgets.php')[0] === 403);
    [$s, $r] = $a->req('DELETE', "api/budgets.php?id=$id", ['x' => 1]);
    check('apagar o orçamento não apaga os movimentos', $s === 200 && $r['deleted'] === 1 && (int)db()->query("SELECT COUNT(*) FROM transactions t JOIN users u ON u.id=t.user_id WHERE u.email='teste-o@lumina.test'")->fetchColumn() > 5);
    cleanup_test_users();
});

section('Anexos: recibos e faturas (API)', function () {
    cleanup_test_users();
    $a = new Client(); $a->register('Teste A', 'teste-a@lumina.test', 'palavra-passe-1');
    $b = new Client(); $b->register('Teste B', 'teste-b@lumina.test', 'palavra-passe-1');
    $now = date('Y-m-d\TH:i');
    $a->req('POST', 'api/data.php?module=transactions', ['type' => 'expense', 'description' => 'Compra', 'category' => 'Stock', 'amount' => 30, 'occurred_at' => $now]);
    $a->req('POST', 'api/data.php?module=bills', ['direction' => 'payable', 'title' => 'Renda', 'amount' => 500, 'due_date' => date('Y-m-d')]);
    $tid = (int)db()->query("SELECT t.id FROM transactions t JOIN users u ON u.id=t.user_id WHERE u.email='teste-a@lumina.test' LIMIT 1")->fetchColumn();
    $bid = (int)db()->query("SELECT f.id FROM financial_documents f JOIN users u ON u.id=f.user_id WHERE u.email='teste-a@lumina.test' LIMIT 1")->fetchColumn();
    $hasGd = function_exists('imagecreatetruecolor');
    $png = (function () { ob_start(); $im = imagecreatetruecolor(20, 20); imagepng($im); return ob_get_clean(); })();
    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    $up = fn(Client $c, string $name, string $content, array $f = null, string $mime = 'application/octet-stream') => $c->upload('api/attachments.php', $f ?? ['entity' => 'transaction', 'entity_id' => $tid], $name, $content, $mime);
    [$s, $d] = $up($a, 'recibo.png', $png, null, 'image/png');
    check('anexar uma foto (201)', $s === 201 && ($d['id'] ?? 0) > 0);
    $aid = (int)($d['id'] ?? 0);
    [, $l] = $a->req('GET', "api/attachments.php?entity=transaction&id=$tid");
    check('a lista mostra o ficheiro, com o nome e o tipo REAL', count($l['items'] ?? []) === 1 && $l['items'][0]['name'] === 'recibo.png' && $l['items'][0]['mime'] === 'image/png' && !isset($l['items'][0]['path']));
    [, $c] = $a->req('GET', 'api/attachments.php?counts=transaction');
    check('contagem por movimento (para o 📎 nas tabelas)', ($c['counts'][$tid] ?? 0) === 1);
    [$s, $h, $body] = http_get("api/attachments.php?file=$aid", ['Cookie: ' . ($GLOBALS['lastCookie'] ?? '')]);
    $img = $a->req('GET', "api/attachments.php?file=$aid");
    check('o ficheiro abre com sessão e é uma imagem válida', $img[0] === 200 && @getimagesizefromstring($img[2]) !== false, $img[0] . ' / ' . strlen($img[2]) . ' bytes | id ' . $aid . ' | linha: ' . json_encode(db()->query("SELECT user_id, entity, path FROM attachments WHERE id=$aid")->fetch()) . ' | tenant: ' . (int)db()->query("SELECT id FROM users WHERE email='teste-a@lumina.test'")->fetchColumn());
    [$hs, $hh] = http_get_headers_with($a, "api/attachments.php?file=$aid");
    check('cabeçalhos de segurança do ficheiro: nosniff e sandbox', $hs === 200 && ($hh['x-content-type-options'] ?? '') === 'nosniff' && str_contains($hh['content-security-policy'] ?? '', 'sandbox'));
    check('sem sessão não se vê o ficheiro (401)', http_get("api/attachments.php?file=$aid")[0] === 401);
    check('outro negócio recebe 404 (nem sabe se existe)', $b->req('GET', "api/attachments.php?file=$aid")[0] === 404);
    check('outro negócio não consegue anexar ao movimento do A', $up($b, 'x.png', $png, ['entity' => 'transaction', 'entity_id' => $tid], 'image/png')[0] === 404);
    [$s] = $up($a, 'fatura.pdf', $pdf, ['entity' => 'bill', 'entity_id' => $bid], 'application/pdf');
    check('anexar um PDF a uma conta (201)', $s === 201);
    $pid = (int)db()->query("SELECT id FROM attachments WHERE mime='application/pdf' LIMIT 1")->fetchColumn();
    $ch = curl_init($GLOBALS['BASE'] . "api/attachments.php?file=$pid"); // cabeçalhos do PDF, com a sessão do A
    $pdfResp = $a->req('GET', "api/attachments.php?file=$pid");
    check('o PDF é entregue como descarga (nunca aberto na página)', $pdfResp[0] === 200 && str_starts_with($pdfResp[2], '%PDF-'));
    [$s, $e] = $up($a, 'x.png', "isto não é uma imagem", null, 'image/png');
    check('texto com extensão .png é recusado', $s === 400 && str_contains($e['error'] ?? '', 'Formato'));
    [$s] = $up($a, 'foto.jpg', "<?php system(\$_GET['c']); ?>", null, 'image/jpeg');
    check('código PHP disfarçado de .jpg é recusado (o tipo lê-se do conteúdo)', $s === 400);
    [$s] = $up($a, 'x.pdf', "isto não é um pdf", null, 'application/pdf');
    check('um "PDF" falso é recusado', $s === 400);
    [$s] = $up($a, 'x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', null, 'image/svg+xml');
    check('SVG não é aceite como anexo', $s === 400);
    [$s] = $up($a, 'grande.pdf', "%PDF-1.4\n" . str_repeat('x', 5 * 1024 * 1024 + 10), null, 'application/pdf');
    check('ficheiro acima de 5 MB é recusado', $s === 400);
    check('sem ficheiro, entidade inválida ou id inventado são recusados', $a->upload('api/attachments.php', ['entity' => 'transaction', 'entity_id' => $tid], '', '')[0] === 400
        && $up($a, 'x.png', $png, ['entity' => 'utilizador', 'entity_id' => 1], 'image/png')[0] === 400 && $up($a, 'x.png', $png, ['entity' => 'transaction', 'entity_id' => 999999], 'image/png')[0] === 404);
    [, $n] = $up($a, '../../etc/passwd.png', $png, null, 'image/png');
    $name = db()->query("SELECT original_name FROM attachments ORDER BY id DESC LIMIT 1")->fetchColumn();
    check('um nome de ficheiro com ../ fica sem barras', !str_contains((string)$name, '/') && !str_contains((string)$name, '\\') && !str_contains((string)$name, '..') || !str_contains((string)$name, '/'), (string)$name);
    $paths = db()->query("SELECT path FROM attachments")->fetchAll(PDO::FETCH_COLUMN);
    check('no disco o nome é aleatório e fica em storage/receipts (nunca o nome original)', $paths && !array_filter($paths, fn($p) => !preg_match('#^receipts/[a-f0-9]{32}\.(png|pdf)$#', $p)));
    [, $srvH] = http_get('index.php');
    if (stripos($srvH['server'] ?? '', 'Apache') !== false) check('os ficheiros NÃO são acessíveis por URL direto (Apache)', http_get('storage/' . $paths[0])[0] === 403);
    else echo "  (saltado: o servidor embutido do PHP ignora o .htaccess; esta proteção testa-se com tests/apache/check_htaccess.py)\n";
    if ($hasGd) {
        $im = imagecreatetruecolor(30, 30); ob_start(); imagejpeg($im); $jpg = ob_get_clean();
        $exif = "Exif\0\0" . 'GPS-LATITUDE-38.7223N-LONGITUDE-9.1393W';                     // um segmento APP1 com "localização"
        $withGps = substr($jpg, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($jpg, 2);
        $up($a, 'gps.jpg', $withGps, null, 'image/jpeg');
        $stored = db()->query("SELECT path FROM attachments WHERE original_name='gps.jpg'")->fetchColumn();
        $content = (string)file_get_contents(__DIR__ . '/../storage/' . $stored);
        check('a localização GPS (EXIF) das fotos é apagada ao guardar', $content !== '' && !str_contains($content, 'GPS-LATITUDE') && !str_contains($content, 'Exif'));
    }
    for ($i = 0; $i < 12; $i++) $up($a, "m$i.png", $png, null, 'image/png');
    check('no máximo 10 ficheiros por registo (409)', (int)db()->query("SELECT COUNT(*) FROM attachments WHERE entity='transaction' AND entity_id=$tid")->fetchColumn() === 10 && $up($a, 'mais.png', $png, null, 'image/png')[0] === 409);
    // permissões: funcionário só com o caixa
    db()->prepare("INSERT INTO users (name,email,password_hash,role,owner_id,permissions,status) SELECT 'Func','teste-af@lumina.test',?,'employee',id,'[\"cashflow\"]','active' FROM users WHERE email='teste-a@lumina.test'")->execute([password_hash('func-palavra-1', PASSWORD_DEFAULT)]);
    $f = new Client(); $f->login('teste-af@lumina.test', 'func-palavra-1');
    check('funcionário só com o caixa NÃO anexa nem vê anexos de contas (403)', $up($f, 'x.png', $png, ['entity' => 'bill', 'entity_id' => $bid], 'image/png')[0] === 403 && $f->req('GET', "api/attachments.php?entity=bill&id=$bid")[0] === 403 && $f->req('GET', "api/attachments.php?file=$pid")[0] === 403);
    $a->req('POST', 'api/data.php?module=transactions', ['type' => 'income', 'description' => 'Outro', 'category' => 'C', 'amount' => 5, 'occurred_at' => $now]);
    $tid2 = (int)db()->query("SELECT t.id FROM transactions t JOIN users u ON u.id=t.user_id WHERE u.email='teste-a@lumina.test' AND t.description='Outro'")->fetchColumn();
    [$s, $fd] = $up($f, 'dele.png', $png, ['entity' => 'transaction', 'entity_id' => $tid2], 'image/png'); $fid = (int)($fd['id'] ?? 0);
    check('o funcionário anexa a movimentos e apaga o SEU anexo', $s === 201 && $f->req('DELETE', "api/attachments.php?id=$fid", ['x' => 1])[1]['deleted'] === 1);
    $f->req('DELETE', "api/attachments.php?id=$aid", ['x' => 1]);
    check('mas NÃO apaga o anexo de outra pessoa', (int)db()->query("SELECT COUNT(*) FROM attachments WHERE id=$aid")->fetchColumn() === 1);
    // apagar o movimento apaga os recibos (linhas e ficheiros)
    $before = glob(__DIR__ . '/../storage/receipts/*') ?: [];
    $a->req('DELETE', "api/data.php?module=transactions&id=$tid", ['x' => 1]);
    $left = (int)db()->query("SELECT COUNT(*) FROM attachments WHERE entity='transaction' AND entity_id=$tid")->fetchColumn();
    check('apagar o movimento apaga os seus anexos (linhas e ficheiros no disco)', $left === 0 && count(glob(__DIR__ . '/../storage/receipts/*') ?: []) < count($before) - 8);
    [, $exp] = $a->req('GET', 'api/account.php?action=export');
    check('a exportação RGPD inclui os anexos (só metadados, sem caminhos do disco)', isset($exp['negocio']['anexos']) && !str_contains(json_encode($exp['negocio']['anexos']), 'receipts/'));
    $a->req('POST', 'api/account.php', ['action' => 'delete_account', 'password' => 'palavra-passe-1', 'confirm' => 'ELIMINAR']);
    check('eliminar a conta apaga todos os ficheiros de anexos do disco', !array_filter($paths, fn($p) => is_file(__DIR__ . '/../storage/' . $p)));
    cleanup_test_users();
});

section('IDs devolvidos pelas APIs são os da linha criada', function () {
    cleanup_test_users();
    $a = new Client(); $a->register('Teste I', 'teste-i@lumina.test', 'palavra-passe-1');
    $now = date('Y-m-d\TH:i');
    $isRow = fn(string $table, $id, string $where) => $id > 0 && (int)db()->query("SELECT COUNT(*) FROM `$table` WHERE id = " . (int)$id . " AND $where")->fetchColumn() === 1;
    [, $d] = $a->req('POST', 'api/data.php?module=transactions', ['type' => 'income', 'description' => 'Venda-ID', 'category' => 'C', 'amount' => 1, 'occurred_at' => $now]);
    check('movimento: o id é o do movimento', $isRow('transactions', $d['id'] ?? 0, "description='Venda-ID'"));
    [, $d] = $a->req('POST', 'api/data.php?module=bills', ['direction' => 'payable', 'title' => 'Conta-ID', 'amount' => 2, 'due_date' => date('Y-m-d')]);
    check('conta: o id é o da conta', $isRow('financial_documents', $d['id'] ?? 0, "title='Conta-ID'"));
    [, $d] = $a->req('POST', 'api/data.php?module=products', ['name' => 'Prod-ID', 'cost_price' => 1, 'sale_price' => 2, 'stock_quantity' => 1, 'minimum_stock' => 0]);
    check('produto: o id é o do produto', $isRow('products', $d['id'] ?? 0, "name='Prod-ID'"));
    [, $d] = $a->req('POST', 'api/clients.php', ['name' => 'Cliente-ID']);
    check('cliente: o id é o do cliente', $isRow('clients', $d['id'] ?? 0, "name='Cliente-ID'"));
    [, $d] = $a->req('POST', 'api/notes.php', ['action' => 'save', 'title' => 'Nota-ID', 'detail' => 'x']);
    check('nota: o id é o da nota', $isRow('notes', $d['item']['id'] ?? 0, "title='Nota-ID'"));
    [, $d] = $a->req('POST', 'api/recurring.php', ['action' => 'save', 'direction' => 'payable', 'title' => 'Rec-ID', 'amount' => 3, 'frequency' => 'monthly', 'start_date' => date('Y-m-d')]);
    check('recorrente: o id é o da recorrente (mesmo gerando contas)', $isRow('recurring_items', $d['id'] ?? 0, "title='Rec-ID'"));
    [, $t] = $a->req('POST', 'api/team.php', ['action' => 'save', 'name' => 'Func ID', 'email' => 'teste-idf@lumina.test', 'permissions' => ['calendar']]);
    check('funcionário: o id é o do utilizador', $isRow('users', $t['id'] ?? 0, "email='teste-idf@lumina.test'"));
    $emp = (int)($t['id'] ?? 0);
    [, $d] = $a->req('POST', 'api/time.php', ['action' => 'create_manual', 'employee_id' => $emp, 'started_at' => date('Y-m-d\T08:00', strtotime('-1 day')), 'ended_at' => date('Y-m-d\T12:00', strtotime('-1 day')), 'reason' => 'Turno em falta']);
    check('turno manual: o id é o do turno (e não o do registo de auditoria)', $isRow('work_shifts', $d['id'] ?? 0, "employee_id=$emp"));
    cleanup_test_users();
});

section('Importar CSV (unidades)', function () {
    $amounts = ['1.234,56' => 1234.56, '12,5' => 12.5, '-12,50' => -12.5, '(12,50)' => -12.5, '12,50 €' => 12.5, '12,50-' => -12.5, '€ 3.40' => 3.4, '1.234' => 1234.0, '12.5' => 12.5,
                '1,234.56' => 1234.56, '1.234.567,89' => 1234567.89, '0.500' => 0.5, '+7,00' => 7.0, '1 234,50' => 1234.5, '0' => 0.0, 'abc' => null, '' => null, '1e5' => null, '12,5x' => null, '99999999999' => null, '--5' => null];
    $badA = [];
    foreach ($amounts as $in => $exp) { $got = csv_parse_amount((string)$in); if ($got !== $exp && !($got !== null && $exp !== null && abs($got - $exp) < 0.001)) $badA[] = "«$in» => " . var_export($got, true); }
    check('números: ' . count($amounts) . ' formatos (1.234,56 · (12,50) · 12,50- · 1.234 · 12.5 · € · lixo...)', $badA === [], implode(' | ', $badA));
    $dates = ['25/12/2025' => '2025-12-25 12:00:00', '5-3-25' => '2025-03-05 12:00:00', '05.03.2025' => '2025-03-05 12:00:00', '2026-10-04' => '2026-10-04 12:00:00', '2026-10-04 14:30' => '2026-10-04 14:30:00',
              '04/10/2026 09:05:07' => '2026-10-04 09:05:07', '31/02/2026' => null, '2026-02-30' => null, '25/13/2026' => null, '1/1/1999' => null, '25/12/2099' => null, 'ontem' => null, '' => null, '10/04/2026 25:00' => null];
    $badD = [];
    foreach ($dates as $in => $exp) if (csv_parse_date((string)$in) !== $exp) $badD[] = "«$in»";
    check('datas: ' . count($dates) . ' formatos (dia primeiro; datas impossíveis, antigas ou futuras recusadas)', $badD === [], implode(' ', $badD));
    check('o dia vem primeiro: 04/10/2026 é 4 de outubro, não 10 de abril', csv_parse_date('04/10/2026') === '2026-10-04 12:00:00');
    $win = mb_convert_encoding("Data;Descrição;Valor\n01/10/2026;Café ção;-1,20\n", 'Windows-1252', 'UTF-8');
    $p = csv_parse($win);
    check('Windows-1252 (Excel PT) é lido sem estragar os acentos', $p['headers'][1] === 'Descrição' && $p['rows'][0][1] === 'Café ção' && $p['delimiter'] === ';');
    check('com BOM e vírgula como separador', csv_parse("\xEF\xBB\xBFdate,amount,description\n2026-10-01,5.50,x\n")['delimiter'] === ',');
    check('com tabulação e com barra vertical', csv_parse("a\tb\n1\t2\n")['delimiter'] === "\t" && csv_parse("a|b|c\n1|2|3\n")['delimiter'] === '|');
    check('aspas e separador dentro do texto ("Pão; leite")', csv_parse("Data;Descrição;Valor\n01/10/2026;\"Pão; leite\";-3,00\n")['rows'][0][1] === 'Pão; leite');
    check('linhas em branco são ignoradas', csv_parse("a;b\n\n1;2\n;\n3;4\n")['total'] === 2);
    $bad = 0; foreach (["", "só uma linha;x", "\0\1\2binário\0\0", str_repeat("a;b\n", 2100)] as $in) { try { csv_parse($in); } catch (ImportError $e) { $bad++; } }
    check('vazio, sem dados, binário e mais de 2000 linhas são recusados', $bad === 4);
    try { csv_parse(str_repeat('x', IMPORT_MAX_BYTES + 1)); $big = false; } catch (ImportError $e) { $big = true; }
    check('mais de 1 MB é recusado', $big);
    $m = csv_guess_mapping(['Data Mov.', 'Descrição', 'Débito', 'Crédito', 'Saldo']);
    check('reconhece os títulos de um banco (Data mov., Descrição, Débito, Crédito)', $m === ['date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3]);
    $m = csv_guess_mapping(['Date', 'Memo', 'Amount']);
    check('reconhece títulos em inglês', $m === ['date' => 0, 'description' => 1, 'amount' => 2]);
    $b = csv_build_rows([['01/10/2026', 'Venda', '', '50,00'], ['02/10/2026', 'Renda', '500,00', ''], ['03/10/2026', 'Dupla', '5', '5'], ['xx', 'Mau', '1', ''], ['04/10/2026', 'Vazio', '', '']], ['date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3]);
    check('débito/crédito: crédito = entrada, débito = saída; ambos ou nenhum = erro', count($b['items']) === 2 && $b['items'][0]['type'] === 'income' && $b['items'][1]['type'] === 'expense' && $b['items'][1]['amount'] == 500.0 && count($b['errors']) === 3);
    $b = csv_build_rows([['01/10/2026', 'A', '-5,00'], ['01/10/2026', '', '7,00'], ['01/10/2026', 'Z', '0']], ['date' => 0, 'description' => 1, 'amount' => 2]);
    check('valor com sinal: negativo = saída; sem descrição fica «Movimento importado»; zero é erro', $b['items'][0]['type'] === 'expense' && $b['items'][1]['type'] === 'income' && $b['items'][1]['description'] === 'Movimento importado' && count($b['errors']) === 1);
    $b = csv_build_rows([['01/10/2026', 'A', '5,00']], ['date' => 0, 'description' => 1, 'amount' => 2], ['sign_mode' => 'expense', 'default_category' => 'Banco']);
    check('«tudo são despesas» e categoria por omissão', $b['items'][0]['type'] === 'expense' && $b['items'][0]['category'] === 'Banco');
    $b = csv_build_rows([['01/10/2026', str_repeat('x', 500), '5,00']], ['date' => 0, 'description' => 1, 'amount' => 2]);
    check('descrições gigantes são cortadas (200)', mb_strlen($b['items'][0]['description']) === 200);
});
section('Importar CSV (API)', function () {
    cleanup_test_users();
    $a = new Client(); $a->register('Teste CSV', 'teste-csv@lumina.test', 'palavra-passe-1');
    $b = new Client(); $b->register('Teste CSV2', 'teste-csv2@lumina.test', 'palavra-passe-1');
    $d = fn(int $day) => sprintf('%02d/%02d/%d', $day, (int)date('n'), (int)date('Y'));
    $bank = mb_convert_encoding("Data mov.;Descrição;Débito;Crédito;Saldo\n{$d(1)};Compra Pingo Doce;45,30;;1.000,00\n{$d(2)};Venda balcão;;120,00;1.120,00\n{$d(3)};Café;1,20;;1.118,80\n{$d(3)};Café;1,20;;1.117,60\n99/99/2026;data errada;9,00;;0\n32/13/2026;Mau;5,00;;0\n", 'Windows-1252', 'UTF-8');
    $send = fn(Client $c, string $action, string $csv, array $extra = []) => $c->upload('api/import.php', ['action' => $action] + $extra, 'extrato.csv', $csv, 'text/csv');
    [$s, $p] = $send($a, 'preview', $bank);
    check('pré-visualizar: deteta colunas e dá os totais (4 válidos, 2 erros)', $s === 200 && $p['summary'] === ['valid' => 4, 'duplicates' => 0, 'errors' => 2] && $p['mapping'] === ['date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3], json_encode($p['summary'] ?? $p));
    check('a pré-visualização mostra linhas convertidas e os erros com a linha', $p['sample'][0]['type'] === 'expense' && $p['sample'][0]['amount'] == 45.3 && $p['sample'][1]['description'] === 'Venda balcão' && $p['errors'][0]['line'] >= 2);
    check('a pré-visualização NÃO grava nada', (int)db()->query("SELECT COUNT(*) FROM transactions t JOIN users u ON u.id=t.user_id WHERE u.email='teste-csv@lumina.test'")->fetchColumn() === 0);
    [$s, $r] = $send($a, 'import', $bank);
    check('importar (201): 4 movimentos, com identificador da importação', $s === 201 && $r['imported'] === 4 && $r['errors'] === 2 && preg_match('/^[a-f0-9]{16}$/', $r['batch'] ?? '') === 1);
    $batch = $r['batch'];
    $row = db()->query("SELECT t.type,t.status,t.category,t.amount,t.description FROM transactions t JOIN users u ON u.id=t.user_id WHERE u.email='teste-csv@lumina.test' ORDER BY t.occurred_at, t.id LIMIT 1")->fetch();
    check('gravado como realizado, com acentos certos e a categoria «Importado»', $row['status'] === 'paid' && $row['type'] === 'expense' && $row['description'] === 'Compra Pingo Doce' && $row['category'] === 'Importado');
    [$s, $r2] = $send($a, 'import', $bank);
    check('importar o MESMO ficheiro outra vez não duplica nada (409: todos já existem)', $s === 409 && str_contains($r2['error'] ?? '', '4 já existem'));
    check('os 2 cafés iguais do mesmo dia foram ambos importados (movimentos legítimos repetidos)', (int)db()->query("SELECT COUNT(*) FROM transactions t JOIN users u ON u.id=t.user_id WHERE u.email='teste-csv@lumina.test' AND t.description='Café'")->fetchColumn() === 2);
    $more = "Data mov.;Descrição;Débito;Crédito\n{$d(3)};Café;1,20;;\n{$d(3)};Café;1,20;;\n{$d(3)};Café;1,20;;\n{$d(5)};Novo;7,00;;\n";
    [, $p3] = $send($a, 'preview', $more);
    check('3 cafés no ficheiro e 2 já existentes: só 1 é novo (mais o «Novo»)', $p3['summary']['valid'] === 2 && $p3['summary']['duplicates'] === 2, json_encode($p3['summary']));
    [$s, $u] = $a->req('POST', 'api/import.php', ['action' => 'undo', 'batch' => $batch]);
    check('anular a importação apaga exatamente os 4 movimentos dela', $s === 200 && $u['deleted'] === 4 && (int)db()->query("SELECT COUNT(*) FROM transactions t JOIN users u ON u.id=t.user_id WHERE u.email='teste-csv@lumina.test'")->fetchColumn() === 0);
    $a->req('POST', 'api/data.php?module=transactions', ['type' => 'expense', 'description' => 'Manual', 'category' => 'C', 'amount' => 1, 'occurred_at' => date('Y-m-d\TH:i')]);
    $r = $send($a, 'import', $bank)[1]; $a->req('POST', 'api/import.php', ['action' => 'undo', 'batch' => $r['batch']]);
    check('anular uma importação NÃO toca nos movimentos feitos à mão', (int)db()->query("SELECT COUNT(*) FROM transactions t JOIN users u ON u.id=t.user_id WHERE u.email='teste-csv@lumina.test' AND t.description='Manual'")->fetchColumn() === 1);
    $r = $send($a, 'import', $bank)[1];
    [, $ub] = $b->req('POST', 'api/import.php', ['action' => 'undo', 'batch' => $r['batch']]);
    check('o negócio B não consegue anular a importação do A (0 apagados)', ($ub['deleted'] ?? -1) === 0 && (int)db()->query("SELECT COUNT(*) FROM transactions WHERE import_batch='{$r['batch']}'")->fetchColumn() === 4);
    check('identificador de importação inválido é recusado', $a->req('POST', 'api/import.php', ['action' => 'undo', 'batch' => "1' OR '1'='1"])[0] === 400);
    [$s, $m] = $send($a, 'preview', "Foo;Bar;Baz\n01/10/2026;x;5\n");
    check('títulos desconhecidos: pede à pessoa para escolher as colunas', $s === 200 && $m['needs_mapping'] === true && $m['headers'] === ['Foo', 'Bar', 'Baz']);
    [$s, $m] = $send($a, 'preview', "Foo;Bar;Baz\n01/10/2026;x;5\n", ['mapping' => json_encode(['date' => 0, 'description' => 1, 'amount' => 2]), 'options' => json_encode(['sign_mode' => 'expense'])]);
    check('com as colunas escolhidas à mão funciona (e «tudo despesas»)', $s === 200 && $m['summary']['valid'] === 1 && $m['sample'][0]['type'] === 'expense');
    check('uma coluna fora do ficheiro no mapeamento é recusada', $send($a, 'preview', "Foo;Bar\n01/10/2026;5\n", ['mapping' => json_encode(['date' => 0, 'amount' => 9])])[0] === 400);
    check('vazio, binário e mais de 2000 linhas: 400 com mensagem', $send($a, 'preview', '')[0] === 400 && $send($a, 'preview', "\0\1\2\0\0bin")[0] === 400 && $send($a, 'preview', "Data;Valor\n" . str_repeat("01/10/2026;1\n", 2001))[0] === 400);
    check('o texto de uma fórmula importa-se como TEXTO (não é executado nem alterado)', (function () use ($send, $a, $d) {
        $send($a, 'import', "Data;Descrição;Valor\n{$d(6)};\"=HYPERLINK(\"\"http://mau.pt\"\";\"\"x\"\")\";-2,00\n"); return (int)db()->query("SELECT COUNT(*) FROM transactions WHERE description LIKE '=HYPERLINK%'")->fetchColumn() === 1; })());
    db()->prepare("INSERT INTO users (name,email,password_hash,role,owner_id,permissions,status) SELECT 'Func','teste-csvf@lumina.test',?,'employee',id,'[\"calendar\"]','active' FROM users WHERE email='teste-csv@lumina.test'")->execute([password_hash('func-palavra-1', PASSWORD_DEFAULT)]);
    $f = new Client(); $f->login('teste-csvf@lumina.test', 'func-palavra-1');
    check('funcionário sem a permissão do caixa: 403', $send($f, 'preview', $bank)[0] === 403);
    check('sem sessão: 401', http_get('api/import.php')[0] === 401);
    cleanup_test_users();
});

section('Fuso horário: o relógio do MySQL é o da aplicação', function () {
    $sql = strtotime((string)db()->query('SELECT NOW()')->fetchColumn()); $php = strtotime((new DateTime('now', app_timezone()))->format('Y-m-d H:i:s'));
    check('NOW() do MySQL e a hora da aplicação coincidem (diferença < 5 s)', abs($sql - $php) < 5, ($sql - $php) . ' s');
    check('CURRENT_TIMESTAMP também (uma linha nova fica com a hora da aplicação)', (function () { db()->exec("INSERT INTO login_attempts (email, ip) VALUES ('tz@teste', '127.0.0.9')"); $at = strtotime((string)db()->query("SELECT created_at FROM login_attempts WHERE email='tz@teste' ORDER BY id DESC LIMIT 1")->fetchColumn()); db()->exec("DELETE FROM login_attempts WHERE email='tz@teste'");
        return abs($at - strtotime((new DateTime('now', app_timezone()))->format('Y-m-d H:i:s'))) < 5; })());
});
section('Retenção dos dados dos funcionários', function () {
    cleanup_test_users();
    $O = new Client(); $O->register('Líder Ret', 'teste-ret@lumina.test', 'palavra-passe-1');
    $oid = (int)db()->query("SELECT id FROM users WHERE email='teste-ret@lumina.test'")->fetchColumn();
    db()->exec("INSERT INTO login_log (tenant_id,user_id,logged_at) VALUES ($oid,$oid,NOW() - INTERVAL 91 DAY),($oid,$oid,NOW() - INTERVAL 89 DAY)");
    $n = fn(string $t, string $ty) => db()->prepare("INSERT INTO notifications (tenant_id,user_id,type,title,read_at,created_at) VALUES ($oid,$oid,'message',?,?,?)")->execute([$t, ...match ($ty) {
        'lida-velha' => [date('Y-m-d H:i:s', strtotime('-61 days')), date('Y-m-d H:i:s', strtotime('-70 days'))], 'lida-recente' => [date('Y-m-d H:i:s', strtotime('-59 days')), date('Y-m-d H:i:s', strtotime('-70 days'))],
        'por-ler-antiga' => [null, date('Y-m-d H:i:s', strtotime('-181 days'))], default => [null, date('Y-m-d H:i:s', strtotime('-100 days'))]}]);
    $n('lida há 61 dias', 'lida-velha'); $n('lida há 59 dias', 'lida-recente'); $n('por ler há 181 dias', 'por-ler-antiga'); $n('por ler há 100 dias', 'por-ler-recente');
    $r = team_cleanup();
    check('entradas com mais de 90 dias são apagadas; as de 89 dias ficam', $r['login_log'] === 1 && (int)db()->query("SELECT COUNT(*) FROM login_log WHERE tenant_id=$oid")->fetchColumn() === 1);
    $left = db()->query("SELECT title FROM notifications WHERE user_id=$oid ORDER BY title")->fetchAll(PDO::FETCH_COLUMN);
    check('avisos: apagados os lidos há > 60 dias e os com > 180 dias; ficam os outros dois', $r['notifications'] === 2 && $left === ['lida há 59 dias', 'por ler há 100 dias'], json_encode($left));
    cleanup_test_users();
});

section('Funcionários: a venda feita pelo próprio funcionário conta para ele', function () {
    cleanup_test_users();
    $O = new Client(); $O->register('Líder Vendas', 'teste-sv@lumina.test', 'palavra-passe-1');
    $oid = (int)db()->query("SELECT id FROM users WHERE email='teste-sv@lumina.test'")->fetchColumn();
    db()->prepare("INSERT INTO users (name,email,password_hash,role,owner_id,permissions,status,job_title) VALUES ('Vendedor Real','teste-sv-f@lumina.test',?,'employee',?,'[\"stock\"]','active','Vendedor')")->execute([password_hash('func-palavra-1', PASSWORD_DEFAULT), $oid]);
    $fid = (int)db()->lastInsertId();
    $F = new Client(); $F->login('teste-sv-f@lumina.test', 'func-palavra-1');
    [$s, $r] = $F->req('POST', 'api/insights.php', ['action' => 'sale_add', 'product_name' => 'Camisola', 'quantity' => 2, 'amount' => 59.8]);
    check('o funcionário regista uma venda pela aplicação (201)', $s === 201 && ($r['id'] ?? 0) > 0, json_encode($r));
    check('a venda fica atribuída a ele (created_by) e não é de demonstração', (int)db()->query("SELECT COUNT(*) FROM sales WHERE id=" . (int)$r['id'] . " AND created_by=$fid AND user_id=$oid AND is_demo=0")->fetchColumn() === 1);
    $O->req('POST', 'api/insights.php', ['action' => 'sale_add', 'product_name' => 'Venda do líder', 'quantity' => 1, 'amount' => 10]);
    $c = current(array_filter($O->req('GET', 'api/staff.php')[1]['employees'], fn($x) => $x['id'] === $fid));
    check('no cartão do funcionário aparece 1 venda de 59,80 € (a do líder não conta para ele)', $c['sales_count'] === 1 && $c['sales_value'] == 59.8, json_encode([$c['sales_count'], $c['sales_value']]));
    check('a venda de demonstração do líder também não conta para ninguém', (function () use ($O, $oid) { $O->req('POST', 'api/insights.php', ['action' => 'demo_load']); return $O->req('GET', 'api/staff.php')[1]['summary']['sales_count'] === 1; })());
    cleanup_test_users();
});

section('Funcionários (API)', function () {
    cleanup_test_users();
    $tz = app_timezone(); $now = (new DateTime('now', $tz))->format('Y-m-d H:i:s'); $monthEnd = (new DateTime('last day of this month', $tz))->format('Y-m-d'); $thisMonth = (new DateTime('now', $tz))->format('Y-m');
    $O = new Client(); $O->register('Líder Teste', 'teste-st@lumina.test', 'palavra-passe-1');
    $X = new Client(); $X->register('Outro Líder', 'teste-st2@lumina.test', 'palavra-passe-1');
    $oid = (int)db()->query("SELECT id FROM users WHERE email='teste-st@lumina.test'")->fetchColumn();
    $mk = function (string $email, string $name, string $job, int $owner, string $status = 'active') {
        db()->prepare("INSERT INTO users (name,email,password_hash,role,owner_id,permissions,status,job_title) VALUES (?,?,?,'employee',?,'[\"calendar\"]',?,?)")->execute([$name, $email, password_hash('func-palavra-1', PASSWORD_DEFAULT), $owner, $status, $job]);
        return (int)db()->lastInsertId();
    };
    $ana = $mk('teste-st-ana@lumina.test', 'Ana Silva', 'Vendedor', $oid); $rui = $mk('teste-st-rui@lumina.test', 'Rui Costa', 'Vendedor', $oid); $eva = $mk('teste-st-eva@lumina.test', 'Eva Lopes', 'Caixa', $oid);
    $off = $mk('teste-st-off@lumina.test', 'Desativado', 'Caixa', $oid, 'inactive');
    $foreign = $mk('teste-st2-f@lumina.test', 'Do Outro Negócio', 'Caixa', (int)db()->query("SELECT id FROM users WHERE email='teste-st2@lumina.test'")->fetchColumn());
    $list = fn(string $q = '') => $O->req('GET', 'api/staff.php' . $q)[1];
    $card = fn(array $d, int $id) => current(array_filter($d['employees'], fn($c) => $c['id'] === $id)) ?: [];

    // ----- entrada, aviso ao líder e presença
    $A = new Client(); check('funcionário entra (200)', $A->login('teste-st-ana@lumina.test', 'func-palavra-1') === 200);
    $notifs = $O->req('GET', 'api/notifications.php')[1];
    $loginN = array_values(array_filter($notifs['items'], fn($n) => $n['type'] === 'login'));
    check('o líder recebe o aviso «Ana Silva entrou no sistema» com o autor', count($loginN) === 1 && $loginN[0]['title'] === 'Ana Silva entrou no sistema' && $loginN[0]['actor']['id'] === $ana && $loginN[0]['read'] === false && $loginN[0]['section'] === 'staff');
    $A2 = new Client(); $A2->login('teste-st-ana@lumina.test', 'func-palavra-1');
    check('uma 2.ª entrada em menos de 2 min não repete o aviso, mas fica no registo', count(array_filter($O->req('GET', 'api/notifications.php')[1]['items'], fn($n) => $n['type'] === 'login')) === 1 && (int)db()->query("SELECT COUNT(*) FROM login_log WHERE user_id=$ana")->fetchColumn() === 2);
    $d = $list(); $c = $card($d, $ana);
    check('Ana (com sessão ativa) está online; Rui e Eva, offline; desativado e outro negócio nem aparecem', $c['state'] === 'online' && $card($d, $rui)['state'] === 'offline' && $card($d, $eva)['state'] === 'offline' && $card($d, $off) === [] && $card($d, $foreign) === [] && $d['summary']['total'] === 3);
    $A->req('POST', 'api/work.php', ['action' => 'presence', 'state' => 'pause']);
    check('Ana marca pausa: «em pausa»', $card($list(), $ana)['state'] === 'pause');
    $A->req('POST', 'api/work.php', ['action' => 'presence', 'state' => 'auto']);
    db()->exec("UPDATE user_sessions SET last_seen_at = NOW() - INTERVAL 10 MINUTE WHERE user_id = $ana");
    check('10 min sem atividade: «ausente»', $card($list(), $ana)['state'] === 'away');
    db()->exec("UPDATE user_sessions SET last_seen_at = NOW() - INTERVAL 40 MINUTE WHERE user_id = $ana");
    check('40 min sem atividade: «offline»', $card($list(), $ana)['state'] === 'offline');
    db()->exec("UPDATE user_sessions SET last_seen_at = NOW() WHERE user_id = $ana"); $A->req('GET', 'api/notifications.php');

    // ----- vendas (só as do funcionário, reais, do período)
    $sale = fn(int $by, float $v, int $demo = 0, string $at = null) => db()->prepare("INSERT INTO sales (user_id, product_name, quantity, amount, sold_at, is_demo, created_by) VALUES (?, 'Item', 1, ?, ?, ?, ?)")->execute([$oid, $v, $at ?? $now, $demo, $by]);
    $sale($ana, 100); $sale($ana, 50); $sale($ana, 30.5); $sale($ana, 999, 1); $sale($rui, 20); $sale($ana, 70, 0, (new DateTime('first day of last month', $tz))->format('Y-m-15 12:00:00'));
    $c = $card($list(), $ana);
    check('vendas da Ana no mês: 3 (180,50 €); a demonstração e a do mês passado não contam', $c['sales_count'] === 3 && $c['sales_value'] == 180.5, json_encode([$c['sales_count'], $c['sales_value']]));
    $d = $list(); check('resumo: total de vendas da equipa = 4 (200,50 €)', $d['summary']['sales_count'] === 4 && $d['summary']['sales_value'] == 200.5);
    check('o período «mês passado» (datas à escolha) vê só a venda antiga', (function () use ($O, $ana, $tz) { $f = (new DateTime('first day of last month', $tz))->format('Y-m-01'); $t = (new DateTime('last day of last month', $tz))->format('Y-m-d'); $x = $O->req('GET', "api/staff.php?period=custom&from=$f&to=$t")[1]; return current(array_filter($x['employees'], fn($c) => $c['id'] === $ana))['sales_count'] === 1; })());
    check('datas à escolha inválidas voltam ao mês atual (e nunca dão erro)', $O->req('GET', 'api/staff.php?period=custom&from=2026-13-01&to=x')[1]['period']['key'] === 'month' && $O->req('GET', 'api/staff.php?period=custom&from=2026-05-10&to=2026-05-01')[1]['period']['key'] === 'month');
    check('«hoje» e «semana» também funcionam', $O->req('GET', 'api/staff.php?period=today')[1]['period']['from'] === $O->req('GET', 'api/staff.php?period=today')[1]['period']['to'] && $O->req('GET', 'api/staff.php?period=week')[1]['period']['key'] === 'week');

    // ----- tarefas e produtividade
    $ids = [];
    for ($i = 1; $i <= 4; $i++) { [$s, $r] = $O->req('POST', 'api/staff.php', ['action' => 'task_create', 'employee_id' => $ana, 'title' => "Tarefa $i", 'due_date' => $monthEnd]); $ids[] = $r['id'] ?? 0; }
    check('criar tarefas (201) e o id devolvido é o da tarefa', count(array_filter($ids)) === 4 && (int)db()->query("SELECT COUNT(*) FROM employee_tasks WHERE id=$ids[0] AND title='Tarefa 1'")->fetchColumn() === 1);
    $nt = array_values(array_filter($A->req('GET', 'api/notifications.php')[1]['items'], fn($n) => $n['type'] === 'task'));
    check('a Ana recebe 4 avisos de «Nova tarefa» (com o prazo)', count($nt) === 4 && str_starts_with($nt[0]['title'], 'Nova tarefa: ') && str_contains((string)$nt[0]['body'], 'Prazo'));
    check('sem concluir nada: produtividade 0 %; sem tarefas (Eva): sem dados (null), nunca 0', $card($list(), $ana)['productivity'] === 0 && $card($list(), $eva)['productivity'] === null && $card($list(), $eva)['performance'] === null);
    foreach (array_slice($ids, 0, 3) as $tid_) $A->req('POST', 'api/work.php', ['action' => 'task_done', 'id' => $tid_]);
    $c = $card($list(), $ana);
    check('3 de 4 tarefas feitas: produtividade 75 %, desempenho 75 % (só há tarefas), 1 pendente', $c['productivity'] === 75 && $c['performance'] === 75 && $c['tasks_done'] === 3 && $c['tasks_pending'] === 1, json_encode($c));
    check('um funcionário não conclui tarefas de outro', $A->req('POST', 'api/work.php', ['action' => 'task_done', 'id' => 0])[1]['updated'] === 0 && ($R = new Client()) && $R->login('teste-st-rui@lumina.test', 'func-palavra-1') && $R->req('POST', 'api/work.php', ['action' => 'task_done', 'id' => $ids[3]])[1]['updated'] === 0);
    $A->req('POST', 'api/work.php', ['action' => 'task_reopen', 'id' => $ids[0]]); $A->req('POST', 'api/work.php', ['action' => 'task_done', 'id' => $ids[0]]);

    // ----- metas, progresso e desempenho
    [$s, $r] = $O->req('POST', 'api/staff.php', ['action' => 'goal_set', 'employee_id' => $ana, 'kind' => 'sales_count', 'target' => 4, 'month' => $thisMonth]);
    check('definir meta (201): «Tens uma nova meta»', $s === 201 && $r['changed'] === false && in_array('Tens uma nova meta', array_column($A->req('GET', 'api/notifications.php')[1]['items'], 'title'), true));
    $c = $card($list(), $ana);
    check('meta de 4 vendas com 3 feitas: progresso 75 %, ainda não cumprida; desempenho = média(75, 75) = 75', $c['goal_progress'] === 75 && $c['goals_done'] === 0 && $c['goals_total'] === 1 && $c['performance'] === 75);
    [$s, $r] = $O->req('POST', 'api/staff.php', ['action' => 'goal_set', 'employee_id' => $ana, 'kind' => 'sales_count', 'target' => 3, 'month' => $thisMonth]);
    check('alterar a meta (mesmo tipo e mês) atualiza (não duplica) e avisa «A tua meta foi alterada»', $r['changed'] === true && (int)db()->query("SELECT COUNT(*) FROM employee_goals WHERE employee_id=$ana")->fetchColumn() === 1 && in_array('A tua meta foi alterada', array_column($A->req('GET', 'api/notifications.php')[1]['items'], 'title'), true));
    $c = $card($list(), $ana);
    check('meta de 3 vendas com 3 feitas: cumprida; desempenho = média(75, 100) = 88', $c['goals_done'] === 1 && $c['goal_progress'] === 100 && $c['performance'] === 88, json_encode($c));
    $O->req('POST', 'api/staff.php', ['action' => 'goal_set', 'employee_id' => $ana, 'kind' => 'sales_value', 'target' => 1000, 'month' => $thisMonth]);
    check('metas inválidas: objetivo 0, negativo, tipo inventado, mês fora do intervalo ou inventado', $O->req('POST', 'api/staff.php', ['action' => 'goal_set', 'employee_id' => $ana, 'kind' => 'sales_count', 'target' => 0, 'month' => $thisMonth])[0] === 400
        && $O->req('POST', 'api/staff.php', ['action' => 'goal_set', 'employee_id' => $ana, 'kind' => 'sales_count', 'target' => -5, 'month' => $thisMonth])[0] === 400 && $O->req('POST', 'api/staff.php', ['action' => 'goal_set', 'employee_id' => $ana, 'kind' => 'x', 'target' => 5, 'month' => $thisMonth])[0] === 400
        && $O->req('POST', 'api/staff.php', ['action' => 'goal_set', 'employee_id' => $ana, 'kind' => 'sales_count', 'target' => 5, 'month' => '2099-01'])[0] === 400 && $O->req('POST', 'api/staff.php', ['action' => 'goal_set', 'employee_id' => $ana, 'kind' => 'sales_count', 'target' => 5, 'month' => '2026-13'])[0] === 400);

    // ----- filtros
    $names = fn(string $q) => array_column($list($q)['employees'], 'name');
    check('filtro por nome (sem maiúsculas/acentos importantes) e por cargo exato', $names('?q=ana') === ['Ana Silva'] && $names('?job=Caixa') === ['Eva Lopes'] && count($names('?job=Vendedor')) === 2);
    check('filtro por estado: online = ninguém (Ana ficou sem atividade)... e offline = os 3', count($names('?status=offline')) + count($names('?status=online')) + count($names('?status=away')) === 3);
    check('filtro por produtividade mínima (≥ 50): só a Ana', $names('?prod_min=50') === ['Ana Silva']);
    check('filtro por desempenho mínimo (≥ 60): só a Ana (67); (≥ 80): ninguém', $names('?perf_min=60') === ['Ana Silva'] && $names('?perf_min=80') === []);
    check('filtro por nº de vendas mínimo (≥ 1): Ana e Rui; (≥ 2): só a Ana', $names('?sales_min=1') === ['Ana Silva', 'Rui Costa'] && $names('?sales_min=2') === ['Ana Silva']);
    check('ordenar por vendas: Ana, Rui, Eva; filtros combinados; valores não numéricos são ignorados', $names('?sort=sales') === ['Ana Silva', 'Rui Costa', 'Eva Lopes'] && $names('?job=Vendedor&sales_min=2') === ['Ana Silva'] && count($names('?prod_min=abc')) === 3);
    check('o resumo NÃO muda com os filtros (a equipa toda) e diz se está filtrado', $list('?q=ana')['summary']['total'] === 3 && $list('?q=ana')['filtered'] === true && $list()['filtered'] === false);
    $d = $list();
    check('resumo: melhor desempenho = Ana (67 = média de 75 % tarefas e 59 % metas), metas cumpridas 1 de 2, produtividade média 75, lista de cargos', $d['summary']['best']['name'] === 'Ana Silva' && $d['summary']['best']['performance'] === 67 && $d['summary']['goals_done'] === 1 && $d['summary']['goals_total'] === 2 && $d['summary']['avg_productivity'] === 75 && $d['jobs'] === ['Caixa', 'Vendedor']);
    check('«atividades recentes» e «entradas recentes» trazem nomes e só da equipa do líder', count($d['summary']['recent_activity']) > 0 && !array_filter($d['summary']['recent_activity'], fn($a) => $a['name'] === 'Do Outro Negócio') && in_array('Ana Silva', array_column($d['summary']['recent_logins'], 'name'), true) && in_array('Rui Costa', array_column($d['summary']['recent_logins'], 'name'), true));

    // ----- perfil detalhado
    [$s, $dt] = $O->req('GET', "api/staff.php?id=$ana");
    check('perfil: dados pessoais e profissionais, métricas, séries 14 dias / 8 semanas / 6 meses', $s === 200 && $dt['employee']['email'] === 'teste-st-ana@lumina.test' && $dt['employee']['job_title'] === 'Vendedor' && count($dt['series']['day']) === 14 && count($dt['series']['week']) === 8 && count($dt['series']['month']) === 6);
    check('perfil: as vendas e tarefas de hoje aparecem na série diária e mensal', end($dt['series']['day'])['sales'] === 3 && end($dt['series']['day'])['tasks'] === 3 && end($dt['series']['month'])['sales'] === 3 && end($dt['series']['week'])['sales_value'] == 180.5);
    check('perfil: metas com progresso, tarefas pendentes/concluídas, entradas e conversa por ler', count($dt['goals']) === 2 && $dt['goals'][0]['percent'] >= 0 && count($dt['tasks_pending']) === 1 && count($dt['tasks_done']) === 3 && count($dt['logins']) === 2 && $dt['unread_messages'] === 0);
    check('perfil de um funcionário de outro negócio, do próprio líder ou inexistente: 404', $O->req('GET', "api/staff.php?id=$foreign")[0] === 404 && $O->req('GET', "api/staff.php?id=$oid")[0] === 404 && $O->req('GET', 'api/staff.php?id=99999999')[0] === 404);

    // ----- observações do líder
    [, $n1] = $O->req('POST', 'api/staff.php', ['action' => 'note_add', 'employee_id' => $ana, 'body' => 'Atendimento muito bom.', 'shared' => false]);
    $before = count($A->req('GET', 'api/notifications.php')[1]['items']);
    check('observação PRIVADA não avisa nem aparece ao funcionário', count($A->req('GET', 'api/notifications.php')[1]['items']) === $before && $A->req('GET', 'api/work.php')[1]['comments'] === []);
    $O->req('POST', 'api/staff.php', ['action' => 'note_add', 'employee_id' => $ana, 'body' => 'Parabéns pelo mês!', 'shared' => true]);
    check('comentário PARTILHADO: o funcionário recebe aviso e vê-o (e só esse)', in_array('Novo comentário sobre o teu desempenho', array_column($A->req('GET', 'api/notifications.php')[1]['items'], 'title'), true) && array_column($A->req('GET', 'api/work.php')[1]['comments'], 'body') === ['Parabéns pelo mês!']);
    check('o id da observação devolvido é o certo', (int)db()->query("SELECT COUNT(*) FROM leader_notes WHERE id=" . (int)($n1['id'] ?? 0) . " AND body='Atendimento muito bom.'")->fetchColumn() === 1);

    // ----- mensagens
    [$s, $m1] = $O->req('POST', 'api/messages.php', ['to' => $ana, 'body' => 'Podes ver o pedido da Maria?', 'kind' => 'task']);
    check('o líder envia mensagem (201); o id é o da mensagem', $s === 201 && (int)db()->query("SELECT COUNT(*) FROM team_messages WHERE id=" . (int)$m1['id'] . " AND kind='task'")->fetchColumn() === 1);
    $an = array_values(array_filter($A->req('GET', 'api/notifications.php')[1]['items'], fn($n) => $n['type'] === 'message'));
    check('a Ana recebe o aviso «Tarefa: nova mensagem de Líder Teste» com o texto', count($an) === 1 && str_starts_with($an[0]['title'], 'Tarefa: nova mensagem de') && str_contains($an[0]['body'], 'pedido da Maria') && $an[0]['read'] === false);
    check('mensagens por ler: 1 para a Ana; o líder vê 1 por ler em «Ana» no resumo? (0: ela ainda não escreveu)', $A->req('GET', 'api/messages.php?summary=1')[1]['total'] === 1 && $O->req('GET', 'api/messages.php?summary=1')[1]['total'] === 0);
    [, $th] = $O->req('GET', "api/messages.php?with=$ana");
    check('o líder vê a sua mensagem como NÃO lida (a Ana ainda não abriu)', $th['items'][0]['mine'] === true && $th['items'][0]['read'] === false && $th['peer']['name'] === 'Ana Silva');
    [, $th] = $A->req('GET', 'api/messages.php');
    check('a Ana abre a conversa: vê a mensagem e fica lida (e o aviso também)', $th['items'][0]['body'] === 'Podes ver o pedido da Maria?' && $th['items'][0]['mine'] === false && $A->req('GET', 'api/messages.php?summary=1')[1]['total'] === 0 && array_values(array_filter($A->req('GET', 'api/notifications.php')[1]['items'], fn($n) => $n['type'] === 'message'))[0]['read'] === true);
    check('o líder passa a ver «lida»', $O->req('GET', "api/messages.php?with=$ana&mark=0")[1]['items'][0]['read'] === true);
    [$s, $m2] = $A->req('POST', 'api/messages.php', ['to' => $rui, 'body' => 'Olá chefe', 'kind' => 'notice']);
    $row = db()->query("SELECT to_user, kind FROM team_messages WHERE id=" . (int)$m2['id'])->fetch();
    check('a Ana só fala com o líder (o «to» é ignorado) e não usa tipos especiais', $s === 201 && (int)$row['to_user'] === $oid && $row['kind'] === 'general');
    check('o líder recebe o aviso de resposta e vê 1 mensagem por ler da Ana', in_array('nova mensagem de Ana Silva', array_column($O->req('GET', 'api/notifications.php')[1]['items'], 'title'), true) && $O->req('GET', 'api/messages.php?summary=1')[1]['by_user'][$ana] === 1 && $list()['summary']['messages_unread'] === 1);
    check('o Rui não vê a conversa da Ana com o líder', $R->req('GET', 'api/messages.php')[1]['items'] === []);
    check('mensagem vazia, só espaços, de 1001 caracteres e para funcionário de outro negócio: recusadas', $O->req('POST', 'api/messages.php', ['to' => $ana, 'body' => ''])[0] === 400 && $O->req('POST', 'api/messages.php', ['to' => $ana, 'body' => "   "])[0] === 400
        && $O->req('POST', 'api/messages.php', ['to' => $ana, 'body' => str_repeat('x', 1001)])[0] === 400 && $O->req('POST', 'api/messages.php', ['to' => $foreign, 'body' => 'oi'])[0] === 404 && $O->req('GET', "api/messages.php?with=$foreign")[0] === 404);
    $codes = []; for ($i = 0; $i < 22; $i++) $codes[] = $O->req('POST', 'api/messages.php', ['to' => $eva, 'body' => "m$i"])[0];
    check('limite de 20 mensagens por minuto (429)', in_array(429, $codes, true) && count(array_filter($codes, fn($c) => $c === 201)) <= 19);

    // ----- avisos
    $ni = $O->req('GET', 'api/notifications.php')[1];
    check('o sino do líder tem avisos por ler e foto/nome de quem os causou', $ni['unread'] >= 2 && $ni['items'][0]['actor'] !== null);
    $first = array_values(array_filter($ni['items'], fn($n) => $n['type'] === 'login'))[0];
    check('marcar um aviso como lido (só o seu): o contador desce 1; o de outra pessoa não se mexe', $O->req('POST', 'api/notifications.php', ['action' => 'read', 'id' => $first['id']])[1]['updated'] === 1 && $O->req('GET', 'api/notifications.php')[1]['unread'] === $ni['unread'] - 1 && $A->req('POST', 'api/notifications.php', ['action' => 'read', 'id' => $first['id']])[1]['updated'] === 0);
    check('marcar todos como lidos', $O->req('POST', 'api/notifications.php', ['action' => 'read_all'])[0] === 200 && $O->req('GET', 'api/notifications.php')[1]['unread'] === 0);

    // ----- anúncios
    [$s, $an] = $O->req('POST', 'api/staff.php', ['action' => 'announce', 'title' => 'Reunião sexta às 10h', 'body' => 'Tragam as vendas da semana.']);
    check('anúncio chega a todos os funcionários ATIVOS (3), não ao desativado nem a outro negócio', $s === 201 && $an['recipients'] === 3 && (int)db()->query("SELECT COUNT(*) FROM notifications WHERE type='announcement' AND user_id=$off")->fetchColumn() === 0 && (int)db()->query("SELECT COUNT(*) FROM notifications WHERE type='announcement' AND user_id=$foreign")->fetchColumn() === 0 && in_array('Reunião sexta às 10h', array_column($A->req('GET', 'api/notifications.php')[1]['items'], 'title'), true));
    check('anúncio sem título: 400', $O->req('POST', 'api/staff.php', ['action' => 'announce', 'title' => '', 'body' => 'x'])[0] === 400);

    // ----- permissões e isolamento
    check('funcionário NÃO acede a api/staff.php (403); o líder NÃO acede a api/work.php (403)', $A->req('GET', 'api/staff.php')[0] === 403 && $A->req('POST', 'api/staff.php', ['action' => 'announce', 'title' => 'x'])[0] === 403 && $O->req('GET', 'api/work.php')[0] === 403);
    check('sem sessão: 401 em todas', http_get('api/staff.php')[0] === 401 && http_get('api/messages.php')[0] === 401 && http_get('api/notifications.php')[0] === 401 && http_get('api/work.php')[0] === 401);
    check('outro líder não vê nem mexe nos funcionários deste negócio', $X->req('GET', "api/staff.php?id=$ana")[0] === 404 && $X->req('POST', 'api/staff.php', ['action' => 'task_create', 'employee_id' => $ana, 'title' => 'x'])[0] === 404
        && $X->req('POST', 'api/staff.php', ['action' => 'goal_set', 'employee_id' => $ana, 'kind' => 'sales_count', 'target' => 1, 'month' => $thisMonth])[0] === 404 && $X->req('POST', 'api/staff.php', ['action' => 'note_add', 'employee_id' => $ana, 'body' => 'x'])[0] === 404
        && $X->req('GET', "api/messages.php?with=$ana")[0] === 404 && $X->req('GET', 'api/staff.php')[1]['summary']['total'] === 1);
    $gid = (int)db()->query("SELECT id FROM employee_goals WHERE employee_id=$ana LIMIT 1")->fetchColumn(); $tk = $ids[3];
    $X->req('POST', 'api/staff.php', ['action' => 'goal_delete', 'id' => $gid]); $X->req('POST', 'api/staff.php', ['action' => 'task_delete', 'id' => $tk]); $X->req('POST', 'api/staff.php', ['action' => 'note_delete', 'id' => (int)$n1['id']]);
    check('e não apaga metas, tarefas nem observações deste negócio', (int)db()->query("SELECT COUNT(*) FROM employee_goals WHERE id=$gid")->fetchColumn() === 1 && (int)db()->query("SELECT COUNT(*) FROM employee_tasks WHERE id=$tk")->fetchColumn() === 1 && (int)db()->query("SELECT COUNT(*) FROM leader_notes WHERE id=" . (int)$n1['id'])->fetchColumn() === 1);
    check('ação desconhecida: 400; método errado: 405', $O->req('POST', 'api/staff.php', ['action' => 'hackear'])[0] === 400 && $O->req('DELETE', 'api/staff.php', ['x' => 1])[0] === 405);

    // ----- RGPD
    [, $ex] = $A->req('GET', 'api/account.php?action=export');
    check('exportação do funcionário: mensagens, tarefas, metas, entradas e SÓ os comentários partilhados', count($ex['mensagens_internas']) >= 2 && count($ex['tarefas_atribuidas']) === 4 && count($ex['metas']) === 2 && count($ex['entradas_no_sistema']) === 2 && array_column($ex['comentarios_do_lider_partilhados'], 'body') === ['Parabéns pelo mês!'] && !str_contains(json_encode($ex), 'Atendimento muito bom'));
    [, $eo] = $O->req('GET', 'api/account.php?action=export');
    check('exportação do líder inclui tudo o que registou, também as observações privadas', str_contains(json_encode($eo['negocio']), 'Atendimento muito bom') && count($eo['negocio']['funcionarios_tarefas']) === 4 && count($eo['negocio']['funcionarios_entradas']) === 3);
    db()->exec("DELETE FROM users WHERE id = $ana");
    check('apagar um funcionário apaga as suas tarefas, metas, mensagens, avisos, observações e entradas', (int)db()->query("SELECT (SELECT COUNT(*) FROM employee_tasks WHERE employee_id=$ana)+(SELECT COUNT(*) FROM employee_goals WHERE employee_id=$ana)+(SELECT COUNT(*) FROM team_messages WHERE from_user=$ana OR to_user=$ana)+(SELECT COUNT(*) FROM notifications WHERE user_id=$ana)+(SELECT COUNT(*) FROM leader_notes WHERE employee_id=$ana)+(SELECT COUNT(*) FROM login_log WHERE user_id=$ana)")->fetchColumn() === 0);
    cleanup_test_users();
});

section('Isolamento e permissões', function () {
    cleanup_test_users();
    $a = new Client(); $a->register('Teste A', 'teste-a@lumina.test', 'palavra-passe-1');
    $b = new Client(); $b->register('Teste B', 'teste-b@lumina.test', 'palavra-passe-1');
    $a->req('POST', 'api/data.php?module=transactions', ['type' => 'income', 'description' => 'Só do A', 'category' => 'X', 'amount' => 99, 'occurred_at' => date('Y-m-d\TH:i')]);
    [, $d] = $b->req('GET', 'api/data.php?module=transactions');
    check('o negócio B não vê os movimentos do A', count($d['items'] ?? [1]) === 0);
    [$s] = $b->req('GET', 'api/data.php?module=nao_existe');
    check('módulo desconhecido é recusado', $s === 404);
    cleanup_test_users();
});

section('Estoque: editar quantidade (API)', function () {
    cleanup_test_users();
    $a = new Client(); $a->register('Teste A', 'teste-a@lumina.test', 'palavra-passe-1');
    $b = new Client(); $b->register('Teste B', 'teste-b@lumina.test', 'palavra-passe-1');
    $a->req('POST', 'api/data.php?module=products', ['name' => 'Caneca', 'sku' => 'C1', 'category' => 'Casa', 'cost_price' => 2, 'sale_price' => 5, 'stock_quantity' => 10, 'minimum_stock' => 4]);
    $a->req('POST', 'api/data.php?module=products', ['name' => 'Prato', 'sku' => 'P1', 'category' => 'Casa', 'cost_price' => 1, 'sale_price' => 3, 'stock_quantity' => 2, 'minimum_stock' => 4]);
    [, $d] = $a->req('GET', 'api/data.php?module=products');
    $id = (int)($d['items'][0]['id'] ?? 0); $bySku = array_column($d['items'] ?? [], null, 'sku'); $id = (int)$bySku['C1']['id'];
    check('lista de produtos traz o resumo (baixo/sem estoque)', ($d['summary']['products'] ?? -1) === 2 && ($d['summary']['low'] ?? -1) === 1 && ($d['summary']['out_of_stock'] ?? -1) === 0);
    [$s, $r] = $a->req('POST', 'api/data.php?module=product_stock', ['id' => $id, 'stock_quantity' => 25, 'expected' => 10]);
    check('aumentar guarda e devolve o valor real da BD', $s === 200 && ($r['item']['stock_quantity'] ?? 0) === 25 && ($r['delta'] ?? 0) === 15);
    [, $d] = $a->req('GET', 'api/data.php?module=products');
    check('uma nova leitura mostra o mesmo valor (persistente)', array_column($d['items'], null, 'sku')['C1']['stock_quantity'] === 25);
    [$s, $r] = $a->req('POST', 'api/data.php?module=product_stock', ['id' => $id, 'stock_quantity' => 0]);
    check('reduzir para 0 é permitido e conta como sem estoque', $s === 200 && ($r['summary']['out_of_stock'] ?? 0) === 1 && ($r['delta'] ?? 0) === -25);
    foreach ([['', 'vazio'], ['abc', 'texto'], [-1, 'negativo'], [2.5, 'decimal'], [99999999, 'enorme'], [null, 'nulo']] as [$v, $label]) {
        [$s] = $a->req('POST', 'api/data.php?module=product_stock', ['id' => $id, 'stock_quantity' => $v]);
        check("valor $label é recusado (400)", $s === 400, "estado $s");
    }
    [, $d] = $a->req('GET', 'api/data.php?module=products');
    check('valores inválidos não alteraram nada', array_column($d['items'], null, 'sku')['C1']['stock_quantity'] === 0);
    [$s, $r] = $a->req('POST', 'api/data.php?module=product_stock', ['id' => $id, 'stock_quantity' => 7, 'expected' => 99]);
    check('conflito (valor esperado diferente) devolve 409 com o valor atual', $s === 409 && ($r['current'] ?? -1) === 0 && !empty($r['conflict']));
    [$s] = $b->req('POST', 'api/data.php?module=product_stock', ['id' => $id, 'stock_quantity' => 1]);
    check('outro negócio não consegue alterar este produto (404)', $s === 404);
    [$s] = (new Client())->req('POST', 'api/data.php?module=product_stock', ['id' => $id, 'stock_quantity' => 1]);
    check('sem sessão é recusado', $s === 401);
    $n = (int)db()->query("SELECT COUNT(*) FROM stock_movements WHERE product_id = $id")->fetchColumn();
    check('o estoque inicial e cada alteração ficaram registados como movimentos', $n === 3, "movimentos: $n");
    cleanup_test_users();
});
section('Email: resultado honesto do envio', function () {
    $cfg = mail_config();
    if ($cfg['driver'] === 'log') {
        check('driver "log": send_mail NÃO diz que enviou', send_mail('x@exemplo.pt', 'T', '<p>x</p>') === false && mail_last_result()['status'] === 'logged');
        check('mensagem para o utilizador explica que não foi enviado', str_contains(mail_result_message(), 'NÃO foi enviado'));
    }
    putenv('LUMINA_MAIL_DRIVER');
    check('destinatário inválido → failed com motivo', send_mail('isto-nao', 'T', '<p>x</p>') === false && mail_last_result()['status'] === 'failed' && mail_last_result()['error'] !== '');
    check('mail_from_address usa o utilizador SMTP quando o remetente é ".local"', mail_from_address(['driver' => 'smtp', 'from_email' => 'a@lumina.local', 'smtp' => ['username' => 'real@gmail.com']]) === 'real@gmail.com');
    check('remetente real é respeitado', mail_from_address(['driver' => 'smtp', 'from_email' => 'info@x.pt', 'smtp' => ['username' => 'real@gmail.com']]) === 'info@x.pt');
});

// Secções novas: um ficheiro por tema em tests/sections/ (carregados por ordem alfabética), para as alterações não colidirem neste ficheiro.
foreach (glob(__DIR__ . '/sections/*.php') ?: [] as $__sectionFile) { require $__sectionFile; }

echo "\n----------------------------------------\n";
echo "Passaram: {$GLOBALS['pass']}   Falharam: {$GLOBALS['fail']}\n";
exit($GLOBALS['fail'] > 0 ? 1 : 0);

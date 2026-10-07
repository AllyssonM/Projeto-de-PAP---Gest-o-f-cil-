<?php
/* =========================================================================
   MARKDOWN MÍNIMO E SEGURO  (includes/markdown.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  transforma os ficheiros de legal/documentos/*.md em HTML para as páginas legais, para
               os textos viverem NUM SÓ SÍTIO e poderem ser editados sem saber programar.
   O QUE ENTENDE:  # título (só na 1.ª linha)  ## secção  ### subsecção  - listas  tabelas | a | b |  ("Tabela: legenda" antes da tabela)
                   **negrito**  *itálico*  `código`  [texto](ligação)  linha em branco = novo parágrafo
   SEGURANÇA:  TODO o HTML é escapado primeiro (um .md nunca consegue injetar <script>); as ligações só
               aceitam http(s), mailto e caminhos relativos. Os {{marcadores}} são trocados DEPOIS, com valores
               de config/legal.php já escapados (ou HTML que o próprio sistema construiu).
   ========================================================================= */
declare(strict_types=1);

function md_inline(string $s): string
{
    $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
    $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<![\*\w])\*(?!\s)(.+?)(?<!\s)\*(?![\*\w])/s', '<em>$1</em>', $s);
    return preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
        $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
        if (!preg_match('#^(https?://|mailto:|[a-z0-9_./\#?=&-]+$)#i', $url)) return $m[1];            // ligações estranhas: só o texto
        $ext = preg_match('#^https?://#i', $url) ? ' target="_blank" rel="noopener noreferrer"' : '';
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"' . $ext . '>' . $m[1] . '</a>';
    }, $s);
}

/** @param array<string,string> $vars  marcadores {{nome}} => HTML já seguro */
function md_render(string $md, array $vars = []): string
{
    $lines = preg_split('/\R/', trim($md));
    $html = []; $para = []; $list = []; $table = []; $caption = '';
    $flush = function () use (&$html, &$para, &$list, &$table, &$caption) {
        if ($para)  { $html[] = '<p>' . md_inline(implode(' ', $para)) . '</p>'; $para = []; }
        if ($list)  { $html[] = '<ul>' . implode('', array_map(fn($i) => '<li>' . md_inline($i) . '</li>', $list)) . '</ul>'; $list = []; }
        if ($table) {
            $rows = array_values(array_filter($table, fn($r) => !preg_match('/^\|?\s*:?-{2,}/', $r)));
            $cells = fn($r) => array_map('trim', explode('|', trim(trim($r), '|')));
            $head = $cells(array_shift($rows));
            $out = '<div class="legal-table-wrap"><table class="legal-table">' . ($caption !== '' ? '<caption class="sr-only">' . md_inline($caption) . '</caption>' : '') . '<thead><tr>' . implode('', array_map(fn($c) => '<th scope="col">' . md_inline($c) . '</th>', $head)) . '</tr></thead><tbody>';
            foreach ($rows as $r) $out .= '<tr>' . implode('', array_map(fn($c) => '<td>' . md_inline($c) . '</td>', $cells($r))) . '</tr>';
            $html[] = $out . '</tbody></table></div>'; $table = []; $caption = '';
        }
    };
    foreach ($lines as $i => $line) {
        if ($i === 0 && preg_match('/^#\s+/', $line)) continue;                       // o título vem à parte (md_title)
        if (trim($line) === '')                      { $flush(); continue; }
        if (preg_match('/^Descrição:\s*/', $line)) continue;                                                       // meta descrição (só para os motores de pesquisa; ver md_description)
        if (preg_match('/^Tabela:\s*(.+)$/', $line, $m)) { $flush(); $caption = $m[1]; continue; }              // legenda (só para leitores de ecrã)
        if (preg_match('/^(#{2,3})\s+(.+)$/', $line, $m)) { $flush(); $n = strlen($m[1]); $html[] = "<h$n>" . md_inline($m[2]) . "</h$n>"; continue; }
        if (preg_match('/^\s*[-*]\s+(.+)$/', $line, $m))  { if ($para || $table) $flush(); $list[] = $m[1]; continue; }
        if (str_starts_with(ltrim($line), '|'))      { if ($para || $list) $flush(); $table[] = $line; continue; }
        if ($list) $flush();
        $para[] = trim($line);
    }
    $flush();
    $out = implode("\n", $html);
    return strtr($out, array_combine(array_map(fn($k) => '{{' . $k . '}}', array_keys($vars)), array_values($vars)));
}

/** Título do documento: a primeira linha "# Título". */
function md_title(string $md, string $fallback = ''): string
{
    return preg_match('/^#\s+(.+)$/m', $md, $m) ? trim($m[1]) : $fallback;
}

/** Meta descrição do documento: a linha "Descrição: ..." (escrita à mão, melhor para o Google) ou, se faltar, o primeiro parágrafo. */
function md_description(string $md, array $vars = []): string
{
    $plain = fn(string $t) => trim(preg_replace('/\s+/', ' ', strip_tags(strtr(preg_replace(['/\[([^\]]+)\]\([^)]*\)/', '/[*`]/'], ['$1', ''], $t), array_combine(array_map(fn($k) => '{{' . $k . '}}', array_keys($vars)), array_values($vars))))));
    if (preg_match('/^Descrição:\s*(.+)$/m', $md, $m)) return $plain($m[1]);
    foreach (preg_split('/\R\R/', $md) as $block) {
        $block = trim($block);
        if ($block !== '' && !preg_match('/^(#|\||-|\*|Tabela:)/', $block)) return $plain($block);
    }
    return '';
}


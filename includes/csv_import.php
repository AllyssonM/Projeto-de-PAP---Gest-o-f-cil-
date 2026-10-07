<?php
/* =========================================================================
   IMPORTAR MOVIMENTOS DE UM CSV  (includes/csv_import.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  lê o ficheiro que o banco ou o Excel exportam e transforma-o em movimentos do Lumina.
   O DIFÍCIL (e porque isto existe):
     - CODIFICAÇÃO: o Excel português grava em Windows-1252, não em UTF-8; sem isto "Descrição" vira "DescriÃ§Ã£o".
     - SEPARADOR: ";" (Excel PT e bancos), "," , tabulação ou "|": deteta-se sozinho.
     - NÚMEROS à portuguesa: "1.234,56", "12,5", "-12,50", "(12,50)", "12,50 €", "12,50-".
     - DATAS: dd/mm/aaaa (dia primeiro), dd-mm-aa, aaaa-mm-dd, com ou sem hora. Datas impossíveis são recusadas.
     - COLUNAS: "Valor" com sinal, ou "Débito" e "Crédito" separados; os nomes comuns dos bancos são reconhecidos.
     - DUPLICADOS: importar o mesmo ficheiro duas vezes NÃO duplica (compara data + tipo + valor + descrição com o que já existe).
   REGRAS: no máximo 1 MB e 2000 linhas; nada é gravado até a pessoa confirmar; cada importação leva um identificador e pode ser anulada.
   ========================================================================= */
declare(strict_types=1);

const IMPORT_MAX_BYTES = 1024 * 1024;
const IMPORT_MAX_ROWS = 2000;
const IMPORT_MAX_AMOUNT = 99999999.99;

final class ImportError extends RuntimeException {}

/** Converte para UTF-8 (tira o BOM; aceita UTF-8 ou Windows-1252). */
function csv_decode(string $bytes): string
{
    if (str_starts_with($bytes, "\xEF\xBB\xBF")) $bytes = substr($bytes, 3);
    if (str_contains(substr($bytes, 0, 4096), "\0")) throw new ImportError('Isto não parece um ficheiro CSV (contém dados binários). Exporta como CSV.');
    return mb_check_encoding($bytes, 'UTF-8') ? $bytes : mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
}

/** O separador mais provável: o que dá o mesmo número (maior que 1) de colunas nas primeiras linhas. */
function csv_detect_delimiter(string $text): string
{
    $lines = array_slice(array_filter(preg_split('/\R/', $text), fn($l) => trim($l) !== ''), 0, 6);
    $best = ';'; $bestScore = -1;
    foreach ([';', ',', "\t", '|'] as $d) {
        $counts = array_map(fn($l) => count(str_getcsv($l, $d, '"', '')), $lines);
        if (!$counts || min($counts) < 2) continue;
        $score = (count(array_unique($counts)) === 1 ? 1000 : 0) + min($counts);
        if ($score > $bestScore) { $bestScore = $score; $best = $d; }
    }
    return $best;
}

/** @return array{headers: list<string>, rows: list<list<string>>, delimiter: string, total: int} */
function csv_parse(string $bytes): array
{
    if (strlen($bytes) === 0) throw new ImportError('O ficheiro está vazio.');
    if (strlen($bytes) > IMPORT_MAX_BYTES) throw new ImportError('O ficheiro é demasiado grande (máximo ' . intdiv(IMPORT_MAX_BYTES, 1024 * 1024) . ' MB). Divide-o em partes.');
    $text = csv_decode($bytes);
    $delimiter = csv_detect_delimiter($text);
    $fh = fopen('php://temp', 'r+'); fwrite($fh, $text); rewind($fh);
    $all = [];
    while (($r = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
        if ($r === [null] || !array_filter($r, fn($c) => trim((string)$c) !== '')) continue;          // linhas em branco
        $all[] = array_map(fn($c) => trim((string)$c), $r);
    }
    fclose($fh);
    if (count($all) < 2) throw new ImportError('Não encontrei movimentos: o ficheiro precisa de uma linha de títulos e pelo menos uma linha de dados.');
    $headers = array_shift($all);
    if (count($all) > IMPORT_MAX_ROWS) throw new ImportError('O ficheiro tem ' . count($all) . ' linhas; o máximo é ' . IMPORT_MAX_ROWS . '. Divide-o em partes.');
    return ['headers' => $headers, 'rows' => $all, 'delimiter' => $delimiter, 'total' => count($all)];
}

/** Sugere que coluna é cada coisa, pelos nomes comuns (PT e EN). Devolve [campo => índice]. */
function csv_guess_mapping(array $headers): array
{
    $norm = fn(string $s) => preg_replace('/[^a-z0-9 ]/', '', strtolower(strtr(trim($s), ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c',
        'Á' => 'a', 'Ã' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ç' => 'c'])));
    $rules = ['date' => ['data', 'data mov', 'data movimento', 'data valor', 'data operacao', 'data lancamento', 'date', 'dia'],
              'description' => ['descricao', 'descritivo', 'movimento', 'detalhe', 'detalhes', 'historico', 'observacoes', 'description', 'memo', 'texto', 'referencia'],
              'amount' => ['valor', 'montante', 'importancia', 'importe', 'amount', 'quantia'],
              'debit' => ['debito', 'saida', 'saidas', 'debit', 'despesa', 'pagamento'],
              'credit' => ['credito', 'entrada', 'entradas', 'credit', 'receita', 'recebimento'],
              'category' => ['categoria', 'category', 'tipo de despesa', 'rubrica']];
    $map = [];
    foreach ($rules as $field => $names) {
        foreach ($headers as $i => $h) {
            $n = $norm($h);
            if (!in_array($i, $map, true) && in_array($n, $names, true)) { $map[$field] = $i; break; }
        }
    }
    foreach (['date' => 'data', 'description' => 'descri', 'amount' => 'valor'] as $field => $part) {          // 2.ª tentativa: o título CONTÉM a palavra
        if (isset($map[$field])) continue;
        foreach ($headers as $i => $h) if (!in_array($i, $map, true) && str_contains($norm($h), $part)) { $map[$field] = $i; break; }
    }
    return $map;
}

/** "1.234,56", "12,5", "-12,50", "(12,50)", "12,50 €", "12,50-", "€ 3.40". Devolve null se não for um número. */
function csv_parse_amount(string $v): ?float
{
    $s = trim(str_replace(["\xC2\xA0", ' ', "\t", '€', 'EUR', 'eur'], '', $v));
    if ($s === '') return null;
    $neg = false;
    if (preg_match('/^\((.*)\)$/', $s, $m)) { $neg = true; $s = $m[1]; }
    if (str_ends_with($s, '-')) { $neg = true; $s = substr($s, 0, -1); }
    if (str_starts_with($s, '-')) { $neg = true; $s = substr($s, 1); }
    elseif (str_starts_with($s, '+')) $s = substr($s, 1);
    if (!preg_match('/^[0-9.,]+$/', $s)) return null;
    $comma = strrpos($s, ','); $dot = strrpos($s, '.');
    if ($comma !== false && $dot !== false) {                                  // os dois: o ÚLTIMO é o decimal
        $dec = $comma > $dot ? ',' : '.'; $thou = $dec === ',' ? '.' : ',';
        $s = str_replace($thou, '', $s); $s = str_replace($dec, '.', $s);
    } elseif ($comma !== false) {                                              // só vírgulas: decimal (convenção portuguesa); várias vírgulas = milhares
        $s = substr_count($s, ',') > 1 ? str_replace(',', '', $s) : str_replace(',', '.', $s);
    } elseif ($dot !== false) {                                                // só pontos: "1.234" = milhares; "12.5" ou "12.50" = decimal
        $parts = explode('.', $s);
        if (count($parts) > 2 || (strlen(end($parts)) === 3 && strlen($parts[0]) <= 3 && $parts[0] !== '0')) $s = str_replace('.', '', $s);
    }
    if (!is_numeric($s)) return null;
    $n = round((float)$s, 2);
    return abs($n) > IMPORT_MAX_AMOUNT ? null : ($neg ? -$n : $n);
}

/** Datas: dd/mm/aaaa (dia primeiro), dd-mm-aa, dd.mm.aaaa, aaaa-mm-dd, com ou sem hora. Devolve 'Y-m-d H:i:s' (12:00 se não houver hora) ou null. */
function csv_parse_date(string $v): ?string
{
    $v = trim($v);
    $time = '12:00:00';
    if (preg_match('/[ T](\d{1,2}):(\d{2})(?::(\d{2}))?/', $v, $t)) {
        if ((int)$t[1] > 23 || (int)$t[2] > 59) return null;
        $time = sprintf('%02d:%02d:%02d', (int)$t[1], (int)$t[2], (int)($t[3] ?? 0));
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) { [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]]; }
    elseif (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})/', $v, $m)) { [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]]; if ($y < 100) $y += 2000; }
    else return null;
    if (!checkdate($mo, $d, $y) || $y < 2000 || $y > (int)date('Y') + 1) return null;
    return sprintf('%04d-%02d-%02d %s', $y, $mo, $d, $time);
}

/**
 * Converte as linhas em movimentos, conforme o mapeamento e as opções.
 * $mapping: [campo => índice da coluna]. $opts: sign_mode ('auto' o sinal decide | 'income' tudo entradas | 'expense' tudo saídas), default_category.
 * @return array{items: list<array>, errors: list<array>}  items: type, description, category, amount, occurred_at, line ; errors: line, reason
 */
function csv_build_rows(array $rows, array $mapping, array $opts = []): array
{
    $mode = in_array($opts['sign_mode'] ?? 'auto', ['auto', 'income', 'expense'], true) ? $opts['sign_mode'] : 'auto';
    $defCat = mb_substr(trim((string)($opts['default_category'] ?? '')), 0, 80) ?: 'Importado';
    $get = fn(array $r, string $f) => isset($mapping[$f]) ? (string)($r[(int)$mapping[$f]] ?? '') : '';
    $items = []; $errors = [];
    foreach ($rows as $i => $r) {
        $line = $i + 2;                                                        // linha no ficheiro (1 = títulos)
        $date = csv_parse_date($get($r, 'date'));
        if ($date === null) { $errors[] = ['line' => $line, 'reason' => 'data inválida («' . mb_substr($get($r, 'date'), 0, 20) . '»)']; continue; }
        if (isset($mapping['debit']) || isset($mapping['credit'])) {
            $deb = csv_parse_amount($get($r, 'debit')) ?? 0.0; $cre = csv_parse_amount($get($r, 'credit')) ?? 0.0;
            if ($deb != 0 && $cre != 0) { $errors[] = ['line' => $line, 'reason' => 'tem débito e crédito ao mesmo tempo']; continue; }
            $signed = $cre != 0 ? abs($cre) : -abs($deb);
            if ($deb == 0 && $cre == 0) { $errors[] = ['line' => $line, 'reason' => 'sem valor']; continue; }
        } elseif (isset($mapping['amount'])) {
            $signed = csv_parse_amount($get($r, 'amount'));
            if ($signed === null) { $errors[] = ['line' => $line, 'reason' => 'valor inválido («' . mb_substr($get($r, 'amount'), 0, 20) . '»)']; continue; }
            if ($signed == 0) { $errors[] = ['line' => $line, 'reason' => 'valor zero']; continue; }
        } else { $errors[] = ['line' => $line, 'reason' => 'não há coluna de valor']; continue; }
        $type = $mode === 'income' ? 'income' : ($mode === 'expense' ? 'expense' : ($signed > 0 ? 'income' : 'expense'));
        $desc = preg_replace('/\s+/u', ' ', trim($get($r, 'description')));
        $cat = trim($get($r, 'category'));
        $items[] = ['type' => $type, 'description' => mb_substr($desc !== '' ? $desc : 'Movimento importado', 0, 200), 'category' => mb_substr($cat !== '' ? $cat : $defCat, 0, 80),
                    'amount' => abs($signed), 'occurred_at' => $date, 'line' => $line];
    }
    return ['items' => $items, 'errors' => $errors];
}

/** Marca os que já existem na base de dados (mesma data + tipo + valor + descrição). Um mesmo movimento legítimo repetido (2 cafés iguais) só é recusado se JÁ existia o mesmo número de vezes. */
function csv_mark_duplicates(int $tenantId, array $items): array
{
    if (!$items) return [];
    $from = min(array_column($items, 'occurred_at')); $to = max(array_column($items, 'occurred_at'));
    $st = db()->prepare("SELECT DATE(occurred_at) d, type, amount, LOWER(description) de, COUNT(*) n FROM transactions WHERE user_id = ? AND occurred_at >= ? AND occurred_at < DATE_ADD(?, INTERVAL 1 DAY) GROUP BY d, type, amount, de");
    $st->execute([$tenantId, substr($from, 0, 10) . ' 00:00:00', substr($to, 0, 10)]);
    $have = [];
    foreach ($st->fetchAll() as $r) $have[$r['d'] . '|' . $r['type'] . '|' . number_format((float)$r['amount'], 2, '.', '') . '|' . $r['de']] = (int)$r['n'];
    $status = [];
    foreach ($items as $k => $it) {
        $key = substr($it['occurred_at'], 0, 10) . '|' . $it['type'] . '|' . number_format($it['amount'], 2, '.', '') . '|' . mb_strtolower($it['description']);
        if (($have[$key] ?? 0) > 0) { $have[$key]--; $status[$k] = 'duplicate'; } else $status[$k] = 'ok';
    }
    return $status;
}

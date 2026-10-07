<?php
/* =========================================================================
   VALIDAÇÃO DE DADOS  (includes/validator.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  uma só maneira de validar o que vem do navegador (antes cada API repetia
               is_numeric / trim / in_array à sua maneira, e deixava passar coisas como
               valores negativos ou datas inventadas).
   COMO SE USA:
       $v = Validator::make($data)
           ->text('description', 'Descrição', 200)
           ->money('amount', 'Valor')
           ->enum('type', 'Tipo', ['income', 'expense'])
           ->date('due_date', 'Data');
       $clean = $v->orFail();            // se algo falhar: responde 400 com a primeira mensagem e pára
       $clean['amount']                  // já é um float válido (> 0, no máximo 2 casas decimais)
   REGRAS GERAIS: texto -> aparado e com tamanho máximo; dinheiro -> número finito, até 2 casas;
   data -> AAAA-MM-DD existente no calendário; enum -> só valores da lista; id -> inteiro positivo.
   Os campos opcionais vazios ficam null (ou o valor por omissão).
   ========================================================================= */
declare(strict_types=1);

final class Validator
{
    private array $clean = [];
    private array $errors = [];

    private function __construct(private array $in) {}

    public static function make(array $in): self { return new self($in); }

    private function raw(string $k): mixed { return $this->in[$k] ?? null; }
    private function blank(mixed $v): bool { return $v === null || (is_string($v) && trim($v) === ''); }
    private function fail(string $k, string $msg): self { $this->errors[$k] ??= $msg; return $this; }

    /** Texto curto. $required=false deixa passar vazio (fica o valor por omissão). */
    public function text(string $k, string $label, int $max = 255, bool $required = true, ?string $default = null): self
    {
        $v = $this->raw($k);
        if ($this->blank($v)) { $this->clean[$k] = $default; return $required ? $this->fail($k, "$label é obrigatório.") : $this; }
        if (!is_scalar($v)) return $this->fail($k, "$label inválido.");
        $v = trim((string)$v);
        if (mb_strlen($v) > $max) return $this->fail($k, "$label é demasiado longo (máximo $max caracteres).");
        $this->clean[$k] = $v;
        return $this;
    }

    /** Dinheiro em euros: número finito, com no máximo 2 casas decimais. $positive=true exige > 0. */
    public function money(string $k, string $label, bool $positive = true, bool $required = true, float $max = 99999999.99, ?float $default = null): self
    {
        $v = $this->raw($k);
        if ($this->blank($v)) { $this->clean[$k] = $default; return $required ? $this->fail($k, "$label é obrigatório.") : $this; }
        if (is_string($v)) $v = str_replace(',', '.', trim($v));
        if (!is_numeric($v) || !is_finite((float)$v)) return $this->fail($k, "$label tem de ser um número.");
        $n = round((float)$v, 2);
        if ($positive && $n <= 0) return $this->fail($k, "$label tem de ser maior que zero.");
        if (!$positive && $n < 0) return $this->fail($k, "$label não pode ser negativo.");
        if (abs($n) > $max) return $this->fail($k, "$label é demasiado grande.");
        $this->clean[$k] = $n;
        return $this;
    }

    /** Número inteiro entre $min e $max. */
    public function int(string $k, string $label, int $min = 0, int $max = 1000000, bool $required = true, ?int $default = null): self
    {
        $v = $this->raw($k);
        if ($this->blank($v)) { $this->clean[$k] = $default; return $required ? $this->fail($k, "$label é obrigatório.") : $this; }
        if (!is_numeric($v) || (float)$v != (int)$v) return $this->fail($k, "$label tem de ser um número inteiro.");
        $n = (int)$v;
        if ($n < $min || $n > $max) return $this->fail($k, "$label tem de estar entre $min e $max.");
        $this->clean[$k] = $n;
        return $this;
    }

    /** Valor de uma lista fixa. */
    public function enum(string $k, string $label, array $allowed, ?string $default = null): self
    {
        $v = $this->raw($k);
        if ($this->blank($v)) { $this->clean[$k] = $default; return $default === null ? $this->fail($k, "$label é obrigatório.") : $this; }
        if (!is_string($v) || !in_array($v, $allowed, true)) return $this->fail($k, "$label inválido.");
        $this->clean[$k] = $v;
        return $this;
    }

    /** Valor de uma lista fixa, mas OPCIONAL: vazio fica null. */
    public function enumOptional(string $k, string $label, array $allowed): self
    {
        $v = $this->raw($k);
        if ($this->blank($v)) { $this->clean[$k] = null; return $this; }
        if (!is_string($v) || !in_array($v, $allowed, true)) return $this->fail($k, "$label inválido.");
        $this->clean[$k] = $v;
        return $this;
    }

    /** Data AAAA-MM-DD que exista mesmo (recusa 2026-02-30). */
    public function date(string $k, string $label, bool $required = true): self
    {
        $v = $this->raw($k);
        if ($this->blank($v)) { $this->clean[$k] = null; return $required ? $this->fail($k, "$label é obrigatória.") : $this; }
        if (!is_string($v) || !self::validDate(substr(trim($v), 0, 10)) || strlen(trim($v)) !== 10) return $this->fail($k, "$label inválida (use AAAA-MM-DD).");
        $this->clean[$k] = trim($v);
        return $this;
    }

    /** Data e hora AAAA-MM-DD HH:MM[:SS] (aceita o "T" do campo datetime-local). Guarda com espaço e segundos. */
    public function datetime(string $k, string $label, bool $required = true): self
    {
        $v = $this->raw($k);
        if ($this->blank($v)) { $this->clean[$k] = null; return $required ? $this->fail($k, "$label é obrigatória.") : $this; }
        $s = str_replace('T', ' ', trim((string)$v));
        if (!preg_match('/^(\d{4}-\d{2}-\d{2}) ([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $s, $m) || !self::validDate($m[1])) return $this->fail($k, "$label inválida.");
        $this->clean[$k] = $m[1] . ' ' . $m[2] . ':' . $m[3] . ':' . ($m[4] ?? '00');
        return $this;
    }

    public function email(string $k, string $label = 'Email', bool $required = true): self
    {
        $v = $this->raw($k);
        if ($this->blank($v)) { $this->clean[$k] = null; return $required ? $this->fail($k, "$label é obrigatório.") : $this; }
        $v = mb_strtolower(trim((string)$v));
        if (mb_strlen($v) > 190 || !filter_var($v, FILTER_VALIDATE_EMAIL)) return $this->fail($k, "$label inválido.");
        $this->clean[$k] = $v;
        return $this;
    }

    /** Identificador (inteiro positivo) opcional; vazio -> null. */
    public function id(string $k, string $label = 'Identificador', bool $required = false): self
    {
        $v = $this->raw($k);
        if ($this->blank($v) || $v === 0 || $v === '0') { $this->clean[$k] = null; return $required ? $this->fail($k, "$label é obrigatório.") : $this; }
        if (!is_numeric($v) || (int)$v < 1) return $this->fail($k, "$label inválido.");
        $this->clean[$k] = (int)$v;
        return $this;
    }

    public function fails(): bool { return $this->errors !== []; }
    public function errors(): array { return $this->errors; }
    public function error(): string { return $this->errors ? (string)reset($this->errors) : ''; }
    public function data(): array { return $this->clean; }

    /** Devolve os dados limpos, ou responde 400 com a primeira mensagem e termina. */
    public function orFail(): array
    {
        if ($this->fails()) json_response(['success' => false, 'error' => $this->error(), 'fields' => $this->errors], 400);
        return $this->clean;
    }

    public static function validDate(string $d): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) && (int)$m[1] >= 1900 && (int)$m[1] <= 2200;
    }
}

-- Lumina — migração v17: limite de pedidos (rate limiting)
-- Seguro de repetir; NÃO altera nem apaga dados. Só cria uma tabela de contadores de curta duração (ver includes/rate_limit.php).
-- Cada linha conta os pedidos de UM "balde" (um utilizador, um endereço IP ou um email-alvo) numa janela de tempo.
-- O "balde" é guardado como código de dispersão (HMAC-SHA256): nunca o IP nem o email em claro. As janelas com mais de 2 horas são apagadas
-- automaticamente, por isso a tabela fica pequena.
USE gestao_facil;

CREATE TABLE IF NOT EXISTS rate_limits (
    bucket CHAR(64) NOT NULL,                          -- HMAC-SHA256 em hexadecimal de "âmbito|chave"
    window_start INT UNSIGNED NOT NULL,                -- início da janela, em segundos Unix (UTC: não depende do fuso horário)
    hits INT UNSIGNED NOT NULL DEFAULT 0,              -- pedidos contados nesta janela
    PRIMARY KEY (bucket, window_start),
    KEY idx_rate_limits_window (window_start)          -- para apagar as janelas antigas depressa
) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin;

INSERT IGNORE INTO schema_migrations (version) VALUES ('v17');

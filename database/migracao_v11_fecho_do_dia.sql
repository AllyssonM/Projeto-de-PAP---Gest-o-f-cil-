-- Lumina — migração v11: método de pagamento nos movimentos e dinheiro esperado no fecho do dia.
-- Seguro de repetir: não apaga dados.
USE gestao_facil;
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS payment_method ENUM('cash','mbway','card','transfer','other') NULL;
ALTER TABLE day_closings ADD COLUMN IF NOT EXISTS expected_cash DECIMAL(12,2) NOT NULL DEFAULT 0;
INSERT IGNORE INTO schema_migrations (version) VALUES ('v11');

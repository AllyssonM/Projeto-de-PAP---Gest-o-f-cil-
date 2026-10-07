-- Lumina — migração v12: orçamentos mensais por categoria e identificador de importação (para anular uma importação).
-- Seguro de repetir: não apaga dados.
USE gestao_facil;
CREATE TABLE IF NOT EXISTS budgets (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL,
  category      VARCHAR(80) NOT NULL,
  monthly_limit DECIMAL(12,2) NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_budget_category (user_id, category),
  CONSTRAINT fk_budget_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Mesma regra de comparação de texto das tabelas originais (senão não se pode comparar budgets.category com transactions.category).
-- Também corrige uma tabela já criada com outra regra. Seguro de repetir.
ALTER TABLE budgets CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS import_batch CHAR(16) NULL;
CREATE INDEX IF NOT EXISTS idx_tx_import ON transactions (user_id, import_batch);
INSERT IGNORE INTO schema_migrations (version) VALUES ('v12');

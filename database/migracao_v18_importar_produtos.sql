-- Lumina — migração v18: importar produtos por CSV (com anulação segura)
-- Seguro de repetir; NÃO altera nem apaga dados. Só acrescenta duas colunas opcionais a "products":
--   import_batch  identificador da importação que criou o produto (vazio nos produtos criados à mão)
--   import_sig    "assinatura" do produto tal como foi importado; ao anular, só se apagam os produtos que continuam EXATAMENTE assim
--                 (sem edições, sem vendas, sem ajustes de estoque). Os outros ficam, e o ecrã diz quais e porquê.
USE gestao_facil;

ALTER TABLE products ADD COLUMN IF NOT EXISTS import_batch CHAR(16) NULL AFTER status;
ALTER TABLE products ADD COLUMN IF NOT EXISTS import_sig CHAR(16) NULL AFTER import_batch;
ALTER TABLE products ADD INDEX IF NOT EXISTS idx_products_import (user_id, import_batch);

INSERT IGNORE INTO schema_migrations (version) VALUES ('v18');

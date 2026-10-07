-- Lumina — migração v7: campos próprios de cada ramo de atividade
-- Seguro de repetir: não apaga dados. Testado em MariaDB (XAMPP).
--
-- Cada ramo tem o seu "produto": um imóvel (tipologia, área, localização...), uma viatura
-- (marca, modelo, ano, km...), um corte de cabelo (duração, profissional...), uma viagem...
-- Em vez de criar uma tabela por ramo, os campos extra ficam num JSON em products.attributes.
-- Os campos de cada ramo estão definidos em assets/js/profiles.js.

USE gestao_facil;

ALTER TABLE products ADD COLUMN IF NOT EXISTS attributes LONGTEXT NULL AFTER image_url;

-- a própria base de dados recusa JSON inválido
ALTER TABLE products DROP CONSTRAINT IF EXISTS chk_products_attributes;
ALTER TABLE products ADD CONSTRAINT chk_products_attributes CHECK (attributes IS NULL OR JSON_VALID(attributes));

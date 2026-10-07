-- Lumina — migração v16: variações de produto (tamanho/cor), vendas ligadas ao estoque e anulação
-- Seguro de repetir; NÃO apaga dados. Os produtos que já existem ficam com UMA variação (a que corresponde ao que já tinham:
-- tamanho e cor lidos de "attributes", quantidade = estoque atual).
USE gestao_facil;

-- 1) Produto: marca como coluna própria (antes só existia dentro de "attributes").
ALTER TABLE products ADD COLUMN IF NOT EXISTS brand VARCHAR(100) NULL AFTER category;
UPDATE products SET brand = LEFT(JSON_VALUE(attributes, '$.marca'), 100)
 WHERE brand IS NULL AND attributes IS NOT NULL AND JSON_VALID(attributes) AND JSON_VALUE(attributes, '$.marca') IS NOT NULL AND JSON_VALUE(attributes, '$.marca') <> '';

-- 2) Variações: cada combinação de tamanho + cor tem o seu estoque. products.stock_quantity passa a ser a SOMA das variações
--    (o servidor mantém-na; assim o resumo, a Lumina e os alertas continuam a funcionar sem mudanças).
CREATE TABLE IF NOT EXISTS product_variants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    size VARCHAR(20) NULL,
    color VARCHAR(40) NULL,
    price DECIMAL(14,2) NULL,                      -- vazio = usa o preço de venda do produto
    quantity INT NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'active',  -- active | inactive
    size_key VARCHAR(20) AS (COALESCE(size, '')) PERSISTENT,
    color_key VARCHAR(40) AS (COALESCE(color, '')) PERSISTENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_variant_combo (product_id, size_key, color_key),   -- sem duplicados (sem distinguir maiúsculas)
    KEY idx_variant_user (user_id, product_id),
    CONSTRAINT fk_variant_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT chk_variant_qty CHECK (quantity >= 0),                -- o estoque nunca fica negativo, nem por engano
    CONSTRAINT chk_variant_price CHECK (price IS NULL OR price >= 0)
) ENGINE=InnoDB;

INSERT INTO product_variants (user_id, product_id, size, color, quantity)
SELECT p.user_id, p.id,
       NULLIF(LEFT(CASE WHEN p.attributes IS NOT NULL AND JSON_VALID(p.attributes) THEN JSON_VALUE(p.attributes, '$.tamanho') END, 20), ''),
       NULLIF(LEFT(CASE WHEN p.attributes IS NOT NULL AND JSON_VALID(p.attributes) THEN JSON_VALUE(p.attributes, '$.cor') END, 40), ''),
       GREATEST(p.stock_quantity, 0)
  FROM products p
 WHERE NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id);

-- 3) Vendas: cabeçalho (sale_orders) + itens (a tabela "sales" que já existia passa a ser a dos itens, com o retrato do produto).
CREATE TABLE IF NOT EXISTS sale_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    total DECIMAL(14,2) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'completed',   -- completed | cancelled
    sold_at DATETIME NOT NULL,
    created_by INT NULL,                               -- quem registou
    request_key CHAR(36) NULL,                         -- impede registar a mesma venda duas vezes (duplo clique, recarregar, reenviar)
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sale_request (user_id, request_key),
    KEY idx_sale_orders_date (user_id, sold_at),
    CONSTRAINT fk_sale_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE sales ADD COLUMN IF NOT EXISTS sale_order_id INT NULL AFTER user_id;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS variant_id INT NULL AFTER product_id;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS unit_price DECIMAL(14,2) NULL AFTER quantity;      -- preço unitário de catálogo no momento da venda
ALTER TABLE sales ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'completed' AFTER amount;   -- completed | cancelled
ALTER TABLE sales ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE sales ADD INDEX IF NOT EXISTS idx_sales_order (sale_order_id);
ALTER TABLE sales ADD INDEX IF NOT EXISTS idx_sales_variant (variant_id);

-- 4) Movimentos de estoque: de que variação e de que venda vieram.
ALTER TABLE stock_movements ADD COLUMN IF NOT EXISTS variant_id INT NULL AFTER product_id;
ALTER TABLE stock_movements ADD COLUMN IF NOT EXISTS sale_id INT NULL AFTER transaction_id;

INSERT IGNORE INTO schema_migrations (version) VALUES ('v16');

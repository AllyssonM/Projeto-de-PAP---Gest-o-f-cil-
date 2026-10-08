-- Lumina — migração v19: alertas de estoque baixo por email (sem repetir avisos)
-- Seguro de repetir; NÃO altera nem apaga dados. Só cria uma tabela com o último aviso enviado por produto.
-- Serve para NÃO avisar duas vezes do mesmo produto: a linha desaparece quando o produto é reposto, e o aviso volta a sair se voltar a baixar.
-- Nenhum dado pessoal: só o negócio, o produto, o nível («low» = baixo, «out» = sem estoque) e a data.
USE gestao_facil;

CREATE TABLE IF NOT EXISTS stock_alert_state (
    user_id INT(11) NOT NULL,                          -- o negócio (dono)
    product_id INT(11) NOT NULL,
    level ENUM('low','out') NOT NULL,
    notified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, product_id),
    CONSTRAINT fk_stock_alert_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO schema_migrations (version) VALUES ('v19');

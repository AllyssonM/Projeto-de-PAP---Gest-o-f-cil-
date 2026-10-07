-- =========================================================
-- Lumina — migração v5: cartões associados (demonstração)
-- Guarda APENAS: bandeira, últimos 4 dígitos, titular, validade e se é o principal.
-- O número completo e o CVV/CVC NUNCA são enviados nem guardados (não existem colunas para isso).
-- Seguro para executar mais do que uma vez (IF NOT EXISTS).
-- =========================================================
USE gestao_facil;

CREATE TABLE IF NOT EXISTS payment_cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    brand VARCHAR(20) NOT NULL,                 -- visa | mastercard | amex
    last4 CHAR(4) NOT NULL,
    holder_name VARCHAR(60) NOT NULL,
    exp_month TINYINT UNSIGNED NOT NULL,
    exp_year SMALLINT UNSIGNED NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_card_user (user_id, brand, last4, exp_month, exp_year),
    INDEX idx_card_user (user_id, is_primary),
    CONSTRAINT fk_card_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

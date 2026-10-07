-- Lumina — migração v2: revisão da base de dados
-- Seguro de repetir: não apaga dados. Testado em MariaDB 10.4 (XAMPP).
--
-- 1. Cria tabelas em falta (calendar_events)
-- 2. Chaves estrangeiras com ON DELETE (apagar um cliente/conta/produto já não dá erro)
-- 3. Índices compostos para as consultas mais usadas
-- 4. Validações CHECK (tipos, estados e valores)

USE gestao_facil;

-- 1. Tabelas em falta ---------------------------------------------------------

CREATE TABLE IF NOT EXISTS calendar_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    event_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    location VARCHAR(200) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_calendar_user_date (user_id, event_date),
    CONSTRAINT fk_calendar_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 2. Chaves estrangeiras ------------------------------------------------------
-- Dados do utilizador: CASCADE (apagar a conta do utilizador apaga os seus dados)
-- Referências opcionais (cliente, conta, movimento): SET NULL (o registo fica, sem a ligação)
-- Filhos que não fazem sentido sozinhos: CASCADE

ALTER TABLE business_profiles DROP FOREIGN KEY IF EXISTS fk_business_profile_user;
ALTER TABLE business_profiles ADD CONSTRAINT fk_business_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE accounts DROP FOREIGN KEY IF EXISTS fk_account_user;
ALTER TABLE accounts ADD CONSTRAINT fk_account_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE clients DROP FOREIGN KEY IF EXISTS fk_client_user;
ALTER TABLE clients ADD CONSTRAINT fk_client_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE products DROP FOREIGN KEY IF EXISTS fk_product_user;
ALTER TABLE products ADD CONSTRAINT fk_product_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE transactions DROP FOREIGN KEY IF EXISTS fk_transaction_user;
ALTER TABLE transactions DROP FOREIGN KEY IF EXISTS fk_transaction_account;
ALTER TABLE transactions DROP FOREIGN KEY IF EXISTS fk_transaction_client;
ALTER TABLE transactions
    ADD CONSTRAINT fk_transaction_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_transaction_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_transaction_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;

ALTER TABLE financial_documents DROP FOREIGN KEY IF EXISTS fk_financial_document_user;
ALTER TABLE financial_documents DROP FOREIGN KEY IF EXISTS fk_financial_document_client;
ALTER TABLE financial_documents DROP FOREIGN KEY IF EXISTS fk_financial_document_account;
ALTER TABLE financial_documents
    ADD CONSTRAINT fk_financial_document_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_financial_document_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_financial_document_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL;

ALTER TABLE stock_movements DROP FOREIGN KEY IF EXISTS fk_stock_movement_user;
ALTER TABLE stock_movements DROP FOREIGN KEY IF EXISTS fk_stock_movement_product;
ALTER TABLE stock_movements DROP FOREIGN KEY IF EXISTS fk_stock_movement_transaction;
ALTER TABLE stock_movements
    ADD CONSTRAINT fk_stock_movement_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_stock_movement_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_stock_movement_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL;

ALTER TABLE notes DROP FOREIGN KEY IF EXISTS fk_note_user;
ALTER TABLE notes DROP FOREIGN KEY IF EXISTS fk_note_client;
ALTER TABLE notes DROP FOREIGN KEY IF EXISTS fk_note_product;
ALTER TABLE notes DROP FOREIGN KEY IF EXISTS fk_note_transaction;
ALTER TABLE notes
    ADD CONSTRAINT fk_note_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_note_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_note_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_note_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL;

ALTER TABLE investment_accounts DROP FOREIGN KEY IF EXISTS fk_investment_account_user;
ALTER TABLE investment_accounts ADD CONSTRAINT fk_investment_account_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE investment_updates DROP FOREIGN KEY IF EXISTS fk_investment_update_account;
ALTER TABLE investment_updates ADD CONSTRAINT fk_investment_update_account FOREIGN KEY (investment_account_id) REFERENCES investment_accounts(id) ON DELETE CASCADE;

ALTER TABLE reserve_funds DROP FOREIGN KEY IF EXISTS fk_reserve_fund_user;
ALTER TABLE reserve_funds ADD CONSTRAINT fk_reserve_fund_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE reserve_fund_movements DROP FOREIGN KEY IF EXISTS fk_reserve_movement_fund;
ALTER TABLE reserve_fund_movements ADD CONSTRAINT fk_reserve_movement_fund FOREIGN KEY (reserve_fund_id) REFERENCES reserve_funds(id) ON DELETE CASCADE;

ALTER TABLE transfers DROP FOREIGN KEY IF EXISTS fk_transfer_user;
ALTER TABLE transfers DROP FOREIGN KEY IF EXISTS fk_transfer_from_account;
ALTER TABLE transfers DROP FOREIGN KEY IF EXISTS fk_transfer_to_account;
ALTER TABLE transfers
    ADD CONSTRAINT fk_transfer_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_transfer_from_account FOREIGN KEY (from_account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_transfer_to_account FOREIGN KEY (to_account_id) REFERENCES accounts(id) ON DELETE CASCADE;

ALTER TABLE user_sessions DROP FOREIGN KEY IF EXISTS fk_session_user;
ALTER TABLE user_sessions ADD CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- 3. Índices ------------------------------------------------------------------

ALTER TABLE transactions
    ADD INDEX IF NOT EXISTS idx_transactions_user_date (user_id, occurred_at),
    ADD INDEX IF NOT EXISTS idx_transactions_user_status (user_id, status);
ALTER TABLE financial_documents
    ADD INDEX IF NOT EXISTS idx_documents_user_due (user_id, due_date),
    ADD INDEX IF NOT EXISTS idx_documents_user_status (user_id, status);
ALTER TABLE products ADD INDEX IF NOT EXISTS idx_products_user_status (user_id, status);
ALTER TABLE clients ADD INDEX IF NOT EXISTS idx_clients_user_name (user_id, name);
ALTER TABLE accounts ADD INDEX IF NOT EXISTS idx_accounts_user_name (user_id, name);
ALTER TABLE user_sessions ADD INDEX IF NOT EXISTS idx_sessions_expires (expires_at);

-- 4. Validações ---------------------------------------------------------------

ALTER TABLE transactions DROP CONSTRAINT IF EXISTS chk_transactions_type;
ALTER TABLE transactions DROP CONSTRAINT IF EXISTS chk_transactions_status;
ALTER TABLE transactions DROP CONSTRAINT IF EXISTS chk_transactions_amount;
ALTER TABLE transactions
    ADD CONSTRAINT chk_transactions_type CHECK (type IN ('income','expense')),
    ADD CONSTRAINT chk_transactions_status CHECK (status IN ('paid','planned')),
    ADD CONSTRAINT chk_transactions_amount CHECK (amount > 0);

ALTER TABLE financial_documents DROP CONSTRAINT IF EXISTS chk_documents_direction;
ALTER TABLE financial_documents DROP CONSTRAINT IF EXISTS chk_documents_status;
ALTER TABLE financial_documents DROP CONSTRAINT IF EXISTS chk_documents_amount;
ALTER TABLE financial_documents
    ADD CONSTRAINT chk_documents_direction CHECK (direction IN ('payable','receivable')),
    ADD CONSTRAINT chk_documents_status CHECK (status IN ('pending','paid','cancelled')),
    ADD CONSTRAINT chk_documents_amount CHECK (amount >= 0);

ALTER TABLE accounts DROP CONSTRAINT IF EXISTS chk_accounts_type;
ALTER TABLE accounts ADD CONSTRAINT chk_accounts_type CHECK (type IN ('cash','bank','reserve','investment'));

ALTER TABLE products DROP CONSTRAINT IF EXISTS chk_products_prices;
ALTER TABLE products ADD CONSTRAINT chk_products_prices CHECK (cost_price >= 0 AND sale_price >= 0);

ALTER TABLE transfers DROP CONSTRAINT IF EXISTS chk_transfers_amount;
ALTER TABLE transfers ADD CONSTRAINT chk_transfers_amount CHECK (amount > 0 AND from_account_id <> to_account_id);

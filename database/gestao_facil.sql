CREATE DATABASE IF NOT EXISTS gestao_facil CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE gestao_facil;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(320) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(30) NOT NULL DEFAULT 'user',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS business_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    business_name VARCHAR(160) NOT NULL DEFAULT 'Meu negócio',
    business_type VARCHAR(40) NOT NULL DEFAULT 'other',
    currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
    enabled_modules JSON NULL,
    labels JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_business_profile_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    type VARCHAR(30) NOT NULL DEFAULT 'cash',
    balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    target_amount DECIMAL(14,2) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_account_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(180) NOT NULL,
    email VARCHAR(320) NULL,
    phone VARCHAR(40) NULL,
    tax_number VARCHAR(40) NULL,
    category VARCHAR(80) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_client_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(160) NOT NULL,
    sku VARCHAR(80) NULL,
    category VARCHAR(100) NULL,
    cost_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    sale_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    stock_quantity INT NOT NULL DEFAULT 0,
    minimum_stock INT NOT NULL DEFAULT 0,
    image_url VARCHAR(500) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    account_id INT NULL,
    client_id INT NULL,
    type VARCHAR(20) NOT NULL,
    description VARCHAR(180) NOT NULL,
    category VARCHAR(100) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'paid',
    occurred_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_transaction_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_transaction_account FOREIGN KEY (account_id) REFERENCES accounts(id),
    CONSTRAINT fk_transaction_client FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS financial_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    client_id INT NULL,
    account_id INT NULL,
    direction VARCHAR(20) NOT NULL,
    title VARCHAR(180) NOT NULL,
    counterparty VARCHAR(180) NULL,
    amount DECIMAL(14,2) NOT NULL,
    due_date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_financial_document_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_financial_document_client FOREIGN KEY (client_id) REFERENCES clients(id),
    CONSTRAINT fk_financial_document_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    transaction_id INT NULL,
    type VARCHAR(30) NOT NULL,
    quantity INT NOT NULL,
    unit_cost DECIMAL(14,2) NULL,
    reason VARCHAR(180) NULL,
    occurred_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_stock_movement_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_stock_movement_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_stock_movement_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    client_id INT NULL,
    product_id INT NULL,
    transaction_id INT NULL,
    title VARCHAR(160) NOT NULL,
    detail TEXT NOT NULL,
    tag VARCHAR(80) NOT NULL DEFAULT 'Geral',
    color VARCHAR(30) NOT NULL DEFAULT 'mint',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_note_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_note_client FOREIGN KEY (client_id) REFERENCES clients(id),
    CONSTRAINT fk_note_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_note_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS investment_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(160) NOT NULL,
    type VARCHAR(40) NOT NULL,
    institution VARCHAR(160) NULL,
    initial_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    contribution_date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_investment_account_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS investment_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    investment_account_id INT NOT NULL,
    current_value DECIMAL(14,2) NOT NULL,
    profit_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    profit_percent DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    recorded_at DATE NOT NULL,
    source VARCHAR(80) NOT NULL DEFAULT 'manual',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_investment_update_account FOREIGN KEY (investment_account_id) REFERENCES investment_accounts(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reserve_funds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL DEFAULT 'Fundo de emergência',
    target_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    current_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_reserve_fund_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reserve_fund_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reserve_fund_id INT NOT NULL,
    type VARCHAR(20) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    description VARCHAR(180) NULL,
    occurred_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reserve_movement_fund FOREIGN KEY (reserve_fund_id) REFERENCES reserve_funds(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transfers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    from_account_id INT NOT NULL,
    to_account_id INT NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    note VARCHAR(180) NULL,
    transferred_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_transfer_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_transfer_from_account FOREIGN KEY (from_account_id) REFERENCES accounts(id),
    CONSTRAINT fk_transfer_to_account FOREIGN KEY (to_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB;

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

CREATE TABLE IF NOT EXISTS user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_token VARCHAR(128) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ===== Assistente de IA =====

CREATE TABLE IF NOT EXISTS ai_conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(160) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ai_conv_user (user_id, updated_at),
    CONSTRAINT fk_ai_conv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ai_messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    user_id INT NOT NULL,
    role VARCHAR(12) NOT NULL,                 -- user | assistant
    content TEXT NOT NULL,
    meta TEXT NULL,                            -- JSON: fontes consultadas, modo
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_msg_conv (conversation_id, id),
    INDEX idx_ai_msg_rate (user_id, role, created_at),
    CONSTRAINT fk_ai_msg_conv FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_msg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Registo de auditoria: que ferramenta foi usada, por quem e com que resultado.
CREATE TABLE IF NOT EXISTS ai_audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,                      -- quem fez a pergunta
    tenant_id INT NULL,                        -- dono do negócio cujos dados foram consultados
    conversation_id INT NULL,
    tool_name VARCHAR(60) NOT NULL,
    arguments TEXT NULL,
    status VARCHAR(20) NOT NULL,               -- ok | blocked | error
    row_count INT NULL,
    error VARCHAR(255) NULL,
    duration_ms INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_audit_user (user_id, created_at)
) ENGINE=InnoDB;

-- ===== Cartões associados (demonstração: sem número completo nem CVV) =====

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

-- ===== Limite de tentativas de login =====

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_email (email, created_at),
    INDEX idx_login_ip (ip, created_at)
) ENGINE=InnoDB;

-- ===== Campos próprios de cada ramo (produtos) =====

ALTER TABLE products ADD COLUMN IF NOT EXISTS attributes LONGTEXT NULL AFTER image_url;

-- a própria base de dados recusa JSON inválido
ALTER TABLE products DROP CONSTRAINT IF EXISTS chk_products_attributes;
ALTER TABLE products ADD CONSTRAINT chk_products_attributes CHECK (attributes IS NULL OR JSON_VALID(attributes));

-- ===== Área pessoal, privacidade, segurança, tempo ativo, notas, painéis por ramo e Google Calendar (v8) =====

-- ---------- 1) UTILIZADORES ----------
ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_path VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS preferences LONGTEXT NULL;                 -- JSON: {"hide_values":true,...}
ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(255) NULL;             -- segredo do 2.º passo, CIFRADO
ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_enabled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_backup LONGTEXT NULL;                 -- JSON com os códigos de recuperação (só o hash)

-- ---------- 2) DADOS DA EMPRESA ----------
ALTER TABLE business_profiles ADD COLUMN IF NOT EXISTS activity VARCHAR(160) NULL;
ALTER TABLE business_profiles ADD COLUMN IF NOT EXISTS tax_number VARCHAR(30) NULL;
ALTER TABLE business_profiles ADD COLUMN IF NOT EXISTS address VARCHAR(255) NULL;
ALTER TABLE business_profiles ADD COLUMN IF NOT EXISTS company_phone VARCHAR(40) NULL;
ALTER TABLE business_profiles ADD COLUMN IF NOT EXISTS company_email VARCHAR(190) NULL;
ALTER TABLE business_profiles ADD COLUMN IF NOT EXISTS website VARCHAR(190) NULL;
ALTER TABLE business_profiles ADD COLUMN IF NOT EXISTS logo_path VARCHAR(255) NULL;
ALTER TABLE business_profiles ADD COLUMN IF NOT EXISTS brand_color VARCHAR(7) NULL;   -- #rrggbb

-- ---------- 3) SESSÕES ATIVAS ----------
ALTER TABLE user_sessions ADD COLUMN IF NOT EXISTS session_hash CHAR(64) NULL;        -- SHA-256 do id de sessão (nunca o id)
ALTER TABLE user_sessions ADD COLUMN IF NOT EXISTS ip VARCHAR(45) NULL;
ALTER TABLE user_sessions ADD COLUMN IF NOT EXISTS user_agent VARCHAR(255) NULL;
ALTER TABLE user_sessions ADD COLUMN IF NOT EXISTS last_seen_at DATETIME NULL;
ALTER TABLE user_sessions ADD COLUMN IF NOT EXISTS revoked_at DATETIME NULL;
CREATE INDEX IF NOT EXISTS idx_sessions_hash ON user_sessions (session_hash);
CREATE INDEX IF NOT EXISTS idx_sessions_user ON user_sessions (user_id, revoked_at);

-- ---------- 4) CARTÕES: estado ----------
ALTER TABLE payment_cards ADD COLUMN IF NOT EXISTS status VARCHAR(12) NOT NULL DEFAULT 'active';   -- active | disabled

-- ---------- 5) NOTAS ----------
ALTER TABLE notes ADD COLUMN IF NOT EXISTS author_id INT NULL;                        -- quem escreveu (user_id é o negócio)
ALTER TABLE notes ADD COLUMN IF NOT EXISTS pinned TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE notes ADD COLUMN IF NOT EXISTS shared TINYINT(1) NOT NULL DEFAULT 0;      -- 0 = privada, 1 = partilhada com a equipa
ALTER TABLE notes ADD COLUMN IF NOT EXISTS tags LONGTEXT NULL;                        -- JSON: ["ideias","clientes"]

-- ---------- 6) TEMPO ATIVO ----------
CREATE TABLE IF NOT EXISTS work_shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,                        -- negócio
    employee_id INT NOT NULL,                      -- quem trabalhou
    started_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'active',  -- active | paused | closed
    source VARCHAR(10) NOT NULL DEFAULT 'clock',   -- clock (relógio) | manual (corrigido)
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_shift_emp (tenant_id, employee_id, started_at),
    INDEX idx_shift_open (employee_id, status),
    CONSTRAINT fk_shift_emp FOREIGN KEY (employee_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_shift_status CHECK (status IN ('active','paused','closed'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS work_pauses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shift_id INT NOT NULL,
    started_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    INDEX idx_pause_shift (shift_id),
    CONSTRAINT fk_pause_shift FOREIGN KEY (shift_id) REFERENCES work_shifts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------- 7) VENDAS E VIAGENS (alimentam os painéis por ramo) ----------
CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,                          -- negócio
    product_id INT NULL,
    product_name VARCHAR(160) NOT NULL,
    category VARCHAR(100) NULL,
    brand VARCHAR(100) NULL,
    size VARCHAR(20) NULL,
    color VARCHAR(40) NULL,
    quantity INT NOT NULL DEFAULT 1,
    amount DECIMAL(14,2) NOT NULL,                 -- total da linha
    sold_at DATETIME NOT NULL,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,         -- 1 = dado de exemplo (pode apagar-se de uma vez)
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sales_user_date (user_id, sold_at),
    INDEX idx_sales_product (user_id, product_name),
    CONSTRAINT fk_sales_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_sales_qty CHECK (quantity > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS trips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,                          -- negócio
    vehicle VARCHAR(80) NOT NULL DEFAULT 'Veículo',
    route VARCHAR(160) NOT NULL,                   -- ex.: Leiria–Coimbra
    distance_km DECIMAL(8,1) NOT NULL,
    fuel_liters DECIMAL(7,2) NOT NULL DEFAULT 0,
    fuel_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
    other_costs DECIMAL(10,2) NOT NULL DEFAULT 0,  -- portagens, manutenção...
    revenue DECIMAL(10,2) NOT NULL DEFAULT 0,
    trip_date DATE NOT NULL,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_trips_user_date (user_id, trip_date),
    CONSTRAINT fk_trips_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_trips_dist CHECK (distance_km > 0)
) ENGINE=InnoDB;

-- ---------- 8) GOOGLE CALENDAR ----------
CREATE TABLE IF NOT EXISTS google_connections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,                          -- a agenda é pessoal: uma ligação por utilizador
    google_email VARCHAR(190) NULL,
    access_token_enc TEXT NULL,                    -- CIFRADOS (ver includes/crypto.php); nunca em texto simples
    refresh_token_enc TEXT NULL,
    expires_at DATETIME NULL,
    scope VARCHAR(255) NULL,
    calendars LONGTEXT NULL,                       -- JSON: ids dos calendários a sincronizar
    sync_mode VARCHAR(10) NOT NULL DEFAULT 'manual',   -- manual | auto
    sync_token VARCHAR(255) NULL,
    last_sync_at DATETIME NULL,
    last_error VARCHAR(255) NULL,
    connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_google_user (user_id),
    CONSTRAINT fk_google_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS source VARCHAR(10) NOT NULL DEFAULT 'local';   -- local | google
ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS google_event_id VARCHAR(190) NULL;
ALTER TABLE calendar_events ADD COLUMN IF NOT EXISTS google_calendar_id VARCHAR(190) NULL;
CREATE INDEX IF NOT EXISTS idx_events_google ON calendar_events (user_id, google_event_id);

-- ---------- 9) AUDITORIA ----------
CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NULL,
    user_id INT NULL,
    action VARCHAR(60) NOT NULL,                   -- ex.: password_change, card_disabled, shift_edit
    detail TEXT NULL,                              -- JSON curto (nunca dados sensíveis)
    ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_tenant (tenant_id, created_at),
    INDEX idx_audit_user (user_id, created_at)
) ENGINE=InnoDB;

-- ===== Tempo ativo: correções de registos (v9) =====

ALTER TABLE work_shifts ADD COLUMN IF NOT EXISTS edited_by INT NULL;                  -- quem corrigiu (administrador ou gerente)
ALTER TABLE work_shifts ADD COLUMN IF NOT EXISTS edited_at DATETIME NULL;             -- quando
ALTER TABLE work_shifts ADD COLUMN IF NOT EXISTS edit_reason VARCHAR(255) NULL;       -- porquê (obrigatório ao corrigir)

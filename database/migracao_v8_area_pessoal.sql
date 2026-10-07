-- =========================================================================
-- Lumina — migração v8: Área pessoal, privacidade, segurança, tempo
-- ativo, notas, painéis por ramo e Google Calendar.
-- Seguro de repetir: não apaga dados. Testado em MariaDB (XAMPP).
--
-- O QUE ACRESCENTA (por zonas):
--   1. users              -> foto, preferências (ocultar valores...), 2 passos (TOTP)
--   2. business_profiles  -> dados da empresa (NIF, morada, logo, cor principal)
--   3. user_sessions      -> sessões ativas (dispositivo, IP, última atividade)
--   4. payment_cards      -> estado do cartão (ativo / desativado)
--   5. notes              -> fixar, partilhar com a equipa, etiquetas
--   6. work_*             -> registo de tempo ativo dos funcionários
--   7. sales / trips      -> vendas (restaurante, loja) e viagens (motorista)
--   8. google_connections -> ligação ao Google Calendar (tokens cifrados)
--   9. audit_log          -> registo de auditoria das ações sensíveis
-- =========================================================================
USE gestao_facil;

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

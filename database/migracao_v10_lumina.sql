-- Lumina — migração v10: registo de versões, tokens de conta (recuperar palavra-passe, verificar email, convites),
-- contas recorrentes, fecho do dia, anexos (recibos), registo de consentimentos e respostas do questionário de arranque.
-- Seguro de repetir: não apaga dados. (O esquema novo vive SÓ nas migrações; o instalador aplica-as sempre a seguir ao ficheiro base.)
USE gestao_facil;

-- 1) que versões do esquema já foram aplicadas
CREATE TABLE IF NOT EXISTS schema_migrations (
  version     VARCHAR(40) NOT NULL PRIMARY KEY,
  applied_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO schema_migrations (version) VALUES ('v1'),('v2'),('v3'),('v4'),('v5'),('v6'),('v7'),('v8'),('v9'),('v10');

-- 2) email confirmado (não bloqueia o uso; só mostra um aviso até ser confirmado)
ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL;

-- 3) tokens de uso único enviados por email. Guardamos só o resumo (hash): quem lê a base de dados não consegue usar o link.
CREATE TABLE IF NOT EXISTS account_tokens (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NOT NULL,
  purpose     ENUM('reset','verify','invite') NOT NULL,
  token_hash  CHAR(64) NOT NULL,
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  ip          VARCHAR(45) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_token_hash (token_hash),
  KEY idx_token_user (user_id, purpose),
  CONSTRAINT fk_token_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) contas e despesas recorrentes (renda, salários, seguros, subscrições...). Geram contas a pagar/receber pendentes.
CREATE TABLE IF NOT EXISTS recurring_items (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT NOT NULL,
  direction      ENUM('payable','receivable') NOT NULL,
  title          VARCHAR(160) NOT NULL,
  counterparty   VARCHAR(160) NULL,
  amount         DECIMAL(12,2) NOT NULL,
  frequency      ENUM('weekly','monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
  start_date     DATE NOT NULL,
  end_date       DATE NULL,
  next_due       DATE NOT NULL,
  active         TINYINT(1) NOT NULL DEFAULT 1,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_recurring_user (user_id, active, next_due),
  CONSTRAINT fk_recurring_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE financial_documents ADD COLUMN IF NOT EXISTS recurring_id INT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_bill_recurring ON financial_documents (recurring_id, due_date);   -- nunca gera duas vezes a mesma conta

-- 5) fecho do dia: o que devia estar na caixa vs. o que foi contado
CREATE TABLE IF NOT EXISTS day_closings (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL,
  closed_by     INT NULL,
  day           DATE NOT NULL,
  income_total  DECIMAL(12,2) NOT NULL DEFAULT 0,
  expense_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  counted_cash  DECIMAL(12,2) NULL,
  difference    DECIMAL(12,2) NULL,
  note          VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_closing_day (user_id, day),
  CONSTRAINT fk_closing_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6) anexos (foto ou PDF do recibo/fatura ligado a um movimento, conta, cliente ou produto)
CREATE TABLE IF NOT EXISTS attachments (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL,
  uploaded_by   INT NULL,
  entity        ENUM('transaction','bill','client','product') NOT NULL,
  entity_id     INT NOT NULL,
  path          VARCHAR(255) NOT NULL,
  original_name VARCHAR(190) NOT NULL,
  mime          VARCHAR(80)  NOT NULL,
  size          INT NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_attach_entity (user_id, entity, entity_id),
  CONSTRAINT fk_attach_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7) registo de consentimentos (RGPD): quando foi dado e quando foi retirado
CREATE TABLE IF NOT EXISTS consent_log (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NOT NULL,
  kind        VARCHAR(30) NOT NULL,          -- 'geolocation', 'google_calendar', 'ai_assistant'
  granted     TINYINT(1) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_consent_user (user_id, kind),
  CONSTRAINT fk_consent_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8) respostas do questionário de arranque (os módulos escolhidos ficam em enabled_modules, que já existia)
ALTER TABLE business_profiles ADD COLUMN IF NOT EXISTS onboarding LONGTEXT NULL;
ALTER TABLE business_profiles ADD COLUMN IF NOT EXISTS onboarding_done_at DATETIME NULL;

-- 9) índice para os gráficos e totais calculados no servidor
CREATE INDEX IF NOT EXISTS idx_tx_user_status_date ON transactions (user_id, status, occurred_at);

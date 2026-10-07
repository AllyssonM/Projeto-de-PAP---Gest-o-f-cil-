-- Lumina — migração v13: gestão de funcionários (tarefas, metas, mensagens, observações do líder, avisos e registo de entradas).
-- Seguro de repetir: não apaga dados. Todas as tabelas usam a mesma regra de texto das originais (utf8mb4_unicode_ci).
USE gestao_facil;

-- pausa marcada pelo próprio funcionário ('auto' = o estado sai da atividade real; 'pause' = em pausa)
ALTER TABLE users ADD COLUMN IF NOT EXISTS presence VARCHAR(10) NOT NULL DEFAULT 'auto';

-- quem entrou e quando (para "entradas recentes" e o histórico de cada funcionário); guarda 90 dias
CREATE TABLE IF NOT EXISTS login_log (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  user_id   INT NOT NULL,
  logged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_login_tenant (tenant_id, logged_at),
  KEY idx_login_user (user_id, logged_at),
  CONSTRAINT fk_login_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- tarefas que o líder atribui
CREATE TABLE IF NOT EXISTS employee_tasks (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id    INT NOT NULL,
  employee_id  INT NOT NULL,
  title        VARCHAR(200) NOT NULL,
  detail       VARCHAR(1000) NULL,
  due_date     DATE NULL,
  status       VARCHAR(10) NOT NULL DEFAULT 'pending',
  created_by   INT NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  KEY idx_task_emp (tenant_id, employee_id, status),
  CONSTRAINT fk_task_emp FOREIGN KEY (employee_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- metas mensais: nº de vendas, valor das vendas ou tarefas concluídas
CREATE TABLE IF NOT EXISTS employee_goals (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id   INT NOT NULL,
  employee_id INT NOT NULL,
  kind        VARCHAR(12) NOT NULL,
  target      DECIMAL(14,2) NOT NULL,
  month       CHAR(7) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_goal (employee_id, kind, month),
  KEY idx_goal_tenant (tenant_id, month),
  CONSTRAINT fk_goal_emp FOREIGN KEY (employee_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mensagens entre o líder e cada funcionário
CREATE TABLE IF NOT EXISTS team_messages (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id  INT NOT NULL,
  from_user  INT NOT NULL,
  to_user    INT NOT NULL,
  kind       VARCHAR(12) NOT NULL DEFAULT 'general',
  body       VARCHAR(1000) NOT NULL,
  read_at    DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_msg_to (tenant_id, to_user, read_at),
  KEY idx_msg_pair (from_user, to_user, id),
  CONSTRAINT fk_msg_from FOREIGN KEY (from_user) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_to FOREIGN KEY (to_user) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- observações do líder sobre um funcionário (shared = 1: o funcionário também as vê e recebe um aviso)
CREATE TABLE IF NOT EXISTS leader_notes (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id   INT NOT NULL,
  employee_id INT NOT NULL,
  author_id   INT NOT NULL,
  body        VARCHAR(1000) NOT NULL,
  shared      TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lnote_emp (tenant_id, employee_id, id),
  CONSTRAINT fk_lnote_emp FOREIGN KEY (employee_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- avisos para cada pessoa (entrada de funcionário, nova mensagem, tarefa, meta, comentário, anúncio)
CREATE TABLE IF NOT EXISTS notifications (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id  INT NOT NULL,
  user_id    INT NOT NULL,
  type       VARCHAR(16) NOT NULL,
  title      VARCHAR(160) NOT NULL,
  body       VARCHAR(300) NULL,
  actor_id   INT NULL,
  section    VARCHAR(20) NULL,
  read_at    DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notif_user (user_id, read_at, id),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- consultas por funcionário nas vendas (quem vendeu o quê)
CREATE INDEX IF NOT EXISTS idx_sales_seller ON sales (user_id, created_by, sold_at);
INSERT IGNORE INTO schema_migrations (version) VALUES ('v13');

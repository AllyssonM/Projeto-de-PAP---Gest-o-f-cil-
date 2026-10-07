-- =========================================================
-- ASSISTENTE DE IA (conversas, mensagens e registo de auditoria)
-- Seguro para executar mais do que uma vez (IF NOT EXISTS). Testado em MariaDB (XAMPP).
-- =========================================================
USE gestao_facil;

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

-- bases criadas com a versão anterior (sem equipa): acrescenta a coluna em falta
ALTER TABLE ai_audit_log ADD COLUMN IF NOT EXISTS tenant_id INT NULL AFTER user_id;

-- Lumina - migração do calendário
-- Para bases de dados já existentes. É seguro repetir: não altera dados.
CREATE DATABASE IF NOT EXISTS gestao_facil CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE gestao_facil;

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

-- Lumina — migração v6: limite de tentativas de login (segurança)
-- Seguro de repetir: não apaga dados. Testado em MariaDB (XAMPP).
--
-- Guarda só as tentativas FALHADAS (email + IP + hora) para travar tentativas de adivinhar
-- palavras-passe. As linhas apagam-se sozinhas depois de um login certo e passado o período.

USE gestao_facil;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_email (email, created_at),
    INDEX idx_login_ip (ip, created_at)
) ENGINE=InnoDB;

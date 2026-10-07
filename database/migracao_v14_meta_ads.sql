-- Lumina — migração v14: integração com a Meta Ads (Facebook/Instagram)
-- Seguro de repetir; não apaga dados. Os tokens ficam CIFRADOS (includes/crypto.php) e nunca vão para o navegador.
USE gestao_facil;

CREATE TABLE IF NOT EXISTS meta_connections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,                          -- o dono do negócio (uma ligação por negócio)
    fb_user_id VARCHAR(40) NULL,
    fb_user_name VARCHAR(190) NULL,
    access_token_enc TEXT NULL,                    -- CIFRADO
    expires_at DATETIME NULL,                      -- tokens de longa duração valem ~60 dias; depois é preciso ligar outra vez
    scope VARCHAR(255) NULL,
    ad_account_id VARCHAR(40) NULL,                -- conta de anúncios escolhida (sem o prefixo "act_")
    ad_account_name VARCHAR(190) NULL,
    currency VARCHAR(8) NULL,
    date_preset VARCHAR(20) NOT NULL DEFAULT 'last_30d',
    last_sync_at DATETIME NULL,
    last_error VARCHAR(255) NULL,
    connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meta_user (user_id),
    CONSTRAINT fk_meta_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Última leitura dos dados (JSON) para abrir a página de imediato; "Atualizar" volta a pedir à Meta.
CREATE TABLE IF NOT EXISTS meta_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ad_account_id VARCHAR(40) NOT NULL,
    date_preset VARCHAR(20) NOT NULL,
    payload LONGTEXT NOT NULL,
    fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meta_snap (user_id, ad_account_id, date_preset),
    CONSTRAINT fk_meta_snap_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

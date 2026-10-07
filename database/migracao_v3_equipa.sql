-- Lumina — migração v3: equipa (dono × funcionários)
-- Seguro de repetir: não apaga dados. Testado em MariaDB 10.4 (XAMPP).
--
-- Um funcionário é um utilizador com role = 'employee' e owner_id = id do dono.
-- Os dados do negócio continuam guardados com user_id = id do dono;
-- o funcionário trabalha sobre eles conforme as permissões que o dono lhe dá.

USE gestao_facil;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS owner_id INT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS permissions LONGTEXT NULL AFTER role,
    ADD COLUMN IF NOT EXISTS job_title VARCHAR(100) NULL AFTER permissions,
    ADD COLUMN IF NOT EXISTS department VARCHAR(100) NULL AFTER job_title,
    ADD COLUMN IF NOT EXISTS phone VARCHAR(40) NULL AFTER department,
    ADD COLUMN IF NOT EXISTS hired_at DATE NULL AFTER phone,
    ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER hired_at,
    ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL AFTER must_change_password;

-- contas antigas (role 'user') passam a donos
UPDATE users SET role = 'owner' WHERE role NOT IN ('owner', 'employee');
ALTER TABLE users MODIFY role VARCHAR(30) NOT NULL DEFAULT 'owner';

ALTER TABLE users DROP FOREIGN KEY IF EXISTS fk_user_owner;
ALTER TABLE users ADD CONSTRAINT fk_user_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_users_owner (owner_id, status);

ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_role;
ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_status;
ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_permissions;
ALTER TABLE users
    ADD CONSTRAINT chk_users_role CHECK (role IN ('owner', 'employee') AND (role = 'owner') = (owner_id IS NULL)),
    ADD CONSTRAINT chk_users_status CHECK (status IN ('active', 'inactive')),
    ADD CONSTRAINT chk_users_permissions CHECK (permissions IS NULL OR JSON_VALID(permissions));

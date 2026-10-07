-- Lumina — migração v9: quem corrigiu um registo de tempo, quando e porquê.
-- Seguro de repetir: não apaga dados.
-- (As tabelas work_shifts e work_pauses vêm da migração v8.)
USE gestao_facil;

ALTER TABLE work_shifts ADD COLUMN IF NOT EXISTS edited_by INT NULL;                  -- quem corrigiu (administrador ou gerente)
ALTER TABLE work_shifts ADD COLUMN IF NOT EXISTS edited_at DATETIME NULL;             -- quando
ALTER TABLE work_shifts ADD COLUMN IF NOT EXISTS edit_reason VARCHAR(255) NULL;       -- porquê (obrigatório ao corrigir)

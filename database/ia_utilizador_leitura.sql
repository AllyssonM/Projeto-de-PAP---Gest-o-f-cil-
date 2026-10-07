-- =========================================================
-- UTILIZADOR MYSQL SÓ DE LEITURA PARA O ASSISTENTE DE IA (recomendado)
--
-- Camada extra de proteção ao nível da base de dados: mesmo que houvesse um
-- erro no código, esta conta NÃO consegue escrever, apagar nem alterar nada,
-- e NÃO tem acesso às tabelas `users` (palavras-passe), nem às do próprio chat.
--
-- 1) Troca TROCA_ESTA_PALAVRA_PASSE por uma palavra-passe tua.
-- 2) Importa este ficheiro (phpMyAdmin > Importar).
-- 3) Copia o utilizador e a palavra-passe para config/ai.php (db_user / db_pass).
-- =========================================================
CREATE USER IF NOT EXISTS 'gf_ia_leitura'@'localhost'  IDENTIFIED BY 'TROCA_ESTA_PALAVRA_PASSE';
CREATE USER IF NOT EXISTS 'gf_ia_leitura'@'127.0.0.1'  IDENTIFIED BY 'TROCA_ESTA_PALAVRA_PASSE';

GRANT SELECT ON gestao_facil.accounts            TO 'gf_ia_leitura'@'localhost', 'gf_ia_leitura'@'127.0.0.1';
GRANT SELECT ON gestao_facil.clients             TO 'gf_ia_leitura'@'localhost', 'gf_ia_leitura'@'127.0.0.1';
GRANT SELECT ON gestao_facil.products            TO 'gf_ia_leitura'@'localhost', 'gf_ia_leitura'@'127.0.0.1';
GRANT SELECT ON gestao_facil.transactions        TO 'gf_ia_leitura'@'localhost', 'gf_ia_leitura'@'127.0.0.1';
GRANT SELECT ON gestao_facil.financial_documents TO 'gf_ia_leitura'@'localhost', 'gf_ia_leitura'@'127.0.0.1';
GRANT SELECT ON gestao_facil.stock_movements     TO 'gf_ia_leitura'@'localhost', 'gf_ia_leitura'@'127.0.0.1';
GRANT SELECT ON gestao_facil.calendar_events     TO 'gf_ia_leitura'@'localhost', 'gf_ia_leitura'@'127.0.0.1';
FLUSH PRIVILEGES;

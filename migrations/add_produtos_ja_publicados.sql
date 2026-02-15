-- Tabela para evitar republicar o mesmo produto no site e no WhatsApp (automação ML, Shopee, etc.)
-- link_origem = URL original do produto (ex.: ML) antes do createLink; usado para deduplicação
--
-- Execute uma vez no banco (phpMyAdmin, MySQL, etc.):
--   mysql -u USUARIO -p NOME_DB < migrations/add_produtos_ja_publicados.sql
--
CREATE TABLE IF NOT EXISTS produtos_ja_publicados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    link_origem VARCHAR(600) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_link_origem (link_origem(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

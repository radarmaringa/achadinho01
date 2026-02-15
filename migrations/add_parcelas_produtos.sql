-- Adiciona parcelas e preco_parcela em produtos (preço total + "em 12x de R$ 46,43")
-- Execute uma vez no MySQL: source migrations/add_parcelas_produtos.sql

ALTER TABLE produtos ADD COLUMN parcelas INT NULL COMMENT 'Número de parcelas (ex: 12)';
ALTER TABLE produtos ADD COLUMN preco_parcela DECIMAL(10,2) NULL COMMENT 'Valor de cada parcela (ex: 46,43)';

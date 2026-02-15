<?php
// Arquivo temporário para testar banco de dados
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

echo "<h1>Teste de Banco de Dados</h1>";

try {
    $pdo = getDB();
    echo "<p style='color: green;'>✓ Conexão com banco de dados OK</p>";
    
    // Verificar se tabela existe
    $stmt = $pdo->query("SHOW TABLES LIKE 'configuracoes'");
    if ($stmt->rowCount() > 0) {
        echo "<p style='color: green;'>✓ Tabela 'configuracoes' existe</p>";
    } else {
        echo "<p style='color: red;'>✗ Tabela 'configuracoes' NÃO existe! Execute o database.sql</p>";
        exit;
    }
    
    // Testar inserção
    $testKey = 'teste_' . time();
    $testValue = 'valor_teste';
    
    echo "<h2>Testando setConfig:</h2>";
    $result = setConfig($testKey, $testValue);
    
    if ($result) {
        echo "<p style='color: green;'>✓ setConfig retornou TRUE</p>";
    } else {
        echo "<p style='color: red;'>✗ setConfig retornou FALSE</p>";
    }
    
    // Verificar se foi salvo
    $valorRecuperado = getConfig($testKey);
    if ($valorRecuperado === $testValue) {
        echo "<p style='color: green;'>✓ Valor foi salvo e recuperado corretamente: '{$valorRecuperado}'</p>";
    } else {
        echo "<p style='color: red;'>✗ Valor não foi salvo corretamente. Esperado: '{$testValue}', Obtido: '{$valorRecuperado}'</p>";
    }
    
    // Limpar teste
    $pdo->prepare("DELETE FROM configuracoes WHERE chave = ?")->execute([$testKey]);
    
    // Listar todas as configurações atuais
    echo "<h2>Configurações atuais no banco:</h2>";
    $stmt = $pdo->query("SELECT chave, valor FROM configuracoes");
    $configs = $stmt->fetchAll();
    
    if (empty($configs)) {
        echo "<p style='color: orange;'>⚠ Nenhuma configuração encontrada no banco</p>";
    } else {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Chave</th><th>Valor</th></tr>";
        foreach ($configs as $config) {
            $valor = strlen($config['valor']) > 50 ? substr($config['valor'], 0, 50) . '...' : $config['valor'];
            echo "<tr><td>{$config['chave']}</td><td>" . htmlspecialchars($valor) . "</td></tr>";
        }
        echo "</table>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Erro: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>

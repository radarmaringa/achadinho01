<?php
/**
 * Ponto de entrada para o CRON da automação Shopee.
 * URL: /cron/rodar-automacao-shopee.php?token=SEU_TOKEN
 * Se shopee_cron_token estiver preenchido nas configs, ?token= é obrigatório (apenas via web).
 */
$wantJson = false;
if (php_sapi_name() !== 'cli') {
    $wantJson = (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) || (isset($_GET['format']) && $_GET['format'] === 'json');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

$tokenConfig = getConfig('shopee_cron_token', '');
if ($tokenConfig !== '' && php_sapi_name() !== 'cli') {
    $token = isset($_GET['token']) ? (string) $_GET['token'] : '';
    if ($token !== $tokenConfig) {
        if ($wantJson) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Token inválido.', 'details' => [], 'errors' => []]);
        } else {
            http_response_code(403);
            echo 'ERRO: Token inválido.';
        }
        exit;
    }
}

require_once __DIR__ . '/../config/automacao-shopee.php';
$result = runAutomacaoShopee(false);

if (!empty($wantJson)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} else {
    echo $result['success'] ? 'OK' : ('ERRO: ' . ($result['message'] ?? 'Falha na automação.'));
}

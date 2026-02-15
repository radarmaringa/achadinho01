<?php
/**
 * Validação LOCAL de licença - AfiliadosPRO
 * Inseriu a chave uma vez (válida para o domínio e dentro do prazo) = sistema liberado.
 * Nenhuma requisição a servidor externo.
 */

if (!function_exists('lp_ap_is_json_request')) {
    function lp_ap_is_json_request() {
        $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
        $xhr    = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
        $uri    = strtolower($_SERVER['REQUEST_URI'] ?? '');
        return (str_contains($accept, 'application/json') || $xhr === 'xmlhttprequest' || str_contains($uri, '/api/'));
    }
}

if (!function_exists('bloquearAfiliadosPRO')) {
    function bloquearAfiliadosPRO($mensagem, $formError = '', $asJson = false) {
        if (ob_get_level() > 0) {
            while (ob_get_level() > 0) { @ob_end_clean(); }
        }
        http_response_code(403);
        header('Cache-Control: no-store, no-cache, must-revalidate');

        if ($asJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'bloqueado', 'mensagem' => $mensagem], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        $m   = htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8');
        $err = $formError !== '' ? htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') : '';
        ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AfiliadosPRO - Licença</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; background: #0b1020; color: #e5e7eb; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .c { max-width: 520px; width: 100%; background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.12); border-radius: 16px; padding: 32px; box-shadow: 0 18px 60px rgba(0,0,0,.4); }
        h1 { margin: 0 0 8px; font-size: 22px; display: flex; align-items: center; gap: 10px; }
        p { margin: 0 0 16px; color: rgba(229,231,235,.85); line-height: 1.5; }
        label { display: block; margin: 14px 0 6px; color: rgba(229,231,235,.8); font-size: 14px; }
        input { width: 100%; padding: 12px 14px; border-radius: 10px; border: 1px solid rgba(34,211,238,.4); background: rgba(15,23,42,.6); color: #e5e7eb; font-size: 15px; outline: none; }
        input:focus { border-color: rgba(34,211,238,.7); box-shadow: 0 0 0 3px rgba(34,211,238,.15); }
        button { cursor: pointer; border: 0; padding: 12px 20px; border-radius: 10px; background: linear-gradient(135deg, #7c3aed, #22d3ee); color: #fff; font-weight: 700; font-size: 15px; }
        button:hover { opacity: .95; }
        .row { margin-top: 14px; }
        .err { margin-top: 14px; border: 1px solid rgba(239,68,68,.4); background: rgba(239,68,68,.12); padding: 12px 14px; border-radius: 10px; color: #fecaca; font-size: 14px; }
    </style>
</head>
<body>
    <div class="c">
        <h1>🔒 AfiliadosPRO</h1>
        <p><?php echo $m; ?></p>
        <form method="POST">
            <label>Chave de licença</label>
            <input name="lp_license_key" placeholder="Cole a chave gerada para este domínio" autocomplete="off" required>
            <div class="row">
                <button type="submit">Ativar</button>
            </div>
        </form>
        <?php if ($err !== '') { echo '<div class="err">' . $err . '</div>'; } ?>
    </div>
</body>
</html>
        <?php
        exit;
    }
}

if (!function_exists('validarLicencaAfiliadosPRO')) {
    function validarLicencaAfiliadosPRO() {
        // Se já está ativa, não validar novamente
        if (defined('LICENCA_ATIVA') && LICENCA_ATIVA) {
            return;
        }
        require_once dirname(__DIR__) . '/config/licenca.php';

        $dominio = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        $dominio = lp_normalizar_dominio($dominio);
        if ($dominio === '') {
            bloquearAfiliadosPRO('Não foi possível identificar o domínio.', '', lp_ap_is_json_request());
            return;
        }

        $storageDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }
        $keyFile = $storageDir . DIRECTORY_SEPARATOR . 'licence.key';

        // Ativação via formulário (POST)
        if (!lp_ap_is_json_request() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['lp_license_key'])) {
            $key = trim((string) $_POST['lp_license_key']);
            if ($key === '') {
                bloquearAfiliadosPRO('A chave é obrigatória.', 'Informe a chave.');
                return;
            }
            if (lp_verificar_chave_licenca($key, $dominio)) {
                // Salvar a chave e garantir que foi salva
                $saved = @file_put_contents($keyFile, $key);
                if ($saved === false) {
                    bloquearAfiliadosPRO('Erro ao salvar a licença. Verifique permissões da pasta storage.', 'Erro de permissão.');
                    return;
                }
                // Definir flag imediatamente após salvar
                define('LICENCA_ATIVA', true);
                $url = $_SERVER['REQUEST_URI'] ?? '/';
                header('Location: ' . $url);
                exit;
            }
            bloquearAfiliadosPRO('Chave inválida, expirada ou não é para este domínio.', 'Verifique o domínio e a validade. Gere uma nova chave no gerador.');
            return;
        }

        // Ler chave salva
        $licenca = is_file($keyFile) ? trim((string) @file_get_contents($keyFile)) : '';
        if ($licenca === '') {
            bloquearAfiliadosPRO('Licença não configurada. Insira a chave gerada para este domínio.', '', lp_ap_is_json_request());
            return;
        }

        if (!lp_verificar_chave_licenca($licenca, $dominio)) {
            // Só apagar se realmente inválida (expirada ou domínio errado)
            // Não apagar se for apenas um erro temporário de leitura
            @unlink($keyFile);
            bloquearAfiliadosPRO('Licença expirada ou inválida para este domínio.', 'Gere uma nova chave no gerador com o domínio correto.');
            return;
        }
        // Licença válida - definir flag para não pedir novamente nesta requisição
        if (!defined('LICENCA_ATIVA')) {
            define('LICENCA_ATIVA', true);
        }
    }
}

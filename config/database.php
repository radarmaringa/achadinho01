<?php
// Bootstrap da licença (um único ponto: quem carrega o DB passa por aqui)
if (!defined('LICENCA_ATIVA')) {
    $__lp_root = dirname(__DIR__);
    if (is_file($__lp_root . '/loader_licenca.php')) {
        require_once $__lp_root . '/loader_licenca.php';
    } elseif (is_file($__lp_root . '/helpers/licenca_local_helper.php')) {
        require_once $__lp_root . '/helpers/licenca_local_helper.php';
        validarLicencaAfiliadosPRO();
        // Se chegou aqui e ainda não definiu LICENCA_ATIVA, a validação passou mas não definiu
        if (!defined('LICENCA_ATIVA')) {
            define('LICENCA_ATIVA', true);
        }
    } else {
        define('LICENCA_ATIVA', false);
    }
}
// Travamento: o sistema só funciona com licença ativa
if (!defined('LICENCA_ATIVA') || !LICENCA_ATIVA) {
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "Licenca nao ativa.\n");
        exit(1);
    }
    http_response_code(403);
    header('Cache-Control: no-store, no-cache');
    exit('Acesso nao autorizado.');
}
// Configurações do banco de dados
define('DB_HOST', 'localhost');
define('DB_NAME', 'u271870832_ofertas');
define('DB_USER', 'u271870832_ofertas');
define('DB_PASS', '3tAzd08vJx');
define('DB_CHARSET', 'utf8mb4');

// Função para conectar ao banco de dados
function getDB() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die("Erro na conexão: " . $e->getMessage());
        }
    }
    
    return $pdo;
}

// Função para iniciar sessão
function startSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// Função para verificar se está logado
function isLoggedIn() {
    startSession();
    return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
}

// Função para fazer logout
function logout() {
    startSession();
    $_SESSION = [];
    session_destroy();
}

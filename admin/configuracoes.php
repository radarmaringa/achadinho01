<?php
// Habilitar exibição de erros temporariamente para debug
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Processar formulário ANTES de incluir o header (para poder redirecionar)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

// Verificar login
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$pdo = getDB();

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug: verificar se POST está chegando
    error_log("=== POST RECEBIDO EM CONFIGURACOES ===");
    error_log("POST data: " . print_r($_POST, true));
    
    $erros = [];
    $debug = [];
    $debug[] = 'POST recebido';
    $debug[] = 'categorias_topbar: ' . ($_POST['categorias_topbar'] ?? 'não definido');
    $debug[] = 'footer_email: ' . ($_POST['footer_email'] ?? 'não definido');
    $debug[] = 'footer_instagram: ' . ($_POST['footer_instagram'] ?? 'não definido');
    $debug[] = 'footer_facebook: ' . ($_POST['footer_facebook'] ?? 'não definido');
    $debug[] = 'banner_type: ' . ($_POST['banner_type'] ?? 'não definido');
    
    // Logo
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $logo = uploadImagem($_FILES['logo'], 'uploads/');
        if ($logo) {
            // Deletar logo antigo se houver
            $logoAntigo = getConfig('logo');
            if (!empty($logoAntigo)) {
                deleteImagem($logoAntigo);
            }
            setConfig('logo', $logo);
        }
    }
    
    // Categorias topbar (permite vazio)
    $categoriasTopbar = isset($_POST['categorias_topbar']) ? $_POST['categorias_topbar'] : '';
    $debug[] = 'Salvando categorias_topbar: "' . substr($categoriasTopbar, 0, 50) . '"';
    setConfig('categorias_topbar', $categoriasTopbar);
    
    // Carregar banners atuais primeiro
    $bannersAtuais = json_decode(getConfig('banners', '[]'), true) ?: [];
    $bannerDeletado = false;
    
    // Deletar banner ANTES de processar upload (prioridade)
    if (isset($_POST['delete_banner']) && $_POST['delete_banner'] !== '') {
        $index = (int)$_POST['delete_banner'];
        
        if ($index >= 0 && isset($bannersAtuais[$index])) {
            // Deletar arquivo físico
            deleteImagem($bannersAtuais[$index]);
            // Remover do array
            unset($bannersAtuais[$index]);
            // Reindexar array para evitar índices quebrados
            $bannersAtuais = array_values(array_filter($bannersAtuais));
            
            setConfig('banners', json_encode($bannersAtuais));
            $bannerDeletado = true;
        }
    }
    
    // Tipo de banner (imagens ou JSON padrão)
    $bannerType = isset($_POST['banner_type']) ? $_POST['banner_type'] : 'images';
    if (empty($bannerType)) {
        $bannerType = 'images';
    }
    $debug[] = 'Salvando banner_type: ' . $bannerType;
    setConfig('banner_type', $bannerType);
    
    // Banners JSON padrão
    if (isset($_POST['banners_json']) && $bannerType === 'default') {
        $bannersJsonValue = $_POST['banners_json'];
        $debug[] = 'Salvando banners_json (tamanho: ' . strlen($bannersJsonValue) . ' chars)';
        setConfig('banners_json', $bannersJsonValue);
    }
    
    // Banners - Upload de novas imagens (apenas se tipo for imagens)
    if ($bannerType === 'images' && isset($_FILES['banner_images']) && !empty($_FILES['banner_images']['name'][0])) {
        $bannersAtuais = json_decode(getConfig('banners', '[]'), true) ?: [];
        
        foreach ($_FILES['banner_images']['name'] as $key => $name) {
            if ($_FILES['banner_images']['error'][$key] === UPLOAD_ERR_OK) {
                $file = [
                    'name' => $_FILES['banner_images']['name'][$key],
                    'type' => $_FILES['banner_images']['type'][$key],
                    'tmp_name' => $_FILES['banner_images']['tmp_name'][$key],
                    'error' => $_FILES['banner_images']['error'][$key],
                    'size' => $_FILES['banner_images']['size'][$key]
                ];
                
                $bannerImage = uploadImagem($file, 'uploads/banners/');
                if ($bannerImage) {
                    $bannersAtuais[] = $bannerImage;
                }
            }
        }
        
        // Remover valores vazios e garantir que são strings
        $bannersAtuais = array_values(array_filter(array_map('trim', $bannersAtuais)));
        
        setConfig('banners', json_encode($bannersAtuais));
    }
    
    // Footer (permite vazios)
    $footerEmail = isset($_POST['footer_email']) ? $_POST['footer_email'] : '';
    $footerInstagram = isset($_POST['footer_instagram']) ? $_POST['footer_instagram'] : '';
    $footerFacebook = isset($_POST['footer_facebook']) ? $_POST['footer_facebook'] : '';
    
    $debug[] = 'Salvando footer_email: "' . $footerEmail . '"';
    $debug[] = 'Salvando footer_instagram: "' . $footerInstagram . '"';
    $debug[] = 'Salvando footer_facebook: "' . $footerFacebook . '"';
    
    $result1 = setConfig('footer_email', $footerEmail);
    $result2 = setConfig('footer_instagram', $footerInstagram);
    $result3 = setConfig('footer_facebook', $footerFacebook);
    
    // Link do Grupo do WhatsApp (topbar)
    $whatsappGrupoUrl = isset($_POST['whatsapp_grupo_url']) ? trim($_POST['whatsapp_grupo_url']) : '';
    setConfig('whatsapp_grupo_url', $whatsappGrupoUrl);
    
    $debug[] = 'Resultado footer_email: ' . ($result1 ? 'OK' : 'ERRO');
    $debug[] = 'Resultado footer_instagram: ' . ($result2 ? 'OK' : 'ERRO');
    $debug[] = 'Resultado footer_facebook: ' . ($result3 ? 'OK' : 'ERRO');
    
    // Verificar se houve erro no salvamento
    if (!$result1 || !$result2 || !$result3) {
        $erros[] = 'Erro ao salvar algumas configurações. Verifique os erros acima.';
    }
    
    // Debug: verificar resultados
    $debug[] = 'Total de erros: ' . count($erros);
    $debug[] = 'bannerDeletado: ' . ($bannerDeletado ? 'sim' : 'não');
    
    // Redirecionar para evitar reenvio do formulário e mostrar mensagem
    // Se deletou banner, mantém mensagem específica, senão usa genérica
    $redirectMsg = $bannerDeletado ? 'delete=1' : 'success=1';
    
    // Verificar se há erros antes de redirecionar
    if (!empty($erros)) {
        $redirectMsg = 'error=' . urlencode(implode(', ', $erros));
    }
    
    // Redirecionar
    header('Location: configuracoes.php?' . $redirectMsg);
    exit;
}

// Verificar mensagens via GET
$message = '';
$messageType = '';

if (isset($_GET['success']) && $_GET['success'] == '1') {
    $message = 'Configurações salvas com sucesso!';
    $messageType = 'success';
}
if (isset($_GET['delete']) && $_GET['delete'] == '1') {
    $message = 'Banner deletado com sucesso!';
    $messageType = 'success';
}
if (isset($_GET['error'])) {
    $message = 'Erro ao salvar: ' . htmlspecialchars($_GET['error']);
    $messageType = 'error';
}

// Agora incluir o header (após processamento do POST para evitar output antes do redirect)
$pageTitle = 'Configurações';
require_once __DIR__ . '/includes/header.php';

// Carregar configurações
$logo = getConfig('logo');
$categoriasTopbar = getConfig('categorias_topbar', 'Eletrônicos|Celulares|Games|Computadores|Cozinha');
$bannerType = getConfig('banner_type', 'images');
$bannersJson = getConfig('banners', '[]');
$banners = json_decode($bannersJson, true) ?: [];
$bannersDefaultJson = getConfig('banners_json', '[]');
if (empty($bannersDefaultJson) || $bannersDefaultJson === '[]') {
    // Banners padrão se não houver configuração
    $bannersDefaultJson = json_encode([
        ['id' => 1, 'title' => 'Até 60% OFF', 'subtitle' => 'em Eletrônicos', 'description' => 'Ofertas imperdíveis para você', 'bgGradient' => 'from-[hsl(var(--primary))] via-orange-500 to-orange-600'],
        ['id' => 2, 'title' => 'Super Ofertas', 'subtitle' => 'em Celulares', 'description' => 'Os melhores smartphones com desconto', 'bgGradient' => 'from-orange-600 via-[hsl(var(--primary))] to-red-500'],
        ['id' => 3, 'title' => 'Black Friday', 'subtitle' => 'Todo Dia', 'description' => 'Preços baixos o ano inteiro', 'bgGradient' => 'from-red-500 via-orange-500 to-[hsl(var(--primary))]'],
    ]);
}
$footerEmail = getConfig('footer_email', '');
$footerInstagram = getConfig('footer_instagram', '');
$footerFacebook = getConfig('footer_facebook', '');
$whatsappGrupoUrl = getConfig('whatsapp_grupo_url', '');
?>
        <main class="flex-1 overflow-y-auto p-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-8">Configurações</h1>
            
            <?php if ($message): ?>
            <div class="mb-4 p-4 rounded <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" action="" enctype="multipart/form-data" class="space-y-8" id="configForm">
                <!-- Logo -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Logo do Site</h2>
                    
                    <?php if (!empty($logo)): ?>
                    <div class="mb-4">
                        <p class="text-sm text-gray-600 mb-2">Logo atual:</p>
                        <img src="../<?php echo htmlspecialchars($logo); ?>" alt="Logo" class="max-h-32 object-contain">
                    </div>
                    <?php endif; ?>
                    
                    <div>
                        <label for="logo" class="block text-sm font-medium text-gray-700 mb-2">Upload de Nova Logo</label>
                        <input type="file" id="logo" name="logo" accept="image/*"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        <p class="mt-1 text-xs text-gray-500">Formatos aceitos: JPG, PNG, GIF, WebP</p>
                    </div>
                </div>
                
                <!-- Grupo do WhatsApp -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Grupo do WhatsApp</h2>
                    <p class="text-sm text-gray-600 mb-4">Link do grupo que aparece no botão "Entrar No Grupo do Whatsapp" na topbar do site.</p>
                    <div>
                        <label for="whatsapp_grupo_url" class="block text-sm font-medium text-gray-700 mb-2">URL do Grupo do WhatsApp</label>
                        <input type="url" id="whatsapp_grupo_url" name="whatsapp_grupo_url"
                               value="<?php echo htmlspecialchars($whatsappGrupoUrl); ?>"
                               placeholder="https://chat.whatsapp.com/..."
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        <p class="mt-1 text-xs text-gray-500">Exemplo: https://chat.whatsapp.com/ABC123xyz</p>
                    </div>
                </div>
                
                <!-- Categorias Topbar -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Categorias da Topbar</h2>
                    <p class="text-sm text-gray-600 mb-4">Informe as categorias separadas por | (pipe)</p>
                    
                    <div>
                        <label for="categorias_topbar" class="block text-sm font-medium text-gray-700 mb-2">Categorias</label>
                        <input type="text" id="categorias_topbar" name="categorias_topbar"
                               value="<?php echo htmlspecialchars($categoriasTopbar); ?>"
                               placeholder="Eletrônicos|Celulares|Games|Computadores|Cozinha"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        <p class="mt-1 text-xs text-gray-500">Exemplo: Eletrônicos|Celulares|Games</p>
                    </div>
                </div>
                
                <!-- Banners -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Banners do Carrossel</h2>
                    
                    <!-- Seleção do tipo de banner -->
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-3">Tipo de Banner</label>
                        <div class="flex gap-4">
                            <label class="flex items-center">
                                <input type="radio" name="banner_type" value="images" <?php echo $bannerType === 'images' ? 'checked' : ''; ?>
                                       class="mr-2" onchange="toggleBannerType()">
                                <span>Banners de Imagens (Upload)</span>
                            </label>
                            <label class="flex items-center">
                                <input type="radio" name="banner_type" value="default" <?php echo $bannerType === 'default' ? 'checked' : ''; ?>
                                       class="mr-2" onchange="toggleBannerType()">
                                <span>Banners Padrão (JSON)</span>
                            </label>
                        </div>
                    </div>
                    
                    <!-- Banners de Imagens -->
                    <div id="banners-images" style="display: <?php echo $bannerType === 'images' ? 'block' : 'none'; ?>;">
                        <p class="text-sm text-gray-600 mb-4">Faça upload de imagens que aparecerão no carrossel principal</p>
                        
                        <!-- Banners atuais -->
                        <?php 
                        // Recarregar banners após processamento
                        $bannersJson = getConfig('banners', '[]');
                        $banners = json_decode($bannersJson, true) ?: [];
                        $banners = array_values(array_filter(array_map('trim', $banners)));
                        ?>
                        <?php if (!empty($banners)): ?>
                        <div class="mb-6">
                            <h3 class="text-lg font-semibold text-gray-700 mb-3">Banners Atuais (<?php echo count($banners); ?>)</h3>
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                                <?php foreach ($banners as $index => $banner): ?>
                                <div class="relative border border-gray-200 rounded p-2">
                                    <img src="../<?php echo htmlspecialchars($banner); ?>" alt="Banner <?php echo $index + 1; ?>" 
                                         class="w-full h-32 object-cover rounded border border-gray-300">
                                    <button type="button" onclick="deleteBanner(<?php echo $index; ?>)" class="mt-2 bg-red-500 hover:bg-red-600 text-white text-sm px-3 py-1 rounded transition-colors w-full">
                                        Deletar Banner #<?php echo $index + 1; ?>
                                    </button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="mb-6 p-4 bg-gray-100 rounded text-gray-600 text-sm">
                            Nenhum banner cadastrado. Faça upload de imagens abaixo.
                        </div>
                        <?php endif; ?>
                        
                        <!-- Upload de novas imagens -->
                        <div>
                            <label for="banner_images" class="block text-sm font-medium text-gray-700 mb-2">Adicionar Novas Imagens</label>
                            <input type="file" id="banner_images" name="banner_images[]" multiple accept="image/*"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            <p class="mt-1 text-xs text-gray-500">Você pode selecionar múltiplas imagens. Formatos aceitos: JPG, PNG, GIF, WebP</p>
                        </div>
                    </div>
                    
                    <!-- Banners Padrão JSON -->
                    <div id="banners-default" style="display: <?php echo $bannerType === 'default' ? 'block' : 'none'; ?>;">
                        <p class="text-sm text-gray-600 mb-4">Configure os banners padrão usando JSON com títulos, subtítulos e gradientes</p>
                        
                        <div>
                            <label for="banners_json" class="block text-sm font-medium text-gray-700 mb-2">JSON dos Banners Padrão</label>
                            <textarea id="banners_json" name="banners_json" rows="12"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 font-mono text-sm"><?php echo htmlspecialchars($bannersDefaultJson); ?></textarea>
                            <p class="mt-1 text-xs text-gray-500">
                                Formato JSON: <code>[{"id": 1, "title": "Título", "subtitle": "Subtítulo", "description": "Descrição", "bgGradient": "from-orange-500 via-orange-600 to-orange-700"}]</code>
                            </p>
                            <button type="button" onclick="restoreDefaultBanners()" class="mt-2 text-sm text-blue-500 hover:text-blue-700">
                                Restaurar Banners Padrão
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Footer -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Configurações do Footer</h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="footer_email" class="block text-sm font-medium text-gray-700 mb-2">Email de Contato (opcional)</label>
                            <input type="email" id="footer_email" name="footer_email"
                                   value="<?php echo htmlspecialchars($footerEmail); ?>"
                                   placeholder="contato@exemplo.com"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        
                        <div>
                            <label for="footer_instagram" class="block text-sm font-medium text-gray-700 mb-2">Link Instagram (opcional)</label>
                            <input type="text" id="footer_instagram" name="footer_instagram"
                                   value="<?php echo htmlspecialchars($footerInstagram); ?>"
                                   placeholder="https://instagram.com/..."
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        
                        <div>
                            <label for="footer_facebook" class="block text-sm font-medium text-gray-700 mb-2">Link Facebook (opcional)</label>
                            <input type="text" id="footer_facebook" name="footer_facebook"
                                   value="<?php echo htmlspecialchars($footerFacebook); ?>"
                                   placeholder="https://facebook.com/..."
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-end">
                    <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-6 rounded transition-colors">
                        Salvar Configurações
                    </button>
                </div>
            </form>
        </main>
        
        <script>
        function deleteBanner(index) {
            if (confirm('Tem certeza que deseja deletar este banner?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'delete_banner';
                input.value = index;
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function toggleBannerType() {
            const imagesDiv = document.getElementById('banners-images');
            const defaultDiv = document.getElementById('banners-default');
            const radioImages = document.querySelector('input[name="banner_type"][value="images"]');
            const radioDefault = document.querySelector('input[name="banner_type"][value="default"]');
            
            if (radioImages.checked) {
                imagesDiv.style.display = 'block';
                defaultDiv.style.display = 'none';
            } else {
                imagesDiv.style.display = 'none';
                defaultDiv.style.display = 'block';
            }
        }
        
        function restoreDefaultBanners() {
            const defaultBanners = JSON.stringify([
                {"id": 1, "title": "Até 60% OFF", "subtitle": "em Eletrônicos", "description": "Ofertas imperdíveis para você", "bgGradient": "from-[hsl(var(--primary))] via-orange-500 to-orange-600"},
                {"id": 2, "title": "Super Ofertas", "subtitle": "em Celulares", "description": "Os melhores smartphones com desconto", "bgGradient": "from-orange-600 via-[hsl(var(--primary))] to-red-500"},
                {"id": 3, "title": "Black Friday", "subtitle": "Todo Dia", "description": "Preços baixos o ano inteiro", "bgGradient": "from-red-500 via-orange-500 to-[hsl(var(--primary))]"}
            ], null, 2);
            
            document.getElementById('banners_json').value = defaultBanners;
        }
        </script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

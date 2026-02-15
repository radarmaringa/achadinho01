<?php
/**
 * Configurações da automação Mercado Livre
 * - Scraping de ofertas, conversão para link de afiliado, IA, envio WhatsApp (Evolution API)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$message = '';
$messageType = '';

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ativação
    setConfig('ml_automacao_ativa', isset($_POST['ml_automacao_ativa']) ? '1' : '0');
    
    // Agendamento
    setConfig('ml_intervalo_minutos', (string) max(5, min(120, (int)($_POST['ml_intervalo_minutos'] ?? 30))));
    setConfig('ml_hora_inicio', (string) max(0, min(23, (int)($_POST['ml_hora_inicio'] ?? 9))));
    setConfig('ml_hora_fim', (string) max(0, min(23, (int)($_POST['ml_hora_fim'] ?? 21))));
    setConfig('ml_site_url', trim($_POST['ml_site_url'] ?? ''));
    setConfig('ml_cron_token', trim($_POST['ml_cron_token'] ?? ''));
    
    // Mercado Livre - Afiliados
    setConfig('ml_tag_afiliado', trim($_POST['ml_tag_afiliado'] ?? ''));
    setConfig('ml_csrf_token', trim($_POST['ml_csrf_token'] ?? ''));
    setConfig('ml_cookie', trim($_POST['ml_cookie'] ?? ''));
    
    // OpenAI
    setConfig('ml_openai_api_key', trim($_POST['ml_openai_api_key'] ?? ''));
    setConfig('ml_openai_model', trim($_POST['ml_openai_model'] ?? 'gpt-4.1-mini'));
    
    // Evolution API (WhatsApp)
    setConfig('ml_evolution_url', rtrim(trim($_POST['ml_evolution_url'] ?? ''), '/'));
    setConfig('ml_evolution_instancia', trim($_POST['ml_evolution_instancia'] ?? ''));
    setConfig('ml_evolution_apikey', trim($_POST['ml_evolution_apikey'] ?? ''));
    setConfig('ml_evolution_grupos', trim($_POST['ml_evolution_grupos'] ?? ''));
    
    // Comportamento
    setConfig('ml_produtos_por_execucao', (string) max(1, min(10, (int)($_POST['ml_produtos_por_execucao'] ?? 1))));
    setConfig('ml_delay_entre_envios', (string) max(1, min(120, (int)($_POST['ml_delay_entre_envios'] ?? 10))));
    
    // Publicar no site
    setConfig('ml_site_publicar', isset($_POST['ml_site_publicar']) ? '1' : '0');
    setConfig('ml_site_categoria_id', (string) (int)($_POST['ml_site_categoria_id'] ?? 0));
    
    // Google Sheets (opcional)
    setConfig('ml_sheets_ativo', isset($_POST['ml_sheets_ativo']) ? '1' : '0');
    setConfig('ml_sheets_document_id', trim($_POST['ml_sheets_document_id'] ?? ''));
    
    $message = 'Configurações do Mercado Livre salvas com sucesso!';
    $messageType = 'success';
}

// Carregar valores atuais
$ml_automacao_ativa     = getConfig('ml_automacao_ativa', '0') === '1';
$ml_intervalo_minutos   = getConfig('ml_intervalo_minutos', '30');
$ml_hora_inicio         = getConfig('ml_hora_inicio', '9');
$ml_hora_fim            = getConfig('ml_hora_fim', '21');
$ml_site_url            = getConfig('ml_site_url', '');
$ml_cron_token          = getConfig('ml_cron_token', '');
$ml_tag_afiliado        = getConfig('ml_tag_afiliado', '');
$ml_csrf_token          = getConfig('ml_csrf_token', '');
$ml_cookie              = getConfig('ml_cookie', '');
$ml_openai_api_key      = getConfig('ml_openai_api_key', '');
$ml_openai_model        = getConfig('ml_openai_model', 'gpt-4.1-mini');
$ml_evolution_url       = getConfig('ml_evolution_url', '');
$ml_evolution_instancia = getConfig('ml_evolution_instancia', '');
$ml_evolution_apikey    = getConfig('ml_evolution_apikey', '');
$ml_evolution_grupos    = getConfig('ml_evolution_grupos', '');
$ml_produtos_por_execucao = getConfig('ml_produtos_por_execucao', '1');
$ml_delay_entre_envios  = getConfig('ml_delay_entre_envios', '10');
$ml_site_publicar       = getConfig('ml_site_publicar', '1') === '1';
$ml_site_categoria_id   = getConfig('ml_site_categoria_id', '');
$ml_sheets_ativo        = getConfig('ml_sheets_ativo', '0') === '1';
$ml_sheets_document_id  = getConfig('ml_sheets_document_id', '');

// Calcular expressão cron para exibição
$mi = (int) $ml_intervalo_minutos;
$hi = (int) $ml_hora_inicio;
$hf = (int) $ml_hora_fim;
if ($mi === 60) {
    $cronExpr = '0 ' . $hi . '-' . $hf . ' * * *';
} elseif ($mi === 120) {
    $horas = range((int)$hi, (int)$hf, 2);
    $cronExpr = '0 ' . implode(',', $horas ?: [(int)$hi]) . ' * * *';
} else {
    $cronExpr = '*/' . $mi . ' ' . $hi . '-' . $hf . ' * * *';
}
$cronUrl = '';
if (!empty($ml_site_url)) {
    $base = rtrim($ml_site_url, '/');
    $cronUrl = $base . '/cron/rodar-automacao-ml.php';
    if (!empty($ml_cron_token)) {
        $cronUrl .= '?token=' . urlencode($ml_cron_token);
    }
}

$pdo = getDB();
$categorias = $pdo->query("SELECT id, nome FROM categorias WHERE ativo = 1 ORDER BY ordem")->fetchAll();

$pageTitle = 'Mercado Livre';
require_once __DIR__ . '/includes/header.php';
?>
        <main class="flex-1 overflow-y-auto p-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-2">Mercado Livre</h1>
            <p class="text-gray-600 mb-8">Configure a automação: ofertas do ML → link de afiliado → copy com IA → WhatsApp.</p>

            <?php if ($message): ?>
            <div class="mb-4 p-4 rounded <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <form method="POST" class="space-y-8">
                <!-- Ativar automação -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Status da Automação</h2>
                    <label class="flex items-center gap-3">
                        <input type="checkbox" name="ml_automacao_ativa" value="1" <?php echo $ml_automacao_ativa ? 'checked' : ''; ?>
                               class="rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                        <span class="text-gray-700">Automação ativa (quando o cron executar, a automação rodará se estiver marcado)</span>
                    </label>
                </div>

                <!-- Agendamento (Cron) -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Agendamento</h2>
                    <p class="text-sm text-gray-600 mb-4">Defina o intervalo e o horário em que a automação deve rodar. É necessário configurar um cron no servidor (ou serviço externo) para chamar o script.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label for="ml_intervalo_minutos" class="block text-sm font-medium text-gray-700 mb-2">Intervalo (minutos)</label>
                            <select id="ml_intervalo_minutos" name="ml_intervalo_minutos"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                                <option value="15" <?php echo $ml_intervalo_minutos == '15' ? 'selected' : ''; ?>>A cada 15 min</option>
                                <option value="30" <?php echo $ml_intervalo_minutos == '30' ? 'selected' : ''; ?>>A cada 30 min</option>
                                <option value="60" <?php echo $ml_intervalo_minutos == '60' ? 'selected' : ''; ?>>A cada 1 hora</option>
                                <option value="120" <?php echo $ml_intervalo_minutos == '120' ? 'selected' : ''; ?>>A cada 2 horas</option>
                            </select>
                        </div>
                        <div>
                            <label for="ml_hora_inicio" class="block text-sm font-medium text-gray-700 mb-2">Hora início (0–23)</label>
                            <input type="number" id="ml_hora_inicio" name="ml_hora_inicio" min="0" max="23"
                                   value="<?php echo htmlspecialchars($ml_hora_inicio); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="ml_hora_fim" class="block text-sm font-medium text-gray-700 mb-2">Hora fim (0–23)</label>
                            <input type="number" id="ml_hora_fim" name="ml_hora_fim" min="0" max="23"
                                   value="<?php echo htmlspecialchars($ml_hora_fim); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                    </div>
                    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="ml_site_url" class="block text-sm font-medium text-gray-700 mb-2">URL do site (para montar o link do cron)</label>
                            <input type="url" id="ml_site_url" name="ml_site_url" placeholder="https://seusite.com.br"
                                   value="<?php echo htmlspecialchars($ml_site_url); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="ml_cron_token" class="block text-sm font-medium text-gray-700 mb-2">Token do cron (opcional, para ?token=)</label>
                            <input type="text" id="ml_cron_token" name="ml_cron_token" placeholder="um_token_secreto"
                                   value="<?php echo htmlspecialchars($ml_cron_token); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            <p class="mt-1 text-xs text-gray-500">Evita que qualquer um dispare a automação pela URL.</p>
                        </div>
                    </div>
                    <div class="mt-4 p-4 bg-amber-50 border border-amber-200 rounded-md">
                        <p class="text-sm font-medium text-amber-800 mb-2">Comando cron sugerido (adicione no crontab do servidor ou use um serviço como cron-job.org):</p>
                        <p class="font-mono text-sm text-gray-700 break-all"><?php echo htmlspecialchars($cronExpr); ?> curl -s "<?php echo htmlspecialchars($cronUrl ?: '[URL_DO_SEU_SITE]/cron/rodar-automacao-ml.php?token=SEU_TOKEN]'); ?>" &gt; /dev/null 2&gt;&amp;1</p>
                    </div>
                </div>

                <!-- Mercado Livre - Afiliados -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Mercado Livre – Programa de Afiliados</h2>
                    <p class="text-sm text-gray-600 mb-4">Para converter links em links de afiliado é obrigatório estar logado no ML. O <strong>x-csrf-token</strong> e o <strong>cookie</strong> expiram; quando a conversão parar de funcionar, atualize os valores abaixo.</p>
                    
                    <div class="mb-4">
                        <button type="button" onclick="document.getElementById('ml-instrucoes').classList.toggle('hidden');" 
                                class="text-orange-600 hover:text-orange-700 text-sm font-medium">
                            ► Como obter o CSRF e o Cookie
                        </button>
                        <div id="ml-instrucoes" class="hidden mt-2 p-4 bg-gray-50 rounded border border-gray-200 text-sm text-gray-700">
                            <ol class="list-decimal list-inside space-y-2">
                                <li>Abra o Chrome/Edge em modo anônimo e faça login no Mercado Livre.</li>
                                <li>Acesse: <a href="https://www.mercadolivre.com.br/afiliados/linkbuilder" target="_blank" rel="noopener" class="text-orange-600 underline">Link Builder</a>.</li>
                                <li>Pressione <kbd class="px-1 bg-gray-200 rounded">F12</kbd> (DevTools) → aba <strong>Network (Rede)</strong> → filtre por <strong>Fetch/XHR</strong>.</li>
                                <li>Na página do Link Builder, gere um link de afiliado manualmente.</li>
                                <li>Procure a requisição <code>createLink</code> → clique com o botão direito → <strong>Copy</strong> → <strong>Copy as cURL</strong>.</li>
                                <li>Cole em um editor e copie o valor do header <code>x-csrf-token</code> e do header <code>cookie</code> (completo).</li>
                            </ol>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="ml_tag_afiliado" class="block text-sm font-medium text-gray-700 mb-2">Tag de afiliado</label>
                            <input type="text" id="ml_tag_afiliado" name="ml_tag_afiliado" placeholder="ex: dv20251007071953"
                                   value="<?php echo htmlspecialchars($ml_tag_afiliado); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="ml_csrf_token" class="block text-sm font-medium text-gray-700 mb-2">x-csrf-token</label>
                            <input type="text" id="ml_csrf_token" name="ml_csrf_token" placeholder="valor do header x-csrf-token"
                                   value="<?php echo htmlspecialchars($ml_csrf_token); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                    </div>
                    <div class="mt-4">
                        <label for="ml_cookie" class="block text-sm font-medium text-gray-700 mb-2">Cookie (completo)</label>
                        <textarea id="ml_cookie" name="ml_cookie" rows="4" placeholder="Cole aqui o valor do header cookie (pode ser longo)"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500"><?php echo htmlspecialchars($ml_cookie); ?></textarea>
                    </div>
                </div>

                <!-- OpenAI -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">OpenAI (copy para WhatsApp)</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="ml_openai_api_key" class="block text-sm font-medium text-gray-700 mb-2">Chave da API OpenAI</label>
                            <input type="password" id="ml_openai_api_key" name="ml_openai_api_key" placeholder="sk-..."
                                   value="<?php echo htmlspecialchars($ml_openai_api_key); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="ml_openai_model" class="block text-sm font-medium text-gray-700 mb-2">Modelo</label>
                            <select id="ml_openai_model" name="ml_openai_model"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                                <option value="gpt-4.1-mini" <?php echo $ml_openai_model === 'gpt-4.1-mini' ? 'selected' : ''; ?>>gpt-4.1-mini</option>
                                <option value="gpt-4o-mini" <?php echo $ml_openai_model === 'gpt-4o-mini' ? 'selected' : ''; ?>>gpt-4o-mini</option>
                                <option value="gpt-4o" <?php echo $ml_openai_model === 'gpt-4o' ? 'selected' : ''; ?>>gpt-4o</option>
                                <option value="gpt-4-turbo" <?php echo $ml_openai_model === 'gpt-4-turbo' ? 'selected' : ''; ?>>gpt-4-turbo</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Evolution API (WhatsApp) -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Evolution API (WhatsApp)</h2>
                    <p class="text-sm text-gray-600 mb-4">A URL do sendMedia será: <code class="bg-gray-100 px-1 rounded">{URL}/message/sendMedia/{INSTANCIA}</code></p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="ml_evolution_url" class="block text-sm font-medium text-gray-700 mb-2">URL base da Evolution</label>
                            <input type="url" id="ml_evolution_url" name="ml_evolution_url" placeholder="https://evolution.digitalavance.com.br"
                                   value="<?php echo htmlspecialchars($ml_evolution_url); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="ml_evolution_instancia" class="block text-sm font-medium text-gray-700 mb-2">Nome da instância</label>
                            <input type="text" id="ml_evolution_instancia" name="ml_evolution_instancia" placeholder="ex: islayne"
                                   value="<?php echo htmlspecialchars($ml_evolution_instancia); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div class="md:col-span-2">
                            <label for="ml_evolution_apikey" class="block text-sm font-medium text-gray-700 mb-2">API Key da Evolution</label>
                            <input type="password" id="ml_evolution_apikey" name="ml_evolution_apikey" placeholder="C4AFCA003DAD-49B8-AF96-5D08E3C2B221"
                                   value="<?php echo htmlspecialchars($ml_evolution_apikey); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div class="md:col-span-2">
                            <label for="ml_evolution_grupos" class="block text-sm font-medium text-gray-700 mb-2">IDs dos grupos ou números (um por linha ou separados por vírgula)</label>
                            <textarea id="ml_evolution_grupos" name="ml_evolution_grupos" rows="3" placeholder="120363422904345053@g.us&#10;ou 5511999999999@s.whatsapp.net"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500"><?php echo htmlspecialchars($ml_evolution_grupos); ?></textarea>
                            <p class="mt-1 text-xs text-gray-500">Ex.: <code>120363422904345053@g.us</code> para grupos.</p>
                        </div>
                    </div>
                </div>

                <!-- Comportamento -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Comportamento</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="ml_produtos_por_execucao" class="block text-sm font-medium text-gray-700 mb-2">Produtos por execução</label>
                            <select id="ml_produtos_por_execucao" name="ml_produtos_por_execucao"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo $ml_produtos_por_execucao == (string)$i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div>
                            <label for="ml_delay_entre_envios" class="block text-sm font-medium text-gray-700 mb-2">Delay entre envios no WhatsApp (segundos)</label>
                            <input type="number" id="ml_delay_entre_envios" name="ml_delay_entre_envios" min="1" max="120"
                                   value="<?php echo htmlspecialchars($ml_delay_entre_envios); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                    </div>
                </div>

                <!-- Publicar no site -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Publicar no site de ofertas</h2>
                    <p class="text-sm text-gray-600 mb-4">Ao enviar no WhatsApp, o produto também será criado aqui no site: imagem, nome e link de afiliado. Os produtos entram como <strong>destaque</strong> e a <strong>categoria</strong> é definida automaticamente (por palavra‑chave nas existentes ou criada pela IA se não houver uma adequada).</p>
                    <label class="flex items-center gap-3 mb-4">
                        <input type="checkbox" name="ml_site_publicar" value="1" <?php echo $ml_site_publicar ? 'checked' : ''; ?>
                               class="rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                        <span class="text-gray-700">Criar produto no site ao enviar no WhatsApp</span>
                    </label>
                    <div>
                        <label for="ml_site_categoria_id" class="block text-sm font-medium text-gray-700 mb-2">Categoria fixa (opcional)</label>
                        <p class="text-xs text-gray-500 mb-1">Se escolher uma, ela será usada em vez da categoria automática.</p>
                        <select id="ml_site_categoria_id" name="ml_site_categoria_id"
                                class="w-full max-w-xs px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            <option value="0" <?php echo ($ml_site_categoria_id === '' || $ml_site_categoria_id === '0') ? 'selected' : ''; ?>>Nenhuma</option>
                            <?php foreach ($categorias as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>" <?php echo $ml_site_categoria_id === (string)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Google Sheets (opcional) -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Google Sheets (opcional)</h2>
                    <p class="text-sm text-gray-600 mb-4">Se ativado, cada produto enviado será registrado em uma planilha. A integração com Service Account deve ser configurada no script de automação.</p>
                    <label class="flex items-center gap-3 mb-4">
                        <input type="checkbox" name="ml_sheets_ativo" value="1" <?php echo $ml_sheets_ativo ? 'checked' : ''; ?>
                               class="rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                        <span class="text-gray-700">Registrar no Google Sheets</span>
                    </label>
                    <div>
                        <label for="ml_sheets_document_id" class="block text-sm font-medium text-gray-700 mb-2">ID do documento (da URL: docs.google.com/spreadsheets/d/<strong>ID</strong>/edit)</label>
                        <input type="text" id="ml_sheets_document_id" name="ml_sheets_document_id" placeholder="18h7YNsGcm6XwNTUtjr8891-qAMAsHjHZNWlOAnoeWyc"
                               value="<?php echo htmlspecialchars($ml_sheets_document_id); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                    </div>
                </div>

                <div class="flex justify-end gap-4">
                    <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-6 rounded transition-colors">
                        Salvar configurações
                    </button>
                    <button type="button" id="btnExecutarAgora" onclick="executarAgora()"
                            class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-6 rounded transition-colors flex items-center gap-2">
                        <span id="btnExecutarTexto">Executar agora</span>
                        <span id="btnExecutarSpinner" class="hidden">
                            <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </span>
                    </button>
                </div>
            </form>

            <div id="executarResultado" class="mt-6 hidden"></div>
        </main>
        <script>
        function executarAgora() {
            var btn = document.getElementById('btnExecutarAgora');
            var txt = document.getElementById('btnExecutarTexto');
            var spi = document.getElementById('btnExecutarSpinner');
            var box = document.getElementById('executarResultado');
            btn.disabled = true;
            txt.textContent = 'Executando...';
            spi.classList.remove('hidden');
            box.classList.add('hidden');
            box.innerHTML = '';

            fetch('executar-automacao-ml.php', { method: 'POST', credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    btn.disabled = false;
                    txt.textContent = 'Executar agora';
                    spi.classList.add('hidden');
                    box.classList.remove('hidden');
                    var isOk = d.success === true;
                    box.className = 'mt-6 p-4 rounded ' + (isOk ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800');
                    box.innerHTML = '<p class="font-bold">' + (isOk ? 'Sucesso' : 'Erro') + '</p><p class="mt-1">' + escapeHtml(d.message || '') + '</p>';
                    if (d.details && Object.keys(d.details).length) {
                        box.innerHTML += '<pre class="mt-2 text-sm opacity-90">' + escapeHtml(JSON.stringify(d.details, null, 2)) + '</pre>';
                    }
                    if (d.errors && d.errors.length) {
                        box.innerHTML += '<p class="mt-2 font-medium">Detalhes:</p><ul class="list-disc list-inside mt-1 text-sm">';
                        d.errors.forEach(function(e) { box.innerHTML += '<li>' + escapeHtml(e) + '</li>'; });
                        box.innerHTML += '</ul>';
                    }
                })
                .catch(function(e) {
                    btn.disabled = false;
                    txt.textContent = 'Executar agora';
                    spi.classList.add('hidden');
                    box.classList.remove('hidden');
                    box.className = 'mt-6 p-4 rounded bg-red-100 text-red-800';
                    box.innerHTML = '<p class="font-bold">Erro</p><p>' + escapeHtml(String(e && e.message ? e.message : 'Falha na requisição.')) + '</p>';
                });
        }
        function escapeHtml(s) {
            if (s == null) return '';
            var d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }
        </script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

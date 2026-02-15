<?php
/**
 * Configurações da automação Shopee
 * API Afiliados Shopee → IA (copy) → Evolution (WhatsApp) → Site
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
    setConfig('shopee_automacao_ativa', isset($_POST['shopee_automacao_ativa']) ? '1' : '0');
    setConfig('shopee_intervalo_horas', (string) max(1, min(6, (int)($_POST['shopee_intervalo_horas'] ?? 2))));
    setConfig('shopee_hora_inicio', (string) max(0, min(23, (int)($_POST['shopee_hora_inicio'] ?? 8))));
    setConfig('shopee_hora_fim', (string) max(0, min(23, (int)($_POST['shopee_hora_fim'] ?? 22))));
    setConfig('shopee_site_url', trim($_POST['shopee_site_url'] ?? ''));
    setConfig('shopee_cron_token', trim($_POST['shopee_cron_token'] ?? ''));
    setConfig('shopee_app_id', trim($_POST['shopee_app_id'] ?? ''));
    setConfig('shopee_secret', trim($_POST['shopee_secret'] ?? ''));
    setConfig('shopee_openai_api_key', trim($_POST['shopee_openai_api_key'] ?? ''));
    setConfig('shopee_openai_model', trim($_POST['shopee_openai_model'] ?? 'gpt-4o-mini'));
    setConfig('shopee_evolution_url', rtrim(trim($_POST['shopee_evolution_url'] ?? ''), '/'));
    setConfig('shopee_evolution_instancia', trim($_POST['shopee_evolution_instancia'] ?? ''));
    setConfig('shopee_evolution_apikey', trim($_POST['shopee_evolution_apikey'] ?? ''));
    setConfig('shopee_evolution_grupos', trim($_POST['shopee_evolution_grupos'] ?? ''));
    setConfig('shopee_produtos_por_execucao', (string) max(1, min(10, (int)($_POST['shopee_produtos_por_execucao'] ?? 1))));
    setConfig('shopee_delay_entre_envios', (string) max(1, min(120, (int)($_POST['shopee_delay_entre_envios'] ?? 10))));
    setConfig('shopee_site_publicar', isset($_POST['shopee_site_publicar']) ? '1' : '0');
    setConfig('shopee_site_categoria_id', (string) (int)($_POST['shopee_site_categoria_id'] ?? 0));
    setConfig('shopee_sheets_ativo', isset($_POST['shopee_sheets_ativo']) ? '1' : '0');
    setConfig('shopee_sheets_document_id', trim($_POST['shopee_sheets_document_id'] ?? ''));
    $message = 'Configurações da Shopee salvas com sucesso!';
    $messageType = 'success';
}

$shopee_automacao_ativa     = getConfig('shopee_automacao_ativa', '0') === '1';
$shopee_intervalo_horas     = getConfig('shopee_intervalo_horas', '2');
$shopee_hora_inicio         = getConfig('shopee_hora_inicio', '8');
$shopee_hora_fim            = getConfig('shopee_hora_fim', '22');
$shopee_site_url            = getConfig('shopee_site_url', '');
$shopee_cron_token          = getConfig('shopee_cron_token', '');
$shopee_app_id              = getConfig('shopee_app_id', '');
$shopee_secret              = getConfig('shopee_secret', '');
$shopee_openai_api_key      = getConfig('shopee_openai_api_key', '');
$shopee_openai_model        = getConfig('shopee_openai_model', 'gpt-4o-mini');
$shopee_evolution_url       = getConfig('shopee_evolution_url', '');
$shopee_evolution_instancia = getConfig('shopee_evolution_instancia', '');
$shopee_evolution_apikey    = getConfig('shopee_evolution_apikey', '');
$shopee_evolution_grupos    = getConfig('shopee_evolution_grupos', '');
$shopee_produtos_por_execucao = getConfig('shopee_produtos_por_execucao', '1');
$shopee_delay_entre_envios  = getConfig('shopee_delay_entre_envios', '10');
$shopee_site_publicar       = getConfig('shopee_site_publicar', '1') === '1';
$shopee_site_categoria_id   = getConfig('shopee_site_categoria_id', '');
$shopee_sheets_ativo        = getConfig('shopee_sheets_ativo', '0') === '1';
$shopee_sheets_document_id  = getConfig('shopee_sheets_document_id', '');

$hi = (int)$shopee_hora_inicio;
$hf = (int)$shopee_hora_fim;
$ih = (int)$shopee_intervalo_horas;
$horas = $ih >= 2 ? range($hi, min($hf, 23), $ih) : range($hi, min($hf, 23));
$cronExpr = '0 ' . implode(',', $horas ?: [$hi]) . ' * * *';
$cronUrl = '';
if (!empty($shopee_site_url)) {
    $base = rtrim($shopee_site_url, '/');
    $cronUrl = $base . '/cron/rodar-automacao-shopee.php';
    if (!empty($shopee_cron_token)) $cronUrl .= '?token=' . urlencode($shopee_cron_token);
}

$pdo = getDB();
$categorias = $pdo->query("SELECT id, nome FROM categorias WHERE ativo = 1 ORDER BY ordem")->fetchAll();

$pageTitle = 'Shopee';
require_once __DIR__ . '/includes/header.php';
?>
        <main class="flex-1 overflow-y-auto p-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-2">Shopee</h1>
            <p class="text-gray-600 mb-8">Configure a automação: API Afiliados Shopee → copy com IA → WhatsApp e site.</p>

            <?php if ($message): ?>
            <div class="mb-4 p-4 rounded <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <form method="POST" class="space-y-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Status da Automação</h2>
                    <label class="flex items-center gap-3">
                        <input type="checkbox" name="shopee_automacao_ativa" value="1" <?php echo $shopee_automacao_ativa ? 'checked' : ''; ?>
                               class="rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                        <span class="text-gray-700">Automação ativa</span>
                    </label>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Agendamento</h2>
                    <p class="text-sm text-gray-600 mb-4">Ex.: a cada 2h das 8h às 22h = <code>0 8,10,12,14,16,18,20,22 * * *</code></p>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label for="shopee_intervalo_horas" class="block text-sm font-medium text-gray-700 mb-2">A cada (horas)</label>
                            <select id="shopee_intervalo_horas" name="shopee_intervalo_horas"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                                <option value="1" <?php echo $shopee_intervalo_horas == '1' ? 'selected' : ''; ?>>1 hora</option>
                                <option value="2" <?php echo $shopee_intervalo_horas == '2' ? 'selected' : ''; ?>>2 horas</option>
                                <option value="3" <?php echo $shopee_intervalo_horas == '3' ? 'selected' : ''; ?>>3 horas</option>
                                <option value="4" <?php echo $shopee_intervalo_horas == '4' ? 'selected' : ''; ?>>4 horas</option>
                                <option value="6" <?php echo $shopee_intervalo_horas == '6' ? 'selected' : ''; ?>>6 horas</option>
                            </select>
                        </div>
                        <div>
                            <label for="shopee_hora_inicio" class="block text-sm font-medium text-gray-700 mb-2">Hora início (0–23)</label>
                            <input type="number" id="shopee_hora_inicio" name="shopee_hora_inicio" min="0" max="23" value="<?php echo htmlspecialchars($shopee_hora_inicio); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="shopee_hora_fim" class="block text-sm font-medium text-gray-700 mb-2">Hora fim (0–23)</label>
                            <input type="number" id="shopee_hora_fim" name="shopee_hora_fim" min="0" max="23" value="<?php echo htmlspecialchars($shopee_hora_fim); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                    </div>
                    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="shopee_site_url" class="block text-sm font-medium text-gray-700 mb-2">URL do site (para o cron)</label>
                            <input type="url" id="shopee_site_url" name="shopee_site_url" placeholder="https://seusite.com.br" value="<?php echo htmlspecialchars($shopee_site_url); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="shopee_cron_token" class="block text-sm font-medium text-gray-700 mb-2">Token do cron (opcional)</label>
                            <input type="text" id="shopee_cron_token" name="shopee_cron_token" placeholder="token_secreto" value="<?php echo htmlspecialchars($shopee_cron_token); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                    </div>
                    <div class="mt-4 p-4 bg-amber-50 border border-amber-200 rounded-md">
                        <p class="text-sm font-medium text-amber-800 mb-2">Cron sugerido:</p>
                        <p class="font-mono text-sm text-gray-700 break-all"><?php echo htmlspecialchars($cronExpr); ?> curl -s "<?php echo htmlspecialchars($cronUrl ?: '[URL]/cron/rodar-automacao-shopee.php?token=TOKEN]'); ?>" &gt; /dev/null 2&gt;&amp;1</p>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Shopee – API Afiliados</h2>
                    <p class="text-sm text-gray-600 mb-4">App ID e Secret em <a href="https://affiliate.shopee.com.br" target="_blank" rel="noopener" class="text-orange-600 underline">affiliate.shopee.com.br</a>.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="shopee_app_id" class="block text-sm font-medium text-gray-700 mb-2">App ID</label>
                            <input type="text" id="shopee_app_id" name="shopee_app_id" placeholder="App ID da API"
                                   value="<?php echo htmlspecialchars($shopee_app_id); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="shopee_secret" class="block text-sm font-medium text-gray-700 mb-2">Secret Key</label>
                            <input type="password" id="shopee_secret" name="shopee_secret" placeholder="Secret da API"
                                   value="<?php echo htmlspecialchars($shopee_secret); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">OpenAI (copy Shopee para WhatsApp)</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="shopee_openai_api_key" class="block text-sm font-medium text-gray-700 mb-2">Chave da API OpenAI</label>
                            <input type="password" id="shopee_openai_api_key" name="shopee_openai_api_key" placeholder="sk-..." value="<?php echo htmlspecialchars($shopee_openai_api_key); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="shopee_openai_model" class="block text-sm font-medium text-gray-700 mb-2">Modelo</label>
                            <select id="shopee_openai_model" name="shopee_openai_model" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                                <option value="gpt-4o-mini" <?php echo $shopee_openai_model === 'gpt-4o-mini' ? 'selected' : ''; ?>>gpt-4o-mini</option>
                                <option value="gpt-4.1-mini" <?php echo $shopee_openai_model === 'gpt-4.1-mini' ? 'selected' : ''; ?>>gpt-4.1-mini</option>
                                <option value="gpt-4o" <?php echo $shopee_openai_model === 'gpt-4o' ? 'selected' : ''; ?>>gpt-4o</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Evolution API (WhatsApp)</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="shopee_evolution_url" class="block text-sm font-medium text-gray-700 mb-2">URL base</label>
                            <input type="url" id="shopee_evolution_url" name="shopee_evolution_url" placeholder="https://evolution.digitalavance.com.br" value="<?php echo htmlspecialchars($shopee_evolution_url); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="shopee_evolution_instancia" class="block text-sm font-medium text-gray-700 mb-2">Instância</label>
                            <input type="text" id="shopee_evolution_instancia" name="shopee_evolution_instancia" placeholder="ex: islayne" value="<?php echo htmlspecialchars($shopee_evolution_instancia); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div class="md:col-span-2">
                            <label for="shopee_evolution_apikey" class="block text-sm font-medium text-gray-700 mb-2">API Key</label>
                            <input type="password" id="shopee_evolution_apikey" name="shopee_evolution_apikey" value="<?php echo htmlspecialchars($shopee_evolution_apikey); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div class="md:col-span-2">
                            <label for="shopee_evolution_grupos" class="block text-sm font-medium text-gray-700 mb-2">IDs dos grupos (um por linha ou vírgula)</label>
                            <textarea id="shopee_evolution_grupos" name="shopee_evolution_grupos" rows="3" placeholder="120363422904345053@g.us" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500"><?php echo htmlspecialchars($shopee_evolution_grupos); ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Comportamento</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="shopee_produtos_por_execucao" class="block text-sm font-medium text-gray-700 mb-2">Produtos por execução</label>
                            <select id="shopee_produtos_por_execucao" name="shopee_produtos_por_execucao" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo $shopee_produtos_por_execucao == (string)$i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div>
                            <label for="shopee_delay_entre_envios" class="block text-sm font-medium text-gray-700 mb-2">Delay entre envios (s)</label>
                            <input type="number" id="shopee_delay_entre_envios" name="shopee_delay_entre_envios" min="1" max="120" value="<?php echo htmlspecialchars($shopee_delay_entre_envios); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Publicar no site</h2>
                    <p class="text-sm text-gray-600 mb-4">Produtos em <strong>destaque</strong> e categoria automática (ou fixa).</p>
                    <label class="flex items-center gap-3 mb-4">
                        <input type="checkbox" name="shopee_site_publicar" value="1" <?php echo $shopee_site_publicar ? 'checked' : ''; ?> class="rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                        <span class="text-gray-700">Criar produto no site ao enviar no WhatsApp</span>
                    </label>
                    <div>
                        <label for="shopee_site_categoria_id" class="block text-sm font-medium text-gray-700 mb-2">Categoria fixa (opcional)</label>
                        <select id="shopee_site_categoria_id" name="shopee_site_categoria_id" class="w-full max-w-xs px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            <option value="0" <?php echo ($shopee_site_categoria_id === '' || $shopee_site_categoria_id === '0') ? 'selected' : ''; ?>>Nenhuma</option>
                            <?php foreach ($categorias as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>" <?php echo $shopee_site_categoria_id === (string)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Google Sheets (opcional)</h2>
                    <label class="flex items-center gap-3 mb-4">
                        <input type="checkbox" name="shopee_sheets_ativo" value="1" <?php echo $shopee_sheets_ativo ? 'checked' : ''; ?> class="rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                        <span class="text-gray-700">Registrar no Google Sheets</span>
                    </label>
                    <div>
                        <label for="shopee_sheets_document_id" class="block text-sm font-medium text-gray-700 mb-2">ID do documento</label>
                        <input type="text" id="shopee_sheets_document_id" name="shopee_sheets_document_id" placeholder="11UCQ7YlVhhJ92_Ku3BSe2ZHT7xUzcJc51RQ79XqxQSg" value="<?php echo htmlspecialchars($shopee_sheets_document_id); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                    </div>
                </div>

                <div class="flex justify-end gap-4">
                    <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-2 px-6 rounded transition-colors">Salvar configurações</button>
                    <button type="button" id="btnExecutarAgora" onclick="executarAgora()" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-6 rounded transition-colors flex items-center gap-2">
                        <span id="btnExecutarTexto">Executar agora</span>
                        <span id="btnExecutarSpinner" class="hidden"><svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg></span>
                    </button>
                </div>
            </form>

            <div id="executarResultado" class="mt-6 hidden"></div>
        </main>
        <script>
        function executarAgora() {
            var btn=document.getElementById('btnExecutarAgora'), txt=document.getElementById('btnExecutarTexto'), spi=document.getElementById('btnExecutarSpinner'), box=document.getElementById('executarResultado');
            btn.disabled=true; txt.textContent='Executando...'; spi.classList.remove('hidden'); box.classList.add('hidden'); box.innerHTML='';
            fetch('executar-automacao-shopee.php', { method: 'POST', credentials: 'same-origin' }).then(function(r){ return r.json(); }).then(function(d){
                btn.disabled=false; txt.textContent='Executar agora'; spi.classList.add('hidden'); box.classList.remove('hidden');
                var ok=d.success===true; box.className='mt-6 p-4 rounded '+(ok?'bg-green-100 text-green-800':'bg-red-100 text-red-800');
                box.innerHTML='<p class="font-bold">'+(ok?'Sucesso':'Erro')+'</p><p class="mt-1">'+escapeHtml(d.message||'')+'</p>';
                if(d.details&&Object.keys(d.details).length) box.innerHTML+='<pre class="mt-2 text-sm opacity-90">'+escapeHtml(JSON.stringify(d.details,null,2))+'</pre>';
                if(d.errors&&d.errors.length){ box.innerHTML+='<p class="mt-2 font-medium">Detalhes:</p><ul class="list-disc list-inside mt-1 text-sm">'; d.errors.forEach(function(e){ box.innerHTML+='<li>'+escapeHtml(e)+'</li>'; }); box.innerHTML+='</ul>'; }
            }).catch(function(e){ btn.disabled=false; txt.textContent='Executar agora'; spi.classList.add('hidden'); box.classList.remove('hidden'); box.className='mt-6 p-4 rounded bg-red-100 text-red-800'; box.innerHTML='<p class="font-bold">Erro</p><p>'+escapeHtml(String(e&&e.message?e.message:'Falha na requisição.'))+'</p>'; });
        }
        function escapeHtml(s){ if(s==null)return''; var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
        </script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

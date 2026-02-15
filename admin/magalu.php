<?php
/**
 * Configurações da automação Magalu (Magazine Luiza)
 * API Lomadee (produtos + shorten) → IA (copy) → Evolution (WhatsApp) → Site
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
    setConfig('magalu_automacao_ativa', isset($_POST['magalu_automacao_ativa']) ? '1' : '0');
    $intervalo = (int)($_POST['magalu_intervalo_minutos'] ?? 20);
    $intervalo = in_array($intervalo, [5, 10, 15, 20, 30]) ? $intervalo : 20;
    setConfig('magalu_intervalo_minutos', (string) $intervalo);
    setConfig('magalu_hora_inicio', (string) max(0, min(23, (int)($_POST['magalu_hora_inicio'] ?? 9))));
    setConfig('magalu_hora_fim', (string) max(0, min(23, (int)($_POST['magalu_hora_fim'] ?? 20))));
    setConfig('magalu_site_url', trim($_POST['magalu_site_url'] ?? ''));
    setConfig('magalu_cron_token', trim($_POST['magalu_cron_token'] ?? ''));
    setConfig('magalu_lomadee_api_key', trim($_POST['magalu_lomadee_api_key'] ?? ''));
    setConfig('magalu_openai_api_key', trim($_POST['magalu_openai_api_key'] ?? ''));
    setConfig('magalu_openai_model', trim($_POST['magalu_openai_model'] ?? 'gpt-4o-mini'));
    setConfig('magalu_evolution_url', rtrim(trim($_POST['magalu_evolution_url'] ?? ''), '/'));
    setConfig('magalu_evolution_instancia', trim($_POST['magalu_evolution_instancia'] ?? ''));
    setConfig('magalu_evolution_apikey', trim($_POST['magalu_evolution_apikey'] ?? ''));
    setConfig('magalu_evolution_grupos', trim($_POST['magalu_evolution_grupos'] ?? ''));
    setConfig('magalu_produtos_por_execucao', (string) max(1, min(10, (int)($_POST['magalu_produtos_por_execucao'] ?? 1))));
    setConfig('magalu_delay_entre_envios', (string) max(1, min(120, (int)($_POST['magalu_delay_entre_envios'] ?? 10))));
    setConfig('magalu_site_publicar', isset($_POST['magalu_site_publicar']) ? '1' : '0');
    setConfig('magalu_site_categoria_id', (string) (int)($_POST['magalu_site_categoria_id'] ?? 0));
    $message = 'Configurações da Magalu salvas com sucesso!';
    $messageType = 'success';
}

$magalu_automacao_ativa     = getConfig('magalu_automacao_ativa', '0') === '1';
$magalu_intervalo_minutos   = getConfig('magalu_intervalo_minutos', '20');
$magalu_hora_inicio         = getConfig('magalu_hora_inicio', '9');
$magalu_hora_fim            = getConfig('magalu_hora_fim', '20');
$magalu_site_url            = getConfig('magalu_site_url', '');
$magalu_cron_token          = getConfig('magalu_cron_token', '');
$magalu_lomadee_api_key     = getConfig('magalu_lomadee_api_key', '');
$magalu_openai_api_key      = getConfig('magalu_openai_api_key', '');
$magalu_openai_model        = getConfig('magalu_openai_model', 'gpt-4o-mini');
$magalu_evolution_url       = getConfig('magalu_evolution_url', '');
$magalu_evolution_instancia = getConfig('magalu_evolution_instancia', '');
$magalu_evolution_apikey    = getConfig('magalu_evolution_apikey', '');
$magalu_evolution_grupos    = getConfig('magalu_evolution_grupos', '');
$magalu_produtos_por_execucao = getConfig('magalu_produtos_por_execucao', '1');
$magalu_delay_entre_envios  = getConfig('magalu_delay_entre_envios', '10');
$magalu_site_publicar       = getConfig('magalu_site_publicar', '1') === '1';
$magalu_site_categoria_id   = getConfig('magalu_site_categoria_id', '');

$hi = (int) $magalu_hora_inicio;
$hf = (int) $magalu_hora_fim;
$im = (int) $magalu_intervalo_minutos;
if (!in_array($im, [5, 10, 15, 20, 30])) $im = 20;
$cronExpr = '*/' . $im . ' ' . $hi . '-' . $hf . ' * * *';
$cronUrl = '';
if (!empty($magalu_site_url)) {
    $base = rtrim($magalu_site_url, '/');
    $cronUrl = $base . '/cron/rodar-automacao-magalu.php';
    if (!empty($magalu_cron_token)) $cronUrl .= '?token=' . urlencode($magalu_cron_token);
}

$pdo = getDB();
$categorias = $pdo->query("SELECT id, nome FROM categorias WHERE ativo = 1 ORDER BY ordem")->fetchAll();

$pageTitle = 'Magalu';
require_once __DIR__ . '/includes/header.php';
?>
        <main class="flex-1 overflow-y-auto p-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-2">Magalu</h1>
            <p class="text-gray-600 mb-8">Configure a automação: API Lomadee (Magazine Luiza) → link afiliado → copy com IA → WhatsApp e site.</p>

            <?php if ($message): ?>
            <div class="mb-4 p-4 rounded <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <form method="POST" class="space-y-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Status da Automação</h2>
                    <label class="flex items-center gap-3">
                        <input type="checkbox" name="magalu_automacao_ativa" value="1" <?php echo $magalu_automacao_ativa ? 'checked' : ''; ?>
                               class="rounded border-gray-300 text-orange-500 focus:ring-orange-500 w-5 h-5">
                        <span class="text-gray-700">Automação ativa</span>
                    </label>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Agendamento</h2>
                    <p class="text-sm text-gray-600 mb-4">Ex.: a cada 20 min das 9h às 20h = <code>*/20 9-20 * * *</code></p>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label for="magalu_intervalo_minutos" class="block text-sm font-medium text-gray-700 mb-2">A cada (minutos)</label>
                            <select id="magalu_intervalo_minutos" name="magalu_intervalo_minutos"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                                <option value="5"  <?php echo $magalu_intervalo_minutos == '5'  ? 'selected' : ''; ?>>5 min</option>
                                <option value="10" <?php echo $magalu_intervalo_minutos == '10' ? 'selected' : ''; ?>>10 min</option>
                                <option value="15" <?php echo $magalu_intervalo_minutos == '15' ? 'selected' : ''; ?>>15 min</option>
                                <option value="20" <?php echo $magalu_intervalo_minutos == '20' ? 'selected' : ''; ?>>20 min</option>
                                <option value="30" <?php echo $magalu_intervalo_minutos == '30' ? 'selected' : ''; ?>>30 min</option>
                            </select>
                        </div>
                        <div>
                            <label for="magalu_hora_inicio" class="block text-sm font-medium text-gray-700 mb-2">Hora início (0–23)</label>
                            <input type="number" id="magalu_hora_inicio" name="magalu_hora_inicio" min="0" max="23" value="<?php echo htmlspecialchars($magalu_hora_inicio); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="magalu_hora_fim" class="block text-sm font-medium text-gray-700 mb-2">Hora fim (0–23)</label>
                            <input type="number" id="magalu_hora_fim" name="magalu_hora_fim" min="0" max="23" value="<?php echo htmlspecialchars($magalu_hora_fim); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                    </div>
                    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="magalu_site_url" class="block text-sm font-medium text-gray-700 mb-2">URL do site (para o cron)</label>
                            <input type="url" id="magalu_site_url" name="magalu_site_url" placeholder="https://seusite.com.br" value="<?php echo htmlspecialchars($magalu_site_url); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="magalu_cron_token" class="block text-sm font-medium text-gray-700 mb-2">Token do cron (opcional)</label>
                            <input type="text" id="magalu_cron_token" name="magalu_cron_token" placeholder="token_secreto" value="<?php echo htmlspecialchars($magalu_cron_token); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                    </div>
                    <div class="mt-4 p-4 bg-amber-50 border border-amber-200 rounded-md">
                        <p class="text-sm font-medium text-amber-800 mb-2">Cron sugerido:</p>
                        <p class="font-mono text-sm text-gray-700 break-all"><?php echo htmlspecialchars($cronExpr); ?> curl -s "<?php echo htmlspecialchars($cronUrl ?: '[URL]/cron/rodar-automacao-magalu.php?token=TOKEN]'); ?>" &gt; /dev/null 2&gt;&amp;1</p>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Lomadee – API Afiliados (Magalu)</h2>
                    <p class="text-sm text-gray-600 mb-4">API Key em <a href="https://www.lomadee.com.br" target="_blank" rel="noopener" class="text-orange-600 underline">lomadee.com.br</a>. Endpoints: GET /affiliate/products e POST /affiliate/shorten.</p>
                    <div>
                        <label for="magalu_lomadee_api_key" class="block text-sm font-medium text-gray-700 mb-2">API Key (x-api-key)</label>
                        <input type="password" id="magalu_lomadee_api_key" name="magalu_lomadee_api_key" placeholder="Sua chave da API Lomadee"
                               value="<?php echo htmlspecialchars($magalu_lomadee_api_key); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">OpenAI (copy Magalu para WhatsApp)</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="magalu_openai_api_key" class="block text-sm font-medium text-gray-700 mb-2">Chave da API OpenAI</label>
                            <input type="password" id="magalu_openai_api_key" name="magalu_openai_api_key" placeholder="sk-..." value="<?php echo htmlspecialchars($magalu_openai_api_key); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="magalu_openai_model" class="block text-sm font-medium text-gray-700 mb-2">Modelo</label>
                            <select id="magalu_openai_model" name="magalu_openai_model" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                                <option value="gpt-4o-mini" <?php echo $magalu_openai_model === 'gpt-4o-mini' ? 'selected' : ''; ?>>gpt-4o-mini</option>
                                <option value="gpt-4.1-mini" <?php echo $magalu_openai_model === 'gpt-4.1-mini' ? 'selected' : ''; ?>>gpt-4.1-mini</option>
                                <option value="gpt-4o" <?php echo $magalu_openai_model === 'gpt-4o' ? 'selected' : ''; ?>>gpt-4o</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Evolution API (WhatsApp)</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="magalu_evolution_url" class="block text-sm font-medium text-gray-700 mb-2">URL base</label>
                            <input type="url" id="magalu_evolution_url" name="magalu_evolution_url" placeholder="https://evolution.digitalavance.com.br" value="<?php echo htmlspecialchars($magalu_evolution_url); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div>
                            <label for="magalu_evolution_instancia" class="block text-sm font-medium text-gray-700 mb-2">Instância</label>
                            <input type="text" id="magalu_evolution_instancia" name="magalu_evolution_instancia" placeholder="ex: alexvivo" value="<?php echo htmlspecialchars($magalu_evolution_instancia); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div class="md:col-span-2">
                            <label for="magalu_evolution_apikey" class="block text-sm font-medium text-gray-700 mb-2">API Key</label>
                            <input type="password" id="magalu_evolution_apikey" name="magalu_evolution_apikey" value="<?php echo htmlspecialchars($magalu_evolution_apikey); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                        <div class="md:col-span-2">
                            <label for="magalu_evolution_grupos" class="block text-sm font-medium text-gray-700 mb-2">IDs dos grupos (um por linha ou vírgula)</label>
                            <textarea id="magalu_evolution_grupos" name="magalu_evolution_grupos" rows="3" placeholder="120363405115361555@g.us" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500"><?php echo htmlspecialchars($magalu_evolution_grupos); ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Comportamento</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="magalu_produtos_por_execucao" class="block text-sm font-medium text-gray-700 mb-2">Produtos por execução</label>
                            <select id="magalu_produtos_por_execucao" name="magalu_produtos_por_execucao" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo $magalu_produtos_por_execucao == (string)$i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div>
                            <label for="magalu_delay_entre_envios" class="block text-sm font-medium text-gray-700 mb-2">Delay entre envios (s)</label>
                            <input type="number" id="magalu_delay_entre_envios" name="magalu_delay_entre_envios" min="1" max="120" value="<?php echo htmlspecialchars($magalu_delay_entre_envios); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Publicar no site</h2>
                    <p class="text-sm text-gray-600 mb-4">Produtos em <strong>destaque</strong> e categoria automática (ou fixa).</p>
                    <label class="flex items-center gap-3 mb-4">
                        <input type="checkbox" name="magalu_site_publicar" value="1" <?php echo $magalu_site_publicar ? 'checked' : ''; ?> class="rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                        <span class="text-gray-700">Criar produto no site ao enviar no WhatsApp</span>
                    </label>
                    <div>
                        <label for="magalu_site_categoria_id" class="block text-sm font-medium text-gray-700 mb-2">Categoria fixa (opcional)</label>
                        <select id="magalu_site_categoria_id" name="magalu_site_categoria_id" class="w-full max-w-xs px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500">
                            <option value="0" <?php echo ($magalu_site_categoria_id === '' || $magalu_site_categoria_id === '0') ? 'selected' : ''; ?>>Nenhuma</option>
                            <?php foreach ($categorias as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>" <?php echo $magalu_site_categoria_id === (string)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
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
            fetch('executar-automacao-magalu.php', { method: 'POST', credentials: 'same-origin' }).then(function(r){ return r.json(); }).then(function(d){
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

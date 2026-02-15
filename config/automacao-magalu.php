<?php
/**
 * Automação Magalu (Magazine Luiza): API Lomadee (produtos + shorten) → IA (copy) → Evolution (WhatsApp) e site.
 * Substitui o fluxo n8n. Requer automacao-ml para: baixarEConverterImagemBase64, enviarWhatsAppEvolution,
 * salvarProdutoNoSite, obterOuCriarCategoriaParaProduto.
 *
 * Retorna: ['success'=>bool, 'message'=>string, 'details'=>array, 'errors'=>array]
 */
if (!defined('AUTOMACAO_MAGALU_LOADED')) {
    define('AUTOMACAO_MAGALU_LOADED', true);
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/automacao-ml.php';

const LOMADEE_BASE = 'https://api-beta.lomadee.com.br';

function runAutomacaoMagalu($forcarExecucao = false) {
    $details = [];
    $errors = [];

    $ativa        = $forcarExecucao || (getConfig('magalu_automacao_ativa', '0') === '1');
    $lomadeeKey   = trim(getConfig('magalu_lomadee_api_key', ''));
    $openaiKey    = trim(getConfig('magalu_openai_api_key', ''));
    $openaiModel  = trim(getConfig('magalu_openai_model', 'gpt-4o-mini'));
    $evUrl        = rtrim(getConfig('magalu_evolution_url', ''), '/');
    $evInst       = getConfig('magalu_evolution_instancia', '');
    $evKey        = getConfig('magalu_evolution_apikey', '');
    $evGrupos     = getConfig('magalu_evolution_grupos', '');
    $qtd          = max(1, min(10, (int) getConfig('magalu_produtos_por_execucao', '1')));
    $delay        = max(1, min(120, (int) getConfig('magalu_delay_entre_envios', '10')));
    $publicarSite = getConfig('magalu_site_publicar', '1') === '1';

    $grupos = array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', $evGrupos))));

    if (!$ativa) {
        return ['success' => false, 'message' => 'Automação Magalu desativada nas configurações.', 'details' => $details, 'errors' => $errors];
    }
    if (empty($lomadeeKey)) {
        $errors[] = 'Magalu: preencha a API Key da Lomadee.';
    }
    if (empty($openaiKey)) {
        $errors[] = 'OpenAI: informe a chave da API.';
    }
    if (empty($evUrl) || empty($evInst) || empty($evKey)) {
        $errors[] = 'Evolution API: informe URL base, instância e API Key.';
    }
    if (empty($grupos)) {
        $errors[] = 'Evolution: informe ao menos um grupo ou número.';
    }
    if (!empty($errors)) {
        return ['success' => false, 'message' => 'Configure os campos obrigatórios na página Magalu.', 'details' => $details, 'errors' => $errors];
    }

    // 1) GET /affiliate/products
    $ch = curl_init(LOMADEE_BASE . '/affiliate/products?limit=50&page=1');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['x-api-key: ' . $lomadeeKey],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $res = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        $errors[] = 'Lomadee produtos HTTP ' . $httpCode;
        return ['success' => false, 'message' => 'Falha ao buscar produtos na API Lomadee.', 'details' => $details, 'errors' => $errors];
    }

    $json = @json_decode($res, true);
    $produtos = [];
    if (isset($json['data']) && is_array($json['data'])) {
        $produtos = $json['data'];
    } elseif (isset($json['products']) && is_array($json['products'])) {
        $produtos = $json['products'];
    } elseif (is_array($json)) {
        $produtos = $json;
    }

    $validos = [];
    foreach ($produtos as $p) {
        $nome   = trim((string) ($p['name'] ?? $p['title'] ?? ''));
        $preco  = $p['price'] ?? $p['currentPrice'] ?? null;
        $preco  = $preco !== null ? (float) $preco : null;
        $img    = trim((string) ($p['image'] ?? $p['imageUrl'] ?? $p['thumbnail'] ?? ''));
        $url    = trim((string) ($p['url'] ?? $p['link'] ?? $p['productUrl'] ?? ''));
        if ($nome !== '' && $preco !== null && $preco > 0 && $img !== '' && $url !== '') {
            $validos[] = [
                'nome'           => $nome,
                'preco_atual'    => $preco,
                'preco_anterior' => isset($p['oldPrice']) || isset($p['previousPrice']) ? (float) ($p['oldPrice'] ?? $p['previousPrice'] ?? 0) : null,
                'imagem'         => $img,
                'url'            => $url,
                'desconto'       => isset($p['discount']) ? (int) $p['discount'] : null,
            ];
        }
    }

    $details['produtos_api'] = count($produtos);
    $details['produtos_validos'] = count($validos);
    if (empty($validos)) {
        return ['success' => false, 'message' => 'Nenhum produto válido (nome, preço, imagem, url) na API Lomadee.', 'details' => $details, 'errors' => $errors];
    }

    shuffle($validos);
    $validos = array_slice($validos, 0, $qtd);

    $enviados = 0;
    $errosProduto = [];
    $details['produtos_site'] = [];

    foreach ($validos as $idx => $p) {
        $nome   = $p['nome'];
        $preco  = $p['preco_atual'];
        $precoAnt = $p['preco_anterior'];
        $img    = $p['imagem'];
        $url    = $p['url'];
        $desconto = $p['desconto'];

        if ($desconto === null && $precoAnt > 0 && $preco > 0 && $precoAnt > $preco && function_exists('calcularDesconto')) {
            $desconto = (int) round(calcularDesconto($precoAnt, $preco));
        }
        $desconto = $desconto !== null ? (int) $desconto : 0;

        // 2) POST /affiliate/shorten
        $linkAfiliado = lomadeeShorten($url, $lomadeeKey, $err);
        if (!empty($err)) {
            $errosProduto[] = 'Produto "' . mb_substr($nome, 0, 40) . '...": ' . $err;
            continue;
        }
        if (empty($linkAfiliado)) {
            $linkAfiliado = $url;
        }

        // 3) Preparar prompt e OpenAI
        $precoTexto = $precoAnt > 0
            ? ('De R$ ' . number_format($precoAnt, 2, ',', '.') . ' por R$ ' . number_format($preco, 2, ',', '.'))
            : ('R$ ' . number_format($preco, 2, ',', '.'));
        if ($desconto > 0) {
            $precoTexto .= ', ' . $desconto . '%';
        }
        $promptUser = $nome . ', ' . $precoTexto . ', ' . $linkAfiliado;

        $copy = gerarCopyMagaluOpenAI($promptUser, $openaiKey, $openaiModel, $err);
        if (!empty($err)) {
            $errosProduto[] = 'Produto "' . mb_substr($nome, 0, 40) . '...": ' . $err;
            continue;
        }
        $mensagem = formatarMensagemMagaluWhatsApp($copy);

        // 4) Imagem → Base64
        $imgB64 = baixarEConverterImagemBase64($img);

        // 5) Publicar no site
        if ($publicarSite) {
            $precoOrig = $precoAnt > 0 ? $precoAnt : null;
            $id = salvarProdutoNoSite($nome, '', $linkAfiliado, $img, $errProd, $preco, $precoOrig, $desconto, 'magalu');
            if ($id) {
                $details['produtos_site'][] = ['id' => $id, 'nome' => mb_substr($nome, 0, 50)];
            } elseif (!empty($errProd)) {
                $errosProduto[] = 'Site: ' . $errProd;
            }
        }

        // 6) Evolution: cada grupo
        foreach ($grupos as $g) {
            $ok = enviarWhatsAppEvolution($evUrl, $evInst, $evKey, $g, $mensagem, $imgB64, $err);
            if ($ok) {
                $enviados++;
            } else {
                $errosProduto[] = 'WhatsApp grupo ' . $g . ': ' . $err;
            }
            if (count($grupos) > 1) {
                sleep((int) $delay);
            }
        }
    }

    $errors = array_merge($errors, $errosProduto);
    $details['produtos_processados'] = count($validos);
    $details['mensagens_enviadas'] = $enviados;
    $nSite = count($details['produtos_site'] ?? []);

    if ($enviados > 0) {
        $msg = 'Automação Magalu concluída. ' . $enviados . ' mensagem(ns) enviada(s).';
        if ($nSite > 0) {
            $msg .= ' ' . $nSite . ' produto(s) criado(s) no site.';
        }
        return ['success' => true, 'message' => $msg, 'details' => $details, 'errors' => $errors];
    }
    return ['success' => false, 'message' => 'Nenhuma mensagem enviada. Verifique as configurações e os erros.', 'details' => $details, 'errors' => $errors];
}

function lomadeeShorten($url, $apiKey, &$err) {
    $err = '';
    $body = json_encode(['url' => $url]);
    $ch = curl_init(LOMADEE_BASE . '/affiliate/shorten');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        $err = 'Lomadee shorten HTTP ' . $code;
        return '';
    }
    $j = @json_decode($res, true);
    if (isset($j['shortUrl']) && $j['shortUrl'] !== '') return trim($j['shortUrl']);
    if (isset($j['url']) && $j['url'] !== '') return trim($j['url']);
    if (isset($j['data']['shortUrl']) && $j['data']['shortUrl'] !== '') return trim($j['data']['shortUrl']);
    return '';
}

function gerarCopyMagaluOpenAI($promptUser, $apiKey, $model, &$err) {
    $err = '';
    $sys = '## AGENTE DE COPYWRITING MAGALU - ALTA CONVERSÃO

**<AgentInstructions>**

**<Função>**
  **<Nome>** Especialista em Ofertas Magazine Luiza
  **<Descrição>** Cria textos persuasivos e urgentes para ofertas do Magalu no WhatsApp usando gatilhos mentais poderosos.

**<Meta>**
  **<Objetivo>** Gerar cliques imediatos através de urgência, escassez e formatação visual impactante.

**<TomDeVoz>**
  **<Estilo>** Alarmista positivo, Urgente, Exclusivo, Brasileiro
  **<Características>** Frases curtas, imperativos, transmite que a oportunidade vai acabar.

**<EstruturaDaMensagem>**
  **1.** Título URGENTE (Negrito + 2 Emojis relevantes)
  **2.** Nome do produto (Negrito + Itálico)
  **3.** Bloco de Preço (Antigo riscado com ❌ e Novo em destaque)
  **4.** Percentual de desconto (se houver)
  **5.** Descrição persuasiva (2 linhas em itálico - SEM bullet points)
  **6.** Call-to-action urgente
  **7.** Link de afiliado
  **8.** Footer de fechamento

**<InstruçõesDeFormatação>**

  **<Titulo>**
    Crie título de 3-5 palavras que gere choque/curiosidade.
    Exemplos:
    - **HOJE É O DIA! 🔥💰**
    - **APROVEITA AGORA! 😱⚡**
    - **MAGALU LIBEROU! 🎉🛒**

  **<Produto>**
    Nome completo em **_Negrito e Itálico_**

  **<Precos>**
    OBRIGATÓRIO seguir este layout EXATO:
    ❌ ~R$ [Preço Antigo]~ ❌
    💰 **POR APENAS R$ [Preço Novo]**
    💥 **[X]% DE DESCONTO**

  **<Descricao>**
    - NÃO use listas (✅, •, checkmarks)
    - Escreva 2 linhas em _itálico_
    - Foque no benefício/desejo que o produto resolve

  **<CTA>**
    Use ordem imperativa + emoji de ação:
    - 👉 **CLIQUE AQUI ANTES QUE ACABE:**
    - 🛒 **GARANTA O SEU AGORA:**
    - ⚡ **CORRE QUE ESTÁ ACABANDO:**

  **<Footer>**
    - 🔥 Oferta Exclusiva do Grupo
    - ⏰ Válido Somente Hoje
    - 🎯 Estoque Limitado

**<Restrições_CRÍTICAS>**
  - NUNCA use listas com marcadores na descrição
  - SEMPRE coloque ❌ antes E depois do preço antigo
  - CTA DEVE ser ordem imperativa e urgente
  - Use linguagem brasileira natural
  - INCLUA o link de afiliado no texto (o prompt do usuário contém: nome, preços, link).

**</AgentInstructions>';

    $body = [
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => $promptUser],
        ],
        'temperature' => 0.4,
    ];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        $err = 'OpenAI HTTP ' . $code . '. Verifique a chave e o modelo.';
        return '';
    }
    $j = @json_decode($res, true);
    $txt = trim($j['choices'][0]['message']['content'] ?? '');
    if ($txt === '') {
        $err = 'OpenAI respondeu vazio.';
        return '';
    }
    return $txt;
}

/**
 * Converte markdown da IA para formatação WhatsApp. A IA já inclui o link; não acrescentamos no final.
 */
function formatarMensagemMagaluWhatsApp($copy) {
    $t = $copy;
    $t = preg_replace('/\[.*?\]\(.*?\)/s', '', $t);
    $t = preg_replace('/\n{3,}/', "\n\n", $t);
    $t = trim($t);
    $t = preg_replace('/\*\*(.*?)\*\*/s', '*$1*', $t);
    $t = preg_replace('/\*(.*?)\*/s', '_$1_', $t);
    $t = preg_replace('/~~(.*?)~~/s', '~$1~', $t);
    return trim($t);
}

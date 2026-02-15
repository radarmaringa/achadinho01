<?php
/**
 * Automação Shopee: API Afiliados → filtrar/randomizar → OpenAI (copy) → Evolution (WhatsApp) e site.
 * Substitui o fluxo n8n. Requer automacao-ml para: baixarEConverterImagemBase64, enviarWhatsAppEvolution,
 * salvarProdutoNoSite, obterOuCriarCategoriaParaProduto.
 *
 * Retorna: ['success'=>bool, 'message'=>string, 'details'=>array, 'errors'=>array]
 */
if (!defined('AUTOMACAO_SHOPEE_LOADED')) {
    define('AUTOMACAO_SHOPEE_LOADED', true);
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/automacao-ml.php';

const SHOPEE_GRAPHQL_QUERY = 'query productOfferV2($page:Int, $limit:Int){productOfferV2(page:$page, limit:$limit){nodes{productName,itemId,commissionRate,commission,price,sales,imageUrl,shopName,productLink,offerLink,periodStartTime,periodEndTime,priceMin,priceMax,productCatIds,ratingStar,priceDiscountRate,shopId,shopType,sellerCommissionRate,shopeeCommissionRate},pageInfo{page,limit,hasNextPage,scrollId}}}}';

function runAutomacaoShopee($forcarExecucao = false) {
    $details = [];
    $errors = [];

    $ativa      = $forcarExecucao || (getConfig('shopee_automacao_ativa', '0') === '1');
    $appId      = trim(getConfig('shopee_app_id', ''));
    $secret     = trim(getConfig('shopee_secret', ''));
    $openaiKey  = trim(getConfig('shopee_openai_api_key', ''));
    $openaiModel = trim(getConfig('shopee_openai_model', 'gpt-4o-mini'));
    $evUrl      = rtrim(getConfig('shopee_evolution_url', ''), '/');
    $evInst     = getConfig('shopee_evolution_instancia', '');
    $evKey      = getConfig('shopee_evolution_apikey', '');
    $evGrupos   = getConfig('shopee_evolution_grupos', '');
    $qtd        = max(1, min(10, (int)getConfig('shopee_produtos_por_execucao', '1')));
    $delay      = max(1, min(120, (int)getConfig('shopee_delay_entre_envios', '10')));
    $publicarSite = getConfig('shopee_site_publicar', '1') === '1';

    $grupos = array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', $evGrupos))));

    if (!$ativa) {
        return ['success' => false, 'message' => 'Automação Shopee desativada nas configurações.', 'details' => $details, 'errors' => $errors];
    }
    if (empty($appId) || empty($secret)) {
        $errors[] = 'Shopee: preencha App ID e Secret.';
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
        return ['success' => false, 'message' => 'Configure os campos obrigatórios na página Shopee.', 'details' => $details, 'errors' => $errors];
    }

    // 1) Chamar API GraphQL Shopee
    $payload = ['query' => SHOPEE_GRAPHQL_QUERY, 'variables' => ['page' => 1, 'limit' => 50]];
    $payloadStr = json_encode($payload);
    $timestamp = (string) time();
    $signStr = $appId . $timestamp . $payloadStr . $secret;
    $signature = hash('sha256', $signStr);

    $ch = curl_init('https://open-api.affiliate.shopee.com.br/graphql');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payloadStr,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: SHA256 Credential=' . $appId . ', Timestamp=' . $timestamp . ', Signature=' . $signature,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        $errors[] = 'Shopee API HTTP ' . $httpCode;
        return ['success' => false, 'message' => 'Falha ao buscar produtos na API Shopee.', 'details' => $details, 'errors' => $errors];
    }

    $json = @json_decode($res, true);
    $nodes = $json['data']['productOfferV2']['nodes'] ?? [];
    $details['produtos_api'] = count($nodes);
    if (empty($nodes)) {
        return ['success' => false, 'message' => 'Nenhum produto retornado pela API Shopee.', 'details' => $details, 'errors' => $errors];
    }

    // 2) Filtrar válidos: productName, imageUrl, offerLink não vazios; offerLink começa com https://
    $validos = [];
    foreach ($nodes as $n) {
        $nome = trim((string)($n['productName'] ?? ''));
        $img  = trim((string)($n['imageUrl'] ?? ''));
        $link = trim((string)($n['offerLink'] ?? ''));
        if ($nome !== '' && $img !== '' && $link !== '' && strpos($link, 'https://') === 0) {
            $validos[] = $n;
        }
    }
    $details['produtos_validos'] = count($validos);
    if (empty($validos)) {
        return ['success' => false, 'message' => 'Nenhum produto válido (nome, imagem, link) após filtro.', 'details' => $details, 'errors' => $errors];
    }

    shuffle($validos);
    $validos = array_slice($validos, 0, $qtd);

    $enviados = 0;
    $errosProduto = [];
    $details['produtos_site'] = [];

    foreach ($validos as $idx => $n) {
        $productName   = trim((string)($n['productName'] ?? ''));
        $imageUrl      = trim((string)($n['imageUrl'] ?? ''));
        $offerLink     = trim((string)($n['offerLink'] ?? ''));
        $price         = (float)($n['price'] ?? 0);
        $priceDiscRate = (float)($n['priceDiscountRate'] ?? 0);
        $sales         = (int)($n['sales'] ?? 0);
        $ratingStar     = (float)($n['ratingStar'] ?? 0);

        $preco_original = null;
        $desconto = 0;
        if ($priceDiscRate > 0 && $price > 0) {
            $preco_original = $price / (1 - $priceDiscRate / 100);
            $desconto = (int) round($priceDiscRate);
        }

        $precoOriginalStr = $preco_original !== null ? 'R$ ' . number_format($preco_original, 2, ',', '.') : '';
        $precoStr         = 'R$ ' . number_format($price, 2, ',', '.');
        $percentualStr    = $desconto > 0 ? (string) $desconto : '';
        $vendasStr        = (string) $sales;
        $avaliacaoStr     = $ratingStar > 0 ? number_format($ratingStar, 1, '.', '') : '';

        // 3) OpenAI – copy Shopee para WhatsApp
        $copy = gerarCopyShopeeOpenAI($productName, $precoOriginalStr, $precoStr, $percentualStr, $offerLink, $vendasStr, $avaliacaoStr, $openaiKey, $openaiModel, $err);
        if (!empty($err)) {
            $errosProduto[] = 'Produto "' . mb_substr($productName, 0, 40) . '...": ' . $err;
            continue;
        }
        $mensagem = formatarMensagemShopeeWhatsApp($copy, $offerLink);

        // 4) Imagem → Base64 (reutiliza automacao-ml)
        $imgB64 = baixarEConverterImagemBase64($imageUrl);

        // 5) Publicar no site (reutiliza automacao-ml com prefix shopee)
        if ($publicarSite) {
            $id = salvarProdutoNoSite($productName, '', $offerLink, $imageUrl, $errProd, $price, $preco_original, $desconto, 'shopee');
            if ($id) {
                $details['produtos_site'][] = ['id' => $id, 'nome' => mb_substr($productName, 0, 50)];
            } elseif (!empty($errProd)) {
                $errosProduto[] = 'Site: ' . $errProd;
            }
        }

        // 6) Evolution: enviar para cada grupo
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
        $msg = 'Automação Shopee concluída. ' . $enviados . ' mensagem(ns) enviada(s).';
        if ($nSite > 0) {
            $msg .= ' ' . $nSite . ' produto(s) criado(s) no site.';
        }
        return ['success' => true, 'message' => $msg, 'details' => $details, 'errors' => $errors];
    }
    return ['success' => false, 'message' => 'Nenhuma mensagem enviada. Verifique as configurações e os erros.', 'details' => $details, 'errors' => $errors];
}

function gerarCopyShopeeOpenAI($nome, $precoOriginal, $preco, $percentualDesconto, $link, $vendas, $avaliacao, $apiKey, $model, &$err) {
    $err = '';
    $sys = "Você é especialista em copywriting para WhatsApp da SHOPEE.\n\n" .
        "🎯 ESTRUTURA OBRIGATÓRIA:\n\n" .
        "1. 🔥 **TÍTULO EM NEGRITO E CAIXA ALTA** (com 2-3 emojis)\n" .
        "2. *Nome do produto em itálico*\n" .
        "3. ❌ ~~Preço original riscado~~ (se houver)\n" .
        "4. 💚 **Preço promocional em negrito**\n" .
        "5. 💥 **% OFF em negrito** (se houver)\n" .
        "6. ✅ 2-3 benefícios principais\n" .
        "7. 📊 Prova social (vendas/avaliação se > 0)\n" .
        "8. **👉 CALL-TO-ACTION em negrito**\n\n" .
        "⚠️ REGRAS CRÍTICAS:\n\n" .
        "- Máximo 12 linhas\n" .
        "- NUNCA coloque o link no texto - ele será adicionado automaticamente depois\n" .
        "- NUNCA use formatação [texto](https://...)\n" .
        "- Use apenas emojis, negrito e itálico\n" .
        "- Se preço original vazio, omita essa linha\n" .
        "- Se avaliação for N/A ou 0.0, omita prova social\n" .
        "- SEMPRE termine com frase de exclusividade em negrito";

    $user = "{$nome}, {$precoOriginal}, {$preco}, {$percentualDesconto}, {$link}, vendas: {$vendas}, avaliação: {$avaliacao}";

    $body = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => $user],
        ],
        'temperature' => 0.4,
    ];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 60,
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

function formatarMensagemShopeeWhatsApp($copy, $linkAfiliado) {
    $t = $copy;
    $t = preg_replace('/\[.*?\]\(.*?\)/s', '', $t);
    $t = preg_replace('/https?:\/\/[^\s]+/u', '', $t);
    $t = preg_replace('/🔥\s*\*\*Oferta exclusiva.*?\*\*/iu', '', $t);
    $t = preg_replace('/👉\s*\*\*.*?garant[ea].*?\*\*/iu', '', $t);
    $t = preg_replace('/\n{3,}/', "\n\n", $t);
    $t = trim($t);
    $t = preg_replace('/\*\*(.*?)\*\*/s', '*$1*', $t);
    $t = preg_replace('/\*(.*?)\*/s', '_$1_', $t);
    $t = trim($t);
    return $t . "\n\n#️⃣ *Aproveite enquanto está disponível!👇*\n\n🔗 " . $linkAfiliado;
}

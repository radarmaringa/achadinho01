<?php
/**
 * Lógica da automação Mercado Livre
 * Scraping ofertas → createLink (afiliado) → OpenAI (copy) → Evolution (WhatsApp)
 * 
 * Retorna: ['success'=>bool, 'message'=>string, 'details'=>array, 'errors'=>array]
 */
if (!defined('AUTOMACAO_ML_LOADED')) {
    define('AUTOMACAO_ML_LOADED', true);
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

function runAutomacaoML($forcarExecucao = false) {
    $details = [];
    $errors = [];
    $urlOfertas = 'https://www.mercadolivre.com.br/ofertas';

    // 1) Config e validações
    $ativa   = $forcarExecucao || (getConfig('ml_automacao_ativa', '0') === '1');
    $tag     = getConfig('ml_tag_afiliado', '');
    $csrf    = getConfig('ml_csrf_token', '');
    $cookie  = getConfig('ml_cookie', '');
    $openaiKey = getConfig('ml_openai_api_key', '');
    $openaiModel = getConfig('ml_openai_model', 'gpt-4.1-mini');
    $evUrl   = rtrim(getConfig('ml_evolution_url', ''), '/');
    $evInst  = getConfig('ml_evolution_instancia', '');
    $evKey   = getConfig('ml_evolution_apikey', '');
    $evGrupos = getConfig('ml_evolution_grupos', '');
    $qtd     = max(1, min(10, (int)getConfig('ml_produtos_por_execucao', '1')));
    $delay   = max(1, min(120, (int)getConfig('ml_delay_entre_envios', '10')));

    if (!$ativa) {
        return ['success' => false, 'message' => 'Automação desativada nas configurações.', 'details' => $details, 'errors' => $errors];
    }
    if (empty($tag) || empty($csrf) || empty($cookie)) {
        $errors[] = 'Mercado Livre: preencha Tag, x-csrf-token e Cookie.';
    }
    if (empty($openaiKey)) {
        $errors[] = 'OpenAI: informe a chave da API.';
    }
    if (empty($evUrl) || empty($evInst) || empty($evKey)) {
        $errors[] = 'Evolution API: informe URL base, instância e API Key.';
    }
    if (empty(trim(str_replace(["\r","\n",","], '', $evGrupos)))) {
        $errors[] = 'Evolution: informe ao menos um grupo ou número.';
    }
    if (!empty($errors)) {
        return ['success' => false, 'message' => 'Configure os campos obrigatórios na página Mercado Livre.', 'details' => $details, 'errors' => $errors];
    }

    $grupos = array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', $evGrupos))));

    // 2) Fetch ofertas ML
    $html = httpGet($urlOfertas, $errors);
    if ($html === false || $html === '') {
        $errors[] = 'Não foi possível acessar a página de ofertas do Mercado Livre.';
        return ['success' => false, 'message' => 'Falha ao acessar ofertas do ML.', 'details' => $details, 'errors' => $errors];
    }
    $details['ofertas_bytes'] = strlen($html);

    // 3) Extrair produtos (DOM)
    $produtos = extrairProdutosOfertas($html);
    $details['produtos_extraidos'] = count($produtos);
    if (empty($produtos)) {
        return ['success' => false, 'message' => 'Nenhum produto encontrado na página de ofertas (estrutura do HTML pode ter mudado).', 'details' => $details, 'errors' => $errors];
    }

    shuffle($produtos);
    // Remover duplicatas na mesma lista (mesmo link)
    $seen = [];
    $produtos = array_values(array_filter($produtos, function ($p) use (&$seen) {
        $u = trim($p['link_compra'] ?? '');
        if ($u === '') return false;
        $n = preg_match('#^//#', $u) ? 'https:' . $u : (preg_match('#^/#', $u) ? 'https://www.mercadolivre.com.br' . $u : $u);
        if (isset($seen[$n])) return false;
        $seen[$n] = true;
        return true;
    }));
    // Pegar mais candidatos para, ao pular já publicados, ainda atingir qtd
    $maxCand = max((int)$qtd * 3, 15);
    $produtos = array_slice($produtos, 0, $maxCand);

    $enviados = 0;
    $processados = 0;
    $errosProduto = [];
    $details['produtos_site'] = [];
    $details['repetidos_ignorados'] = 0;
    $pdo = getDB();

    foreach ($produtos as $idx => $p) {
        if ($processados >= $qtd) break;

        $linkCompra = trim($p['link_compra'] ?? '');
        $nome       = $p['nome'] ?? '';
        $preco      = $p['preco'] ?? '';
        $imagemUrl  = $p['imagem'] ?? '';
        if (empty($linkCompra) || empty($nome)) {
            $errosProduto[] = "Produto #" . ($idx+1) . ": sem link ou nome.";
            continue;
        }

        // Normalizar URL para checagem (ex.: //... ou /path)
        $linkNorm = $linkCompra;
        if (preg_match('#^//#', $linkNorm)) $linkNorm = 'https:' . $linkNorm;
        elseif (preg_match('#^/#', $linkNorm)) $linkNorm = 'https://www.mercadolivre.com.br' . $linkNorm;

        // 3b) Evitar repetidos: não publicar de novo no site nem no WhatsApp
        $jaPublicado = false;
        try {
            // Verificar por link
            $st = $pdo->prepare("SELECT 1 FROM produtos_ja_publicados WHERE link_origem = ? LIMIT 1");
            $st->execute([$linkNorm]);
            $jaPublicado = (bool)$st->fetch();
            
            // Verificar também por nome similar (primeiras 50 chars)
            if (!$jaPublicado && !empty($nome)) {
                $nomeNorm = mb_substr(trim($nome), 0, 50);
                $st2 = $pdo->prepare("SELECT 1 FROM produtos WHERE SUBSTRING(nome, 1, 50) = ? LIMIT 1");
                $st2->execute([$nomeNorm]);
                $jaPublicado = (bool)$st2->fetch();
            }
        } catch (Exception $e) { 
            // Tabela pode não existir; rodar migrations/add_produtos_ja_publicados.sql
            error_log("Erro ao verificar produto repetido: " . $e->getMessage());
        }
        if ($jaPublicado) {
            $details['repetidos_ignorados'] = ($details['repetidos_ignorados'] ?? 0) + 1;
            continue;
        }

        // 4) createLink (afiliado)
        $linkAfiliado = createLinkAfiliadoML($linkCompra, $tag, $csrf, $cookie, $err);
        if (!empty($err)) {
            $errosProduto[] = "Produto \"".mb_substr($nome,0,40)."...\": " . $err;
            continue;
        }
        if (empty($linkAfiliado)) {
            $errosProduto[] = "Produto \"".mb_substr($nome,0,40)."...\": createLink não retornou short_url.";
            continue;
        }

        // 5) OpenAI
        $copy = gerarCopyOpenAI($nome, $preco, $linkAfiliado, $openaiKey, $openaiModel, $err);
        if (!empty($err)) {
            $errosProduto[] = "Produto \"".mb_substr($nome,0,40)."...\": " . $err;
            continue;
        }
        $mensagem = formatarMensagemWhatsApp($copy, $linkAfiliado);

        // 6) Imagem → Base64
        $imgB64 = null;
        if (!empty($imagemUrl)) {
            $imgB64 = baixarEConverterImagemBase64($imagemUrl);
        }

        // 6b) Publicar no site de ofertas (imagem, nome, link de afiliado)
        $publicarSite = getConfig('ml_site_publicar', '1') === '1';
        if ($publicarSite) {
            $id = salvarProdutoNoSite($nome, $preco, $linkAfiliado, $imagemUrl, $errProd);
            if ($id) {
                $details['produtos_site'][] = ['id' => $id, 'nome' => mb_substr($nome, 0, 50)];
            } elseif (!empty($errProd)) {
                $errosProduto[] = "Site: " . $errProd;
            }
        }

        // 7) Evolution: enviar para cada grupo
        foreach ($grupos as $g) {
            $ok = enviarWhatsAppEvolution($evUrl, $evInst, $evKey, $g, $mensagem, $imgB64, $err);
            if ($ok) {
                $enviados++;
            } else {
                $errosProduto[] = "WhatsApp grupo " . $g . ": " . $err;
            }
            if (count($grupos) > 1) {
                sleep((int)$delay);
            }
        }

        // Registrar como já publicado para não repetir no site nem no WhatsApp
        try {
            $ins = $pdo->prepare("INSERT IGNORE INTO produtos_ja_publicados (link_origem) VALUES (?)");
            $ins->execute([$linkNorm]);
        } catch (Exception $e) { /* tabela pode não existir; seguir */ }
        $processados++;
    }

    $errors = array_merge($errors, $errosProduto);
    $details['produtos_processados'] = $processados;
    $details['mensagens_enviadas'] = $enviados;
    $nSite = count($details['produtos_site'] ?? []);

    if ($enviados > 0) {
        $msg = 'Automação concluída. ' . $enviados . ' mensagem(ns) enviada(s).';
        if ($nSite > 0) $msg .= ' ' . $nSite . ' produto(s) criado(s) no site.';
        return ['success' => true, 'message' => $msg, 'details' => $details, 'errors' => $errors];
    }
    return ['success' => false, 'message' => 'Nenhuma mensagem enviada. Verifique as configurações e os erros.', 'details' => $details, 'errors' => $errors];
}

function httpGet($url, &$errors, $headers = []) {
    $ch = curl_init($url);
    if (!$ch) return false;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => array_merge(['Accept: text/html,application/xhtml+xml'], $headers),
    ]);
    $body = curl_exec($ch);
    $errNo = curl_errno($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errNo) {
        $errors[] = 'cURL: ' . $errNo;
        return false;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        $errors[] = 'HTTP ' . $httpCode;
        return false;
    }
    return $body;
}

function extrairProdutosOfertas($html) {
    $out = [];
    $dom = @new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);
    // Card: .poly-card ou similar; dentro: .poly-card__content (nome, preço, link) e .poly-card__portada (img)
    $cards = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' poly-card__content ')]");
    if ($cards->length === 0) {
        $cards = $xpath->query("//*[contains(@class,'poly-card__content')]");
    }
    if ($cards->length === 0) {
        $cards = $xpath->query("//*[contains(@class,'andes-card')]");
    }
    for ($i = 0; $i < $cards->length; $i++) {
        $content = $cards->item($i);
        $card = $content->parentNode;
        $a = $xpath->query(".//h3//a | .//a[.//h3] | .//h2//a | .//a[.//h2]", $content)->item(0);
        $nome = $a ? trim($a->textContent) : '';
        $href = $a ? trim($a->getAttribute('href') ?: '') : '';
        if (empty($href) && $a) {
            $href = trim($a->getAttribute('href') ?: '');
        }
        $priceNode = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' poly-component__price ')] | .//*[contains(@class,'price')] | .//*[contains(@class,'andes-money-amount')]", $content)->item(0);
        $preco = $priceNode ? trim($priceNode->textContent) : '';
        $img = null;
        $imgs = $xpath->query(".//img[@data-src or @src]", $content);
        if ($imgs->length === 0 && $card) $imgs = $xpath->query(".//img[@data-src or @src]", $card);
        $img = $imgs->length ? $imgs->item(0) : null;
        $imgUrl = '';
        if ($img) {
            $imgUrl = trim($img->getAttribute('data-src') ?: $img->getAttribute('src') ?: '');
        }
        if (empty($nome) && empty($href)) continue;
        $out[] = ['nome' => $nome, 'preco' => $preco, 'link_compra' => $href, 'imagem' => $imgUrl];
    }
    return $out;
}

function createLinkAfiliadoML($url, $tag, $csrf, $cookie, &$err) {
    $err = '';
    $api = 'https://www.mercadolivre.com.br/affiliate-program/api/v2/affiliates/createLink';
    $body = json_encode(['urls' => [$url], 'tag' => $tag]);
    $h = [
        'Content-Type: application/json',
        'Accept: application/json, text/plain, */*',
        'Origin: https://www.mercadolivre.com.br',
        'Referer: https://www.mercadolivre.com.br/afiliados/linkbuilder',
        'x-csrf-token: ' . $csrf,
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Cookie: ' . $cookie,
    ];
    $ch = curl_init($api);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        $err = 'createLink HTTP ' . $code . '. Atualize CSRF e Cookie se estiver 401/403.';
        return '';
    }
    $j = @json_decode($res, true);
    if (!$j || empty($j['urls'][0]['short_url'])) {
        $err = 'createLink: short_url não encontrado na resposta.';
        return '';
    }
    return $j['urls'][0]['short_url'];
}

function gerarCopyOpenAI($nome, $preco, $link, $apiKey, $model, &$err) {
    $err = '';
    $sys = "Você é um especialista em copy para promoções no WhatsApp (Mercado Livre/outlet). Crie mensagens curtas (máx. 12 linhas), com gancho, nome em *negrito*, preço (~~antigo~~ → *atual*), % de desconto em *negrito*, 3 benefícios com ✅, CTA em *negrito*, e link. Use formatação WhatsApp: *texto* e ~~riscado~~. Emojis com moderação. Nunca invente parcelamento; omita se não tiver certeza. Foco em conversão.";
    $user = "Produto: {$nome}. Preço: {$preco}. Link de afiliado (NÃO inclua na sua resposta, será adicionado depois): {$link}. Gere apenas o corpo da mensagem, sem o link no final.";
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
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        $err = 'OpenAI HTTP ' . $code . '. Verifique a chave e o modelo.';
        return '';
    }
    $j = @json_decode($res, true);
    $txt = $j['choices'][0]['message']['content'] ?? '';
    if (trim($txt) === '') {
        $err = 'OpenAI respondeu vazio.';
        return '';
    }
    return $txt;
}

function formatarMensagemWhatsApp($copy, $linkAfiliado) {
    $t = $copy;
    $t = preg_replace('/\[.*?\]\(.*?\)/s', '', $t);
    $t = preg_replace('/https?:\/\/[^\s]+/u', '', $t);
    $t = preg_replace('/\n{3,}/', "\n\n", $t);
    $t = preg_replace('/\*\*(.*?)\*\*/s', '*$1*', $t);
    $t = preg_replace('/~~(.*?)~~/s', '~$1~', $t);
    $t = trim($t);
    return $t . "\n\n#️⃣ *Aproveite enquanto está disponível!*\n\n🔗 " . $linkAfiliado;
}

function baixarEConverterImagemBase64($url) {
    $data = @file_get_contents($url);
    if ($data === false || strlen($data) < 10) return null;
    $img = @imagecreatefromstring($data);
    if ($img) {
        ob_start();
        @imagejpeg($img, null, 90);
        $jpeg = ob_get_clean();
        @imagedestroy($img);
        if ($jpeg !== false && strlen($jpeg) > 0) return base64_encode($jpeg);
    }
    return null;
}

function enviarWhatsAppEvolution($baseUrl, $inst, $apiKey, $number, $caption, $mediaBase64, &$err) {
    $err = '';
    $base = rtrim($baseUrl, '/');
    $headers = ['Content-Type: application/json', 'apikey: ' . $apiKey];
    if (!empty($mediaBase64)) {
        $url = $base . '/message/sendMedia/' . $inst;
        $body = [
            'number' => $number,
            'caption' => $caption,
            'delay' => 3000,
            'media' => $mediaBase64,
            'mediatype' => 'image',
            'mimetype' => 'image/jpeg',
            'fileName' => 'imagem_produto.jpeg',
        ];
    } else {
        $url = $base . '/message/sendText/' . $inst;
        $body = ['number' => $number, 'text' => $caption];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        $err = 'Evolution HTTP ' . $code;
        return false;
    }
    $j = @json_decode($res, true);
    if (isset($j['error']) && $j['error']) {
        $err = $j['message'] ?? 'Erro na Evolution';
        return false;
    }
    return true;
}

/**
 * Encontra uma categoria existente pelo nome do produto ou cria uma nova.
 * 1) Se ml_site_categoria_id estiver definido e for válido, usa ele.
 * 2) Senão, tenta casar por palavras‑chave com categorias existentes.
 * 3) Se não houver casamento, pergunta à OpenAI uma categoria e cria.
 * Retorna categoria_id ou null. Em falha, preenche $err.
 */
function obterOuCriarCategoriaParaProduto($nomeProduto, &$err, $prefix = 'ml') {
    $err = '';
    $nomeProduto = trim($nomeProduto);
    if (empty($nomeProduto)) return null;

    // 1) Override manual
    $cid = getConfig($prefix . '_site_categoria_id', '');
    if ($cid !== '' && $cid !== '0') {
        $pdo = getDB();
        $st = $pdo->prepare("SELECT id FROM categorias WHERE id = ? AND ativo = 1");
        $st->execute([(int)$cid]);
        if ($st->fetch()) return (int)$cid;
    }

    $pdo = getDB();
    $cats = $pdo->query("SELECT id, nome, slug FROM categorias WHERE ativo = 1")->fetchAll(PDO::FETCH_ASSOC);

    // Mapa: termo (sem acento) -> slug da categoria
    $slugPorTermo = [
        'celular' => 'celulares', 'celulares' => 'celulares', 'smartphone' => 'celulares', 'iphone' => 'celulares',
        'samsung' => 'celulares', 'xiaomi' => 'celulares', 'motorola' => 'celulares', 'aparelho' => 'celulares',
        'fone' => 'eletronicos', 'headphone' => 'eletronicos', 'fone de ouvido' => 'eletronicos',
        'cabo' => 'eletronicos', 'carregador' => 'eletronicos', 'notebook' => 'eletronicos', 'laptop' => 'eletronicos',
        'tablet' => 'eletronicos', 'monitor' => 'eletronicos', 'tv' => 'eletronicos', 'televisao' => 'eletronicos',
        'som' => 'eletronicos', 'caixa de som' => 'eletronicos', 'eletronico' => 'eletronicos', 'eletronicos' => 'eletronicos',
        'game' => 'games', 'games' => 'games', 'playstation' => 'games', 'xbox' => 'games', 'nintendo' => 'games',
        'controle' => 'games', 'jogo' => 'games', 'videogame' => 'games',
        'panela' => 'casa-cozinha', 'cozinha' => 'casa-cozinha', 'fogao' => 'casa-cozinha', 'geladeira' => 'casa-cozinha',
        'cama' => 'casa-cozinha', 'sofa' => 'casa-cozinha', 'tapete' => 'casa-cozinha', 'mesa' => 'casa-cozinha',
        'cadeira' => 'casa-cozinha', 'organizador' => 'casa-cozinha', 'pote' => 'casa-cozinha',
        'mais vendidos' => 'mais-vendidos', 'mais-vendidos' => 'mais-vendidos',
    ];

    $slugToId = [];
    foreach ($cats as $c) {
        $slugToId[$c['slug']] = (int)$c['id'];
        $n = removerAcentos(mb_strtolower($c['nome']));
        foreach (preg_split('/[\s\-]+/', $n) as $w) {
            $w = trim($w, '-');
            if (strlen($w) >= 2) $slugPorTermo[$w] = $c['slug'];
        }
        $slugPorTermo[$c['slug']] = $c['slug'];
        foreach (explode('-', $c['slug']) as $w) {
            if (strlen($w) >= 2) $slugPorTermo[$w] = $c['slug'];
        }
    }

    $txt = removerAcentos(mb_strtolower($nomeProduto));
    $words = array_filter(preg_split('/\s+/', $txt), function ($w) { return strlen($w) >= 2; });
    $scores = [];
    foreach ($slugPorTermo as $termo => $slug) {
        if (!isset($slugToId[$slug])) continue;
        $id = $slugToId[$slug];
        if (!isset($scores[$id])) $scores[$id] = 0;
        foreach ($words as $w) {
            if ($w === $termo || strpos($termo, $w) !== false || strpos($w, $termo) !== false) {
                $scores[$id]++;
                break;
            }
        }
    }
    if (!empty($scores)) {
        arsort($scores);
        $best = (int)array_key_first($scores);
        if ($scores[$best] >= 1) return $best;
    }

    // 3) Criar nova: OpenAI sugere o nome
    $key = getConfig($prefix . '_openai_api_key', '');
    $model = getConfig($prefix . '_openai_model', 'gpt-4.1-mini');
    if (empty($key)) {
        $err = 'OpenAI não configurada para sugerir categoria.';
        return null;
    }
    $sugerida = sugerirCategoriaOpenAI($nomeProduto, $key, $model, $err);
    if ($sugerida === '' || !empty($err)) return null;

    $slug = slugify($sugerida);
    if ($slug === '') $slug = 'outros';
    $st = $pdo->prepare("SELECT id FROM categorias WHERE slug = ?");
    $st->execute([$slug]);
    if ($st->fetch()) {
        $slug = $slug . '-' . substr(uniqid(), -4);
    }
    $ordem = 999;
    $st = $pdo->prepare("SELECT COALESCE(MAX(ordem),0)+1 FROM categorias");
    $st->execute();
    $ordem = (int)$st->fetchColumn();
    $st = $pdo->prepare("INSERT INTO categorias (nome, slug, ordem, ativo) VALUES (?, ?, ?, 1)");
    $st->execute([$sugerida, $slug, $ordem]);
    return (int)$pdo->lastInsertId();
}

function removerAcentos($s) {
    $a = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c'];
    return strtr(mb_strtolower($s), $a);
}

function slugify($s) {
    $s = removerAcentos($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

function sugerirCategoriaOpenAI($nomeProduto, $apiKey, $model, &$err) {
    $err = '';
    $body = [
        'model' => $model,
        'messages' => [
            ['role' => 'user', 'content' => "Produto: \"{$nomeProduto}\". Retorne SOMENTE o nome de uma categoria em 1 a 3 palavras (ex: Eletrônicos, Casa e Cozinha, Moda). Uma única linha, sem explicação."],
        ],
        'temperature' => 0.2,
        'max_tokens' => 30,
    ];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 15,
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        $err = 'OpenAI (categoria) HTTP ' . $code;
        return '';
    }
    $j = @json_decode($res, true);
    $t = trim($j['choices'][0]['message']['content'] ?? '');
    if (preg_match('/[:\-]\s*(.+)$/u', $t, $m)) $t = trim($m[1]);
    $t = preg_replace('/\s+/', ' ', $t);
    return $t;
}

/**
 * Salva o produto no site de ofertas (tabela produtos).
 * $precoStr: texto tipo "R$ 99,90" ou "De R$ 149,90 por R$ 99,90"
 * $linkAfiliado: link de compra (já convertido para afiliado)
 * Retorna o ID do produto ou 0. Em caso de falha, $err é preenchido.
 * $preco, $preco_original, $desconto: opcionais (ex.: Shopee); se null, extrai de $precoStr.
 * $parcelas, $preco_parcela: opcionais; se preenchidos, exibe "em 12x de R$ 46,43" no site.
 * $configPrefix: 'ml' ou 'shopee' para getConfig de categoria/OpenAI.
 */
function salvarProdutoNoSite($nome, $precoStr, $linkAfiliado, $imagemUrl, &$err, $preco = null, $preco_original = null, $desconto = null, $configPrefix = 'ml', $parcelas = null, $preco_parcela = null) {
    $err = '';
    $nome = trim($nome);
    $linkAfiliado = trim($linkAfiliado);
    if (empty($nome) || empty($linkAfiliado)) {
        $err = 'Nome ou link vazio.';
        return 0;
    }
    
    // Verificar se produto já existe por nome similar (evitar duplicatas)
    try {
        $pdo = getDB();
        $nomeNorm = mb_substr($nome, 0, 50);
        $st = $pdo->prepare("SELECT id FROM produtos WHERE SUBSTRING(nome, 1, 50) = ? LIMIT 1");
        $st->execute([$nomeNorm]);
        if ($st->fetch()) {
            $err = 'Produto já existe no site (nome similar).';
            return 0;
        }
    } catch (Exception $e) {
        // Continuar mesmo se der erro
    }
    
    if ($preco === null && $preco_original === null) {
        $preco = null;
        $preco_original = null;
        if (preg_match_all('/R\$\s*([\d.,]+)/u', $precoStr, $m)) {
            $vals = [];
            foreach ($m[1] as $v) {
                $f = function_exists('parsePrecoBr') ? parsePrecoBr($v) : null;
                if ($f === null) $f = floatval(str_replace([','], ['.'], trim($v)));
                if ($f > 0) $vals[] = $f;
            }
            if (count($vals) >= 2) {
                $preco_original = max($vals);
                $preco = min($vals);
            } elseif (count($vals) === 1) {
                $preco = $vals[0];
            }
        }
        $desconto = 0;
        if ($preco_original > 0 && $preco > 0 && $preco_original > $preco && function_exists('calcularDesconto')) {
            $desconto = calcularDesconto($preco_original, $preco);
        }
        if (function_exists('sanearPrecoOriginal')) {
            list($preco_original, $desconto) = sanearPrecoOriginal($preco, $preco_original, $desconto);
        }
    } else {
        if ($desconto === null && $preco_original > 0 && $preco > 0 && $preco_original > $preco && function_exists('calcularDesconto')) {
            $desconto = calcularDesconto($preco_original, $preco);
        }
        $desconto = $desconto ?? 0;
        if (function_exists('sanearPrecoOriginal')) {
            list($preco_original, $desconto) = sanearPrecoOriginal($preco, $preco_original, $desconto);
        }
    }
    
    // Extrair parcelas do texto se não fornecidas
    if ($parcelas === null && $preco_parcela === null && function_exists('extrairParcelas')) {
        list($parcelas, $preco_parcela) = extrairParcelas($precoStr);
    }
    
    // Se temos preco_parcela mas não parcelas, não mostrar (evita confusão)
    if ($preco_parcela !== null && $parcelas === null) {
        $preco_parcela = null;
    }
    // Se temos parcelas mas não preco_parcela, não mostrar
    if ($parcelas !== null && $preco_parcela === null) {
        $parcelas = null;
    }
    
    // Garantir que preco é o total, não o valor da parcela
    if (function_exists('corrigirPrecoTotalParcelas') && $parcelas && $preco_parcela) {
        $preco = corrigirPrecoTotalParcelas($preco, $parcelas, $preco_parcela);
    }
    
    $imagem = null;
    if (!empty($imagemUrl) && function_exists('downloadImageFromUrl')) {
        $imagem = downloadImageFromUrl($imagemUrl, 'uploads/produtos/');
    }
    $categoria_id = obterOuCriarCategoriaParaProduto($nome, $errCat, $configPrefix);
    if (!empty($errCat)) $err = $errCat;
    try {
        $pdo = getDB();

        $st = $pdo->prepare("
            INSERT INTO produtos (nome, categoria_id, imagem, preco, preco_original, desconto, parcelas, preco_parcela, link_compra, destaque, ativo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)
        ");
        $st->execute([
            $nome,
            $categoria_id,
            $imagem,
            $preco,
            $preco_original,
            $desconto,
            $parcelas,
            $preco_parcela,
            $linkAfiliado,
        ]);
        return (int) $pdo->lastInsertId();
    } catch (Exception $e) {
        $err = $e->getMessage();
        return 0;
    }
}

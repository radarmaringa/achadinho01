<?php
require_once __DIR__ . '/database.php';

// Função para fazer upload de imagem
function uploadImagem($file, $pasta = 'uploads/') {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    // Verificar se é uma imagem
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        return false;
    }

    // Criar diretório se não existir
    $uploadDir = __DIR__ . '/../' . $pasta;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Gerar nome único
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = uniqid('img_', true) . '.' . $extension;
    $filePath = $uploadDir . $fileName;

    if (move_uploaded_file($file['tmp_name'], $filePath)) {
        return $pasta . $fileName;
    }

    return false;
}

// Função para deletar imagem
function deleteImagem($path) {
    if (!empty($path) && file_exists(__DIR__ . '/../' . $path)) {
        @unlink(__DIR__ . '/../' . $path);
    }
}

// Função para obter configuração
function getConfig($chave, $default = '') {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
    $stmt->execute([$chave]);
    $result = $stmt->fetch();
    return $result ? $result['valor'] : $default;
}

// Função para salvar configuração
function setConfig($chave, $valor) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("
            INSERT INTO configuracoes (chave, valor) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE valor = ?
        ");
        $result = $stmt->execute([$chave, $valor, $valor]);
        
        if (!$result) {
            error_log("Erro ao executar query para configuração {$chave}");
        }
        
        return $result;
    } catch (PDOException $e) {
        // Exibir erro apenas em desenvolvimento
        if (ini_get('display_errors')) {
            echo "<div style='background: #fee; padding: 10px; margin: 10px; border: 1px solid #f00;'>";
            echo "<strong>Erro ao salvar configuração '{$chave}':</strong> " . htmlspecialchars($e->getMessage());
            echo "</div>";
        }
        error_log("Erro ao salvar configuração {$chave}: " . $e->getMessage());
        return false;
    } catch (Exception $e) {
        if (ini_get('display_errors')) {
            echo "<div style='background: #fee; padding: 10px; margin: 10px; border: 1px solid #f00;'>";
            echo "<strong>Erro ao salvar configuração '{$chave}':</strong> " . htmlspecialchars($e->getMessage());
            echo "</div>";
        }
        error_log("Erro ao salvar configuração {$chave}: " . $e->getMessage());
        return false;
    }
}

// Função para formatar valor monetário
function formatMoney($valor) {
    return number_format($valor, 2, '.', '');
}

// Função para calcular desconto
function calcularDesconto($precoOriginal, $precoAtual) {
    if ($precoOriginal <= 0 || $precoAtual >= $precoOriginal) {
        return 0;
    }
    return round((($precoOriginal - $precoAtual) / $precoOriginal) * 100);
}

/**
 * Converte string de preço (BR ou EN) em float.
 * - BR: "1.504,31" (ponto=milhar, vírgula=decimal) → 1504.31
 * - EN: "504.31" (ponto=decimal) → 504.31 — NÃO remove o ponto para não virar 50431
 * - "504" → 504
 * Retorna float ou null se inválido.
 */
function parsePrecoBr($str) {
    if ($str === null || $str === '') return null;
    $s = trim(preg_replace('/\s+/', '', (string)$str));
    if ($s === '') return null;
    $s = preg_replace('/^R\$\s*/iu', '', $s);
    if ($s === '') return null;
    
    // Se tem vírgula, é formato BR
    if (strpos($s, ',') !== false) {
        // Formato BR: 1.504,31 ou 747,38 — remove pontos (milhar), troca vírgula por ponto
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
        $f = filter_var($s, FILTER_VALIDATE_FLOAT);
        return ($f !== false && $f >= 0) ? (float)$f : null;
    }
    
    // Só tem ponto - precisa decidir se é decimal ou milhar
    if (strpos($s, '.') !== false) {
        // Contar quantos pontos tem
        $numPontos = substr_count($s, '.');
        
        // Se tem mais de 1 ponto, são milhares (ex: 1.504.321)
        if ($numPontos > 1) {
            $s = str_replace('.', '', $s);
            $f = filter_var($s, FILTER_VALIDATE_FLOAT);
            return ($f !== false && $f >= 0) ? (float)$f : null;
        }
        
        // Se tem 1 ponto, verificar posição e quantidade de dígitos após
        if (preg_match('/^(\d+)\.(\d+)$/', $s, $m)) {
            $antesDecimal = $m[1];
            $depoisDecimal = $m[2];
            
            // Se depois do ponto tem 1 ou 2 dígitos, É DECIMAL (ex: 747.38, 504.5)
            if (strlen($depoisDecimal) <= 2) {
                $f = filter_var($s, FILTER_VALIDATE_FLOAT);
                return ($f !== false && $f >= 0) ? (float)$f : null;
            }
            
            // Se depois do ponto tem 3 dígitos exatos E antes tem 1-2 dígitos, é milhar BR (ex: 1.504, 74.738)
            if (strlen($depoisDecimal) == 3 && strlen($antesDecimal) <= 2) {
                $s = str_replace('.', '', $s);
                $f = filter_var($s, FILTER_VALIDATE_FLOAT);
                return ($f !== false && $f >= 0) ? (float)$f : null;
            }
            
            // Outros casos: tratar como decimal (ex: 747.388 seria 747.388)
            $f = filter_var($s, FILTER_VALIDATE_FLOAT);
            return ($f !== false && $f >= 0) ? (float)$f : null;
        }
    }
    
    // Sem ponto nem vírgula - número inteiro
    $f = filter_var($s, FILTER_VALIDATE_FLOAT);
    return ($f !== false && $f >= 0) ? (float)$f : null;
}

/**
 * Se preco_original for manifestamente inválido (ex.: 10x maior que preco), zera.
 * Também corrige casos onde o preço original parece ter sido parseado errado.
 * Retorna [preco_original, desconto] já ajustados.
 */
function sanearPrecoOriginal($preco, $preco_original, $desconto) {
    $po = $preco_original;
    $desc = (int)$desconto;
    
    // Se preco_original > 10x preco, é claramente erro de parsing
    if ($preco > 0 && $po > 0 && $po > 10 * $preco) {
        // Tentar corrigir: talvez seja 74738 em vez de 747.38
        $poCorrigido = $po / 100;
        if ($poCorrigido > $preco && $poCorrigido < 10 * $preco) {
            $po = $poCorrigido;
        } else {
            $po = null;
            $desc = 0;
        }
    }
    
    // Recalcular desconto se tivermos valores válidos
    if ($po !== null && $preco > 0 && $po > 0 && $preco < $po && function_exists('calcularDesconto')) {
        $desc = calcularDesconto($po, $preco);
    }
    
    // Se desconto > 95%, provavelmente é erro
    if ($desc > 95) {
        $po = null;
        $desc = 0;
    }
    
    return [$po, $desc];
}

/**
 * Garante que preco (total) não seja o valor da parcela por engano.
 * Se preco <= preco_parcela e temos parcelas, retorna preco_parcela * parcelas.
 * Retorna o preco (corrigido ou original).
 */
function corrigirPrecoTotalParcelas($preco, $parcelas, $preco_parcela) {
    if (empty($parcelas) || empty($preco_parcela) || $preco_parcela <= 0) {
        return $preco;
    }
    $total = round($preco_parcela * (int)$parcelas, 2);
    // Se o "preço" salvo é menor ou igual ao valor de 1 parcela, estava errado
    if ($preco <= $preco_parcela) {
        return $total;
    }
    return $preco;
}

/**
 * Extrai informações de parcelas de uma string de preço.
 * Ex: "em 10x de R$ 74,70" ou "10x R$ 74,70" ou "12x de 46,43"
 * Retorna [parcelas, preco_parcela] ou [null, null] se não encontrar.
 */
function extrairParcelas($str) {
    if (empty($str)) return [null, null];
    
    // Padrões: "10x de R$ 74,70", "em 10x R$ 74,70", "12x 46,43"
    if (preg_match('/(\d{1,2})\s*x\s*(?:de\s*)?R?\$?\s*([\d.,]+)/iu', $str, $m)) {
        $parcelas = (int)$m[1];
        $precoParcela = parsePrecoBr($m[2]);
        if ($parcelas >= 2 && $parcelas <= 48 && $precoParcela > 0) {
            return [$parcelas, $precoParcela];
        }
    }
    
    return [null, null];
}

/**
 * Baixa imagem de URL e salva em uploads (ex.: uploads/produtos/).
 * Retorna o caminho relativo (ex.: uploads/produtos/arquivo.jpg) ou null.
 */
function downloadImageFromUrl($url, $pasta = 'uploads/produtos/') {
    if (empty($url)) return null;
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        if (file_exists(__DIR__ . '/../' . $url)) return $url;
        return null;
    }
    try {
        $uploadDir = __DIR__ . '/../' . $pasta;
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $imageData = @file_get_contents($url);
        if ($imageData === false || strlen($imageData) < 10) return null;
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $finfo ? finfo_buffer($finfo, $imageData) : '';
        if ($finfo) finfo_close($finfo);
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if ($mimeType && !in_array(strtolower($mimeType), $allowed)) return null;
        $ext = 'jpg';
        if (strpos($mimeType, 'png') !== false) $ext = 'png';
        elseif (strpos($mimeType, 'gif') !== false) $ext = 'gif';
        elseif (strpos($mimeType, 'webp') !== false) $ext = 'webp';
        $nome = uniqid('ml_', true) . '.' . $ext;
        $path = $uploadDir . $nome;
        if (file_put_contents($path, $imageData) !== false) return $pasta . $nome;
        return null;
    } catch (Exception $e) {
        error_log('downloadImageFromUrl: ' . $e->getMessage());
        return null;
    }
}

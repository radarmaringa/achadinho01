<?php
/**
 * Configuração e validação LOCAL de licença - AfiliadosPRO
 * Sem requisição a servidor. Chave atrelada ao domínio e data de validade.
 * A mesma chave LICENCA_SECRET deve estar no gerador-licencas.html (só você tem).
 */

if (!defined('LICENCA_SECRET')) {
    define('LICENCA_SECRET', 'AfiliadosPRO_Gerador_2024_ChaveSecreta_AltereEmProducao');
}

if (!function_exists('lp_normalizar_dominio')) {
    function lp_normalizar_dominio($host) {
        $host = strtolower(trim((string) $host));
        $host = preg_replace('/:\d+$/', '', $host);
        return $host;
    }
}

if (!function_exists('lp_base64url_decode')) {
    function lp_base64url_decode($data) {
        $data = (string) $data;
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}

if (!function_exists('lp_verificar_chave_licenca')) {
    /**
     * Verifica se a chave é válida para o domínio atual e não está expirada.
     * Formato da chave: base64url(dominio|expiry_timestamp).hmac_hex
     */
    function lp_verificar_chave_licenca($chave, $dominioAtual) {
        $chave = trim((string) $chave);
        if ($chave === '') {
            return false;
        }
        $dominioAtual = lp_normalizar_dominio($dominioAtual);
        if (strpos($chave, '.') === false) {
            return false;
        }
        $parts = explode('.', $chave, 2);
        $payloadB64 = $parts[0];
        $signatureHex = $parts[1];
        if (strlen($signatureHex) !== 64 || !ctype_xdigit($signatureHex)) {
            return false;
        }
        $payload = lp_base64url_decode($payloadB64);
        if ($payload === false || strpos($payload, '|') === false) {
            return false;
        }
        $arr = explode('|', $payload, 2);
        $domain = lp_normalizar_dominio($arr[0]);
        $expiry = (int) $arr[1];
        if ($domain !== $dominioAtual) {
            return false;
        }
        if ($expiry < time()) {
            return false;
        }
        $expectedSig = hash_hmac('sha256', $payload, LICENCA_SECRET);
        return hash_equals($expectedSig, $signatureHex);
    }
}

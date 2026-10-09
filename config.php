<?php
// Configuração central do PlayMoz Embed. Não fixa o domínio.
function pm_origin(): string {
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $proto = $https ? 'https' : 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $forwarded = strtolower(trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
        if ($forwarded === 'https' || $forwarded === 'http') $proto = $forwarded;
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $proto . '://' . $host;
}
function pm_base(): string { return rtrim(pm_origin(), '/'); }
function pm_asset(string $path): string { return pm_base() . '/' . ltrim($path, '/'); }

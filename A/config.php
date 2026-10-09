<?php
// Configuração central do PlayMoz Embed.
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

// ============================================================
// PROTEÇÃO DO EMBED
// Adicione/remova origens completas aqui. Não use barra no final.
// Ex.: 'https://outrodominio.com'
// ============================================================
define('PM_AUTHORIZED_EMBED_ORIGINS', [
    'https://golplay.site',
    'https://playmoz.xyz',
]);

// Destino do botão exibido em qualquer bloqueio.
define('PM_ACCESS_SITE', 'https://golplay.site');

// Troque esta chave por outra string longa e privada se publicar o código.
define('PM_EMBED_GATE_SECRET', 'pm-gate-2026-9f6e7a34-CHANGE-ME-TO-A-LONG-RANDOM-SECRET');

define('PM_EMBED_GATE_TTL', 90); // segundos para concluir a pré-verificação

function pm_normalize_origin(string $origin): string {
    return rtrim(strtolower(trim($origin)), '/');
}

function pm_authorized_origins(): array {
    return array_values(array_unique(array_filter(array_map('pm_normalize_origin', PM_AUTHORIZED_EMBED_ORIGINS))));
}

function pm_embed_forbidden(string $message = 'Este conteúdo foi bloqueado. Para continuar acessando os conteúdos, clique no botão Acessar.', string $code = 'Acesso bloqueado'): void {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $safeAccess = htmlspecialchars(PM_ACCESS_SITE, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="pt"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="dark"><title>Acesso bloqueado</title><style>*{box-sizing:border-box}html,body{height:100%;margin:0;background:#05070b;color:#fff;font-family:Inter,system-ui,-apple-system,Segoe UI,Arial,sans-serif}.x{min-height:100%;display:grid;place-items:center;padding:24px}.c{width:min(520px,100%);text-align:center;background:#0d1118;border:1px solid #202734;border-radius:22px;padding:30px 24px;box-shadow:0 18px 60px rgba(0,0,0,.35)}.i{width:58px;height:58px;border-radius:18px;display:grid;place-items:center;margin:0 auto 16px;background:#171d27;font-size:26px}.c h1{font-size:22px;margin:0 0 8px}.code{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#ff6b83;font-weight:800;margin-bottom:11px}.c p{color:#aab3c1;line-height:1.6;margin:0 auto 22px;max-width:430px}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 18px;border-radius:12px;background:#ef2147;color:#fff;text-decoration:none;font-weight:800}.btn:hover{filter:brightness(1.08)}</style></head><body><main class="x"><section class="c"><div class="i">🔒</div><div class="code">'.$safeCode.'</div><h1>Conteúdo bloqueado</h1><p>'.$safeMessage.'</p><a class="btn" href="'.$safeAccess.'" target="_top" rel="noopener noreferrer">Acessar</a></section></main></body></html>';
    exit;
}

function pm_embed_request_origin(): string {
    $ref = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if ($ref === '') return '';
    $parts = @parse_url($ref);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return '';
    $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
    if (!empty($parts['port'])) $origin .= ':' . (int)$parts['port'];
    return pm_normalize_origin($origin);
}

function pm_is_authorized_origin(string $origin): bool {
    $origin = pm_normalize_origin($origin);
    return $origin !== '' && in_array($origin, pm_authorized_origins(), true);
}

function pm_gate_subject(): string {
    // Liga a autorização ao caminho + parâmetros reais do conteúdo, ignorando somente os campos do gate.
    $query = $_GET;
    unset($query['__pm_gate'], $query['__pm_exp']);
    ksort($query);
    return ($_SERVER['SCRIPT_NAME'] ?? '') . '|' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function pm_gate_token(int $exp): string {
    $data = $exp . '|' . pm_gate_subject();
    return hash_hmac('sha256', $data, PM_EMBED_GATE_SECRET);
}

function pm_gate_valid(): bool {
    $exp = (int)($_GET['__pm_exp'] ?? 0);
    $token = (string)($_GET['__pm_gate'] ?? '');
    if ($exp <= time() || $exp > time() + PM_EMBED_GATE_TTL + 30 || $token === '') return false;
    return hash_equals(pm_gate_token($exp), $token);
}

function pm_emit_embed_gate(): void {
    $exp = time() + PM_EMBED_GATE_TTL;
    $token = pm_gate_token($exp);
    $origins = pm_authorized_origins();
    $originsJson = json_encode($origins, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $tokenJson = json_encode($token, JSON_UNESCAPED_SLASHES);
    $expJson = json_encode($exp);
    $accessJson = json_encode(PM_ACCESS_SITE, JSON_UNESCAPED_SLASHES);

    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Robots-Tag: noindex, nofollow, noarchive');

    echo '<!doctype html><html lang="pt"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="dark"><title>A validar player…</title><style>*{box-sizing:border-box}html,body{height:100%;margin:0;background:#05070b;color:#fff;font-family:Inter,system-ui,-apple-system,Segoe UI,Arial,sans-serif}.wrap{height:100%;display:grid;place-items:center;padding:24px}.card{width:min(520px,100%);text-align:center}.spin{width:34px;height:34px;border:3px solid #272f3c;border-top-color:#ef2147;border-radius:50%;margin:0 auto 14px;animation:r .7s linear infinite}.muted{color:#9ca6b5;font-size:13px}@keyframes r{to{transform:rotate(360deg)}}.err{display:none;background:#0d1118;border:1px solid #202734;border-radius:20px;padding:28px 22px}.err h1{font-size:21px;margin:0 0 10px}.err p{color:#aab3c1;line-height:1.55;margin:0 0 20px}.btn{display:inline-flex;min-height:44px;align-items:center;padding:0 18px;border-radius:12px;background:#ef2147;color:#fff;text-decoration:none;font-weight:800}</style></head><body><main class="wrap"><div class="card"><div id="loading"><div class="spin"></div><div class="muted">A validar origem do player…</div></div><section class="err" id="err"><h1>Conteúdo bloqueado</h1><p id="msg">Este conteúdo foi bloqueado. Para continuar acessando os conteúdos, clique no botão Acessar.</p><a class="btn" id="go" href="#" target="_top" rel="noopener noreferrer">Acessar</a></section></div></main><script>(function(){"use strict";const allowed='.$originsJson.',token='.$tokenJson.',exp='.$expJson.',access='.$accessJson.';const err=document.getElementById("err"),loading=document.getElementById("loading"),msg=document.getElementById("msg"),go=document.getElementById("go");go.href=access;function fail(t){loading.style.display="none";err.style.display="block";msg.textContent=t;}function refOrigin(){try{if(document.referrer)return new URL(document.referrer).origin.toLowerCase().replace(/\/$/,"");}catch(e){}return "";}function ancestorOrigin(){try{const a=location.ancestorOrigins;if(a&&a.length){return String(a[a.length-1]||a[0]).toLowerCase().replace(/\/$/,"");}}catch(e){}return "";}function sandboxDetected(){try{if(window.frameElement&&window.frameElement.hasAttribute("sandbox"))return true;}catch(e){}try{const k="__pm_embed_sb_"+Math.random();sessionStorage.setItem(k,"1");sessionStorage.removeItem(k);}catch(e){return true;}try{if(String(window.origin||"").toLowerCase()==="null")return true;}catch(e){}return false;}if(window.top===window.self){fail("Este conteúdo foi bloqueado. Para continuar acessando os conteúdos, clique no botão Acessar.");return;}const ro=refOrigin(),ao=ancestorOrigin();const parentOrigin=allowed.includes(ao)?ao:(allowed.includes(ro)?ro:"");if(!parentOrigin){fail("Este site não está autorizado. O conteúdo foi bloqueado. Para continuar acessando os conteúdos, clique no botão Acessar.");return;}if(sandboxDetected()){fail("Iframe com sandbox detectado. O conteúdo foi bloqueado. Para continuar acessando os conteúdos, clique no botão Acessar.");return;}const u=new URL(location.href);u.searchParams.set("__pm_gate",token);u.searchParams.set("__pm_exp",String(exp));location.replace(u.toString());})();</script></body></html>';
    exit;
}

function pm_require_authorized_embed(): void {
    $allowed = pm_authorized_origins();
    if (!$allowed) pm_embed_forbidden('Nenhum domínio autorizado foi configurado.', 'Configuração inválida');

    // frame-ancestors é a barreira principal contra incorporação por terceiros.
    header('Content-Security-Policy: frame-ancestors ' . implode(' ', $allowed));
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: origin');
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('X-Robots-Tag: noindex, nofollow, noarchive');

    // Bloqueia abertura direta quando o navegador fornece Sec-Fetch-Dest.
    $dest = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '')));
    if ($dest !== '' && $dest !== 'iframe' && $dest !== 'frame') {
        pm_embed_forbidden('Este conteúdo foi bloqueado. Para continuar acessando os conteúdos, clique no botão Acessar.', 'Acesso direto bloqueado');
    }

    // Não bloqueamos pelo HTTP_REFERER no servidor. CDNs, proxies reversos e
    // políticas de privacidade podem alterar/remover esse cabeçalho e causar
    // falsos bloqueios. O navegador aplica frame-ancestors e o gate abaixo
    // confirma o ancestral real do iframe.

    // Antes de carregar qualquer player pesado, passa por um gate mínimo no navegador.
    if (!pm_gate_valid()) {
        pm_emit_embed_gate();
    }

    // O request final do gate é uma navegação interna dentro do próprio iframe.
    // Nesse segundo request, alguns navegadores enviam embed.playmoz.xyz como Referer
    // em vez do domínio pai (golplay.site). A autorização já foi verificada no gate
    // do navegador e o token HMAC válido prova que a pré-verificação foi concluída.
    // Portanto, não repetimos a validação de HTTP_REFERER aqui.
}

<?php
/** PlayMoz Player — mgeb.space via stream_id, com mapeamento TMDB → stream_id. PHP 7.4+ */

/* ============================================================
 *  0) AMBIENTE — evita 500 por timeout/memória
 * ============================================================ */
@ini_set('display_errors', '0');       // não mostra erro ao cliente
@ini_set('log_errors', '1');
@set_time_limit(120);                   // 2 min (lista grande)
@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', '120');

/* ============================================================
 *  1) CONFIGURAÇÃO INTERNA — NUNCA EXPOR AO CLIENTE
 * ============================================================ */
define('MGEB_DOMAIN', 'https://mgeb.space');
define('MGEB_USER',   'Feljo');
define('MGEB_PASS',   '22109f3c5de3aa6c');

define('JWPLAYER_KEY', '64HPbvSQorQcd52B8XFuhMtEoitbvY/EXJmMBfKcXZQU2Rnn');
define('PLAYMOZ_SITE', 'https://playmoz.xyz');
define('PLAYMOZ_POSTER_FALLBACK', 'https://i.imgur.com/XB5B8Md.jpeg');

define('STREAM_DIR', sys_get_temp_dir() . '/pm_streams');
define('STREAM_TTL', 1800);    // 30 min
define('MAP_TTL',    21600);   // 6h

/* ============================================================
 *  2) LOG LOCAL (para debugar 500 sem expor ao cliente)
 * ============================================================ */
function pm_log(string $msg): void {
    if (!is_dir(STREAM_DIR)) @mkdir(STREAM_DIR, 0700, true);
    @file_put_contents(STREAM_DIR . '/error.log', '[' . date('c') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

/* ============================================================
 *  3) TOKEN STORE
 * ============================================================ */
function pm_store_init(): void {
    if (!is_dir(STREAM_DIR)) @mkdir(STREAM_DIR, 0700, true);
}
function pm_store_put(string $token, array $data): bool {
    pm_store_init();
    $data['expires'] = time() + STREAM_TTL;
    $file = STREAM_DIR . '/' . preg_replace('/[^a-f0-9]/', '', $token) . '.json';
    $tmp  = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, json_encode($data), LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    return @rename($tmp, $file);
}
function pm_store_get(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    $file = STREAM_DIR . '/' . $token . '.json';
    if (!is_file($file)) return null;
    $raw = @file_get_contents($file);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    if (!is_array($data)) return null;
    if (($data['expires'] ?? 0) < time()) { @unlink($file); return null; }
    return $data;
}
function pm_store_gc(): void {
    if (random_int(1, 100) !== 1) return;
    pm_store_init();
    foreach ((array)@glob(STREAM_DIR . '/*.json') as $f) {
        if (basename($f) === 'error.log') continue;
        $raw = @file_get_contents($f);
        $d = $raw ? json_decode($raw, true) : null;
        if (!is_array($d) || ($d['expires'] ?? 0) < time()) @unlink($f);
    }
}

function pm_is_id(string $id): bool {
    return (bool)preg_match('/^[0-9]{1,12}$/', $id);
}

/* ============================================================
 *  4) CACHE DA LISTA get_vod_streams (TMDB → stream_id)
 * ============================================================ */
function pm_map_cache_file(): string {
    pm_store_init();
    return STREAM_DIR . '/map_vod.json';
}

/**
 * Baixa get_vod_streams e monta tmdb_id => stream_id.
 * - Guarda SÓ o mapa (não guarda a lista inteira) — economiza memória/disco.
 * - Cache 6h.
 * - Se falhar, devolve [] (não rebenta).
 */
function pm_load_vod_map(): array {
    $file = pm_map_cache_file();
    if (is_file($file) && (filemtime($file) + MAP_TTL) > time()) {
        $cached = json_decode((string)@file_get_contents($file), true);
        if (is_array($cached) && $cached !== []) return $cached;
    }
    if (!function_exists('curl_init')) { pm_log('curl indisponível'); return []; }

    $url = MGEB_DOMAIN . '/player_api.php?username=' . rawurlencode(MGEB_USER)
         . '&password=' . rawurlencode(MGEB_PASS) . '&action=get_vod_streams';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 90,      // lista pode ser grande
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Linux; Android 13)',
        CURLOPT_REFERER        => MGEB_DOMAIN . '/',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING       => '',      // aceita gzip (reduz 80%)
    ]);
    $raw    = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if (!is_string($raw) || $raw === '') { pm_log("get_vod_streams vazio (http=$status err=$err)"); return []; }
    if ($status < 200 || $status >= 300) { pm_log("get_vod_streams http=$status"); return []; }

    $arr = json_decode($raw, true);
    if (!is_array($arr)) { pm_log('get_vod_streams JSON inválido'); return []; }

    $map = [];
    foreach ($arr as $item) {
        if (!is_array($item)) continue;
        $tmdb = isset($item['tmdb_id']) ? (string)$item['tmdb_id'] : '';
        $sid  = isset($item['stream_id']) ? (string)$item['stream_id'] : '';
        if ($tmdb !== '' && $sid !== '' && pm_is_id($tmdb) && pm_is_id($sid)) {
            $map[$tmdb] = $sid;
        }
    }
    if ($map !== []) {
        @file_put_contents($file, json_encode($map), LOCK_EX);
        pm_log('map_vod criado com ' . count($map) . ' itens');
    } else {
        pm_log('map_vod vazio (nenhum tmdb_id encontrado)');
    }
    return $map;
}

function pm_load_series_map(): array {
    $file = STREAM_DIR . '/map_series.json';
    if (is_file($file) && (filemtime($file) + MAP_TTL) > time()) {
        $cached = json_decode((string)@file_get_contents($file), true);
        if (is_array($cached) && $cached !== []) return $cached;
    }
    if (!function_exists('curl_init')) return [];

    $url = MGEB_DOMAIN . '/player_api.php?username=' . rawurlencode(MGEB_USER)
         . '&password=' . rawurlencode(MGEB_PASS) . '&action=get_series';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Linux; Android 13)',
        CURLOPT_REFERER        => MGEB_DOMAIN . '/',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING       => '',
    ]);
    $raw    = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) return [];
    $arr = json_decode($raw, true);
    if (!is_array($arr)) return [];

    $map = [];
    foreach ($arr as $item) {
        if (!is_array($item)) continue;
        $tmdb = isset($item['tmdb_id']) ? (string)$item['tmdb_id'] : '';
        $sid  = isset($item['series_id']) ? (string)$item['series_id'] : '';
        if ($tmdb !== '' && $sid !== '' && pm_is_id($tmdb) && pm_is_id($sid)) {
            $map[$tmdb] = $sid;
        }
    }
    if ($map !== []) @file_put_contents($file, json_encode($map), LOCK_EX);
    return $map;
}

/** URL REAL do mgeb — só o PHP conhece. */
function pm_mgeb_url(string $streamId, string $type = 'movie'): string {
    $kind = ($type === 'tv') ? 'series' : 'movie';
    return MGEB_DOMAIN . '/' . $kind . '/' . rawurlencode(MGEB_USER) . '/' . rawurlencode(MGEB_PASS)
         . '/' . rawurlencode($streamId) . '.ts';
}

/**
 * Traduz ID (TMDB ou stream_id) para stream_id interno.
 * - 1º valida direto como stream_id (HEAD rápido, timeout 4s)
 * - 2º procura no mapa TMDB → stream_id
 * - Se não achar, devolve null (o endpoint responde JSON erro, não 500)
 */
function pm_resolve_stream_id(string $givenId, string $type = 'movie'): ?string {
    if (!pm_is_id($givenId)) return null;

    // 1) Tenta direto
    if (function_exists('curl_init')) {
        $url = pm_mgeb_url($givenId, $type);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Linux; Android 13)',
            CURLOPT_REFERER        => MGEB_DOMAIN . '/',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status === 200 || ($status >= 300 && $status < 400)) {
            return $givenId;
        }
    }

    // 2) Mapa TMDB → stream_id
    try {
        $map = ($type === 'tv') ? pm_load_series_map() : pm_load_vod_map();
        if (isset($map[$givenId])) return (string)$map[$givenId];
    } catch (\Throwable $e) {
        pm_log('resolve_stream_id: ' . $e->getMessage());
    }

    return null;
}

/** Resolve o 302 do mgeb → URL final do CDN (.m3u8?t=...). */
function pm_resolve_cdn(string $mgebUrl): ?string {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($mgebUrl);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Linux; Android 13)',
        CURLOPT_REFERER        => MGEB_DOMAIN . '/',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc    = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);

    if ($status >= 300 && $status < 400 && $loc !== '' && preg_match('~^https?://~i', $loc)) return $loc;
    if ($status === 200) return $mgebUrl;
    return null;
}

/* ============================================================
 *  5) ENDPOINTS
 * ============================================================ */

/* 5.1) ?go=<token> → 302 para mgeb */
if (isset($_GET['go'])) {
    $token = (string)$_GET['go'];
    $entry = pm_store_get($token);
    if (!is_array($entry) || empty($entry['url'])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Fonte indisponível';
        exit;
    }
    header('Cache-Control: private, no-store');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Location: ' . $entry['url'], true, 302);
    exit;
}

/* 5.2) ?source=1 → JSON { ok, server: { embed, stream_id } } */
if (isset($_GET['source']) && $_GET['source'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    try {
        $id   = (string)($_GET['id'] ?? '');
        $type = in_array($_GET['type'] ?? 'movie', ['movie', 'tv'], true) ? $_GET['type'] : 'movie';

        if (!pm_is_id($id)) {
            echo json_encode(['ok' => false, 'error' => 'id_required']); exit;
        }

        $streamId = pm_resolve_stream_id($id, $type);
        if (!$streamId) {
            echo json_encode([
                'ok'    => false,
                'error' => 'stream_not_found',
                'tmdb'  => $id,
                'type'  => $type,
            ]); exit;
        }

        $token = bin2hex(random_bytes(16));
        if (!pm_store_put($token, [
            'id'       => $streamId,
            'original' => $id,
            'type'     => $type,
            'url'      => pm_mgeb_url($streamId, $type),
            'label'    => 'HD',
            'poster'   => PLAYMOZ_POSTER_FALLBACK,
        ])) {
            echo json_encode(['ok' => false, 'error' => 'token_failed']); exit;
        }

        $self = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        echo json_encode([
            'ok'     => true,
            'server' => [
                'embed'     => $self . '?embed=' . $token,
                'quality'   => 'HD',
                'stream_id' => $streamId,
                'tmdb_id'   => $id,
            ],
        ], JSON_UNESCAPED_SLASHES);
        exit;

    } catch (\Throwable $e) {
        pm_log('source=1 fatal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(200); // devolve JSON, não 500
        echo json_encode(['ok' => false, 'error' => 'internal_error']); exit;
    }
}

/* 5.3) ?embed=<token> → HTML do player */
if (isset($_GET['embed'])) {
    $token = (string)$_GET['embed'];
    $entry = pm_store_get($token);
    if (!is_array($entry)) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><body style="background:#000;color:#fff;font-family:sans-serif;display:grid;place-items:center;height:100vh;margin:0"><p>Fonte indisponível</p></body></html>';
        exit;
    }

    $self    = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $goUrl   = $self . '?go=' . $token;
    $mgebUrl = (string)($entry['url'] ?? '');

    $cdnUrl  = $mgebUrl ? pm_resolve_cdn($mgebUrl) : null;
    $playUrl = $cdnUrl ?: $goUrl;

    $label   = $entry['label']  ?? 'HD';
    $poster  = $entry['poster'] ?? PLAYMOZ_POSTER_FALLBACK;

    $urlJs    = json_encode($playUrl, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES);
    $labelJs  = json_encode($label,   JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    $posterJs = json_encode($poster,  JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES);

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    ?>
<!DOCTYPE html>
<html>
<head>
<script>
(function () {
  if (window.self === window.top) return;
  var sandboxFound = false;
  try { var f = window.frameElement; sandboxFound = !!(f && f.hasAttribute('sandbox')); } catch (_) {}
  if (!sandboxFound) { var o = window.origin || (window.location && window.location.origin); if (o === 'null' || o === null) sandboxFound = true; }
  if (!sandboxFound) { try { document.domain = document.domain; } catch (e) { var m = String(e && e.message || '').toLowerCase(); if (m.indexOf('sandbox') !== -1 || m.indexOf('forbidden') !== -1) sandboxFound = true; } }
  if (!sandboxFound) return;
  function show() {
    if (document.getElementById('pm-protection')) return;
    var d = document.createElement('div');
    d.id = 'pm-protection';
    d.style.cssText = 'position:fixed;inset:0;z-index:2147483647;background:#080b12;display:flex;align-items:center;justify-content:center;padding:20px;font-family:Arial,sans-serif;color:#fff;text-align:center';
    d.innerHTML = '<section style="max-width:420px;background:#141922;border:1px solid #343d4c;border-radius:18px;padding:30px 22px"><h2 style="color:#ff4966">Acesso protegido</h2><p style="line-height:1.65">Este player não permite a utilização de iframe com sandbox. Acede ao site oficial.</p><a href="<?= htmlspecialchars(PLAYMOZ_SITE, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" style="display:inline-block;background:#ed2448;color:white;text-decoration:none;padding:13px 22px;border-radius:10px;font-weight:bold">Aceder ao PlayMoz</a></section>';
    document.body.appendChild(d);
    try { if (window.player && window.player.pause) window.player.pause(true); } catch (_) {}
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', show, {once:true});
  else show();
})();
</script>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<meta name="referrer" content="strict-origin-when-cross-origin">
<title>PlayMoz Player</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
<script src="https://ssl.p.jwpcdn.com/player/v/8.6.2/jwplayer.js"></script>
<script>jwplayer.key = <?= json_encode(JWPLAYER_KEY, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script>
<style>
 html, body { padding:0; margin:0; height:100%; background:#000 }
 #ani-player { width:100% !important; height:100% !important; overflow:hidden; background-color:#000 }
 .download { background:#ff0000; padding:10px; letter-spacing:1px; box-shadow:0 1px 15px #ff0000; color:#fff; font-family:"Open-Sans", sans-serif; margin:8px; border-radius:19px; font-weight:bold; font-size:11px }
 #down { position:absolute; z-index:2 }
 #down:hover > .down-list { display:block }
 .down-list { display:none; list-style:none; left:3px; z-index:9999999999999999; box-shadow:0 1px 15px #ff0000; background:#ff0000; margin:0; border-radius:40px; width:144px; padding:5px 0 0; position:absolute; }
 .down-list li { float:left; width:134px; padding:5px; text-align:center; margin-bottom:5px }
 .down-list li:hover { background:#0003 }
 .down-list li:hover > a { color:#fff }
 .down-list li a { color:#fff; text-decoration:none; font-family:"Open-Sans", sans-serif; font-size:18px; width:100% }
 .jw-icon.jw-icon-inline.jw-button-color.jw-reset.jw-icon-rewind { display:none; }
 #pm-err{position:fixed;inset:0;background:#000;display:none;align-items:center;justify-content:center;z-index:20;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
 #pm-err.on{display:flex}
 #pm-err .box{background:linear-gradient(160deg,#1a1d26,#0f1117);border:1px solid #2a2e39;border-radius:22px;padding:30px 26px;max-width:360px;width:calc(100% - 40px);text-align:center;box-shadow:0 30px 90px rgba(0,0,0,.75);animation:pmIn2 .4s cubic-bezier(.2,.9,.3,1.15)}
 @keyframes pmIn2{from{opacity:0;transform:translateY(12px) scale(.97)}to{opacity:1;transform:none}}
 #pm-err .icon{width:60px;height:60px;border-radius:50%;display:grid;place-items:center;background:rgba(255,0,0,.12);border:1px solid rgba(255,0,0,.35);color:#ff4d4d;margin:0 auto 16px}
 #pm-err .icon svg{width:28px;height:28px}
 #pm-err h2{margin:0 0 8px;font-size:17px;font-weight:700;color:#fff}
 #pm-err p{margin:0 0 22px;color:#9aa1b0;font-size:13px;line-height:1.55}
 #pm-err .btns{display:flex;gap:10px;flex-direction:column}
 #pm-err button{width:100%;padding:13px 18px;border-radius:14px;border:1px solid #333846;background:linear-gradient(145deg,#202430,#161922);color:#fff;font-family:inherit;font-size:14px;font-weight:700;cursor:pointer;transition:transform .15s,border-color .2s,background .2s}
 #pm-err button:hover{border-color:#ff3333;background:linear-gradient(145deg,#2a1e22,#1c1518)}
 #pm-err button:active{transform:scale(.98)}
 #pm-err button.primary{border-color:rgba(255,0,0,.5);background:linear-gradient(145deg,#2c1a1c,#1a1012)}
</style>
</head>
<body>
<script>
function goBack(){
  try{
    if (window.parent && window.parent !== window) { window.parent.postMessage({type:'playmoz-close'}, '*'); return; }
    if (history.length > 1) { history.back(); return; }
    window.close();
    setTimeout(function(){ window.location.href = "/"; }, 50);
  }catch(err){ window.location.href = "/"; }
}
</script>

<div style="background:#ff0000;padding:10px 20px;letter-spacing:1px;box-shadow:0 1px 15px #ff0000;color:#fff;font-family:'Open-Sans',sans-serif;margin:8px;border-radius:19px;font-weight:bold;font-size:11px;position:absolute;left:16px;z-index:9;cursor:pointer;" onclick="goBack()">Voltar</div>

<div id="down" style="right:16px;">
  <div class="download">Espelhar/Baixar</div>
  <ul id="down-list" class="down-list">
    <li><a href="<?= htmlspecialchars($playUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Clique aqui</a></li>
  </ul>
</div>

<div id="ani-player"></div>

<div id="pm-err">
  <div class="box">
    <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg></div>
    <h2>Não foi possível reproduzir</h2>
    <p>A fonte selecionada não está disponível neste momento. Podes tentar novamente.</p>
    <div class="btns">
      <button class="primary" type="button" onclick="window.location.reload()">Tentar novamente</button>
      <button type="button" onclick="goBack()">Voltar</button>
    </div>
  </div>
</div>

<script>
function pmShowErr(){ var el = document.getElementById('pm-err'); if (el) el.classList.add('on'); try { if (window.player && player.pause) player.pause(true); } catch(e){} }
function pmHideErr(){ var el = document.getElementById('pm-err'); if (el) el.classList.remove('on'); }

var player = jwplayer("ani-player");
player.setup({
  sources: [{ file: <?= $urlJs ?>, label: <?= $labelJs ?>, type: "hls", default: "false" }],
  aspectratio: "16:9",
  startparam: "start",
  primary: "html5",
  autostart: false,
  mute: false,
  preload: "auto",
  image: <?= $posterJs ?>,
  playbackRateControls: [0.5, 0.75, 1, 1.25, 1.5, 2],
  debug: false,
  cast: {},
});
player.on('error', pmShowErr);
player.on('mediaError', pmShowErr);
player.on('play', pmHideErr);
player.on('buffer', pmHideErr);

player.addButton('<svg xmlns="http://www.w3.org/2000/svg" class="jw-svg-icon jw-svg-icon-rewind2" viewBox="0 0 240 240" focusable="false"><path d="m 25.993957,57.778 v 125.3 c 0.03604,2.63589 2.164107,4.76396 4.8,4.8 h 62.7 v -19.3 h -48.2 v -96.4 H 160.99396 v 19.3 c 0,5.3 3.6,7.2 8,4.3 l 41.8,-27.9 c 2.93574,-1.480087 4.13843,-5.04363 2.7,-8 -0.57502,-1.174985 -1.52502,-2.124979 -2.7,-2.7 l -41.8,-27.9 c -4.4,-2.9 -8,-1 -8,4.3 v 19.3 H 30.893957 c -2.689569,0.03972 -4.860275,2.210431 -4.9,4.9 z m 163.422413,73.04577 c -3.72072,-6.30626 -10.38421,-10.29683 -17.7,-10.6 -7.31579,0.30317 -13.97928,4.29374 -17.7,10.6 -8.60009,14.23525 -8.60009,32.06475 0,46.3 3.72072,6.30626 10.38421,10.29683 17.7,10.6 7.31579,-0.30317 13.97928,-4.29374 17.7,-10.6 8.60009,-14.23525 8.60009,-32.06475 0,-46.3 z m -17.7,47.2 c -7.8,0 -14.4,-11 -14.4,-24.1 0,-13.1 6.6,-24.1 14.4,-24.1 7.8,0 14.4,11 14.4,24.1 0,13.1 -6.5,24.1 -14.4,24.1 z m -47.77056,9.72863 v -51 l -4.8,4.8 -6.8,-6.8 13,-12.99999 c 3.02543,-3.03598 8.21053,-0.88605 8.2,3.4 v 62.69999 z"></path></svg>', "Avançar 10s", function () { player.seek(player.getPosition() + 10); }, "Avançar 10s");

player.addButton('<svg xmlns="http://www.w3.org/2000/svg" class="jw-svg-icon jw-svg-icon-rewind" viewBox="0 0 240 240" focusable="false"><path d="M113.2,131.078a21.589,21.589,0,0,0-17.7-10.6,21.589,21.589,0,0,0-17.7,10.6,44.769,44.769,0,0,0,0,46.3,21.589,21.589,0,0,0,17.7,10.6,21.589,21.589,0,0,0,17.7-10.6,44.769,44.769,0,0,0,0-46.3Zm-17.7,47.2c-7.8,0-14.4-11-14.4-24.1s6.6-24.1,14.4-24.1,14.4,11,14.4,24.1S103.4,178.278,95.5,178.278Zm-43.4,9.7v-51l-4.8,4.8-6.8-6.8,13-13a4.8,4.8,0,0,1,8.2,3.4v62.7l-9.6-.1Zm162-130.2v125.3a4.867,4.867,0,0,1-4.8,4.8H146.6v-19.3h48.2v-96.4H79.1v19.3c0,5.3-3.6,7.2-8,4.3l-41.8-27.9a6.013,6.013,0,0,1-2.7-8,5.887,5.887,0,0,1,2.7-2.7l41.8-27.9c4.4-2.9,8-1,8,4.3v19.3H209.2A4.974,4.974,0,0,1,214.1,57.778Z"></path></svg>', "Voltar 10s", function () { player.seek(player.getPosition() - 10); }, "Voltar 10s");
</script>
</body>
</html>
    <?php
    exit;
}

/* ============================================================
 *  6) TELA PRINCIPAL
 * ============================================================ */
$id   = isset($_GET['id']) ? (string)$_GET['id'] : '';
$type = in_array($_GET['type'] ?? 'movie', ['movie', 'tv'], true) ? $_GET['type'] : 'movie';

if (!pm_is_id($id)) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><body style="background:#000;color:#fff;font-family:sans-serif;display:grid;place-items:center;height:100vh;margin:0"><p>Parâmetro id inválido.</p></body></html>';
    exit;
}

$self = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$base = $self . '?' . http_build_query(['type' => $type, 'id' => $id]);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="referrer" content="strict-origin-when-cross-origin">
<meta name="robots" content="noindex,nofollow">
<title>PlayMoz · Player</title>
<style>
:root{color-scheme:dark;--red:#ff0000;--red-soft:#ff3333;--bg:#050609;--panel-1:#1a1d26;--panel-2:#0f1117;--border:#2a2e39;--muted:#9aa1b0}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{margin:0;padding:0;width:100%;height:100%;background:var(--bg);color:#fff;font:15px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,"Open Sans",sans-serif;overflow:hidden}

#pm-player-stage{position:fixed;inset:0;display:none;background:#000;z-index:9000}
#pm-player-stage.on{display:block}
#pm-frame{width:100%;height:100%;border:0;display:block;background:#000}

#pm-screen{position:fixed;inset:0;z-index:10000;display:grid;place-items:center;padding:max(16px,env(safe-area-inset-top)) max(16px,env(safe-area-inset-right)) max(16px,env(safe-area-inset-bottom)) max(16px,env(safe-area-inset-left));background:radial-gradient(120% 80% at 50% 0%,#2a1418 0%,transparent 55%),radial-gradient(120% 80% at 50% 100%,#121620 0%,transparent 60%),var(--bg);transition:opacity .35s ease,visibility .35s ease}
#pm-screen.off{opacity:0;visibility:hidden;pointer-events:none}

.pm-modal{width:100%;max-width:380px;background:linear-gradient(160deg,var(--panel-1),var(--panel-2));border:1px solid var(--border);border-radius:22px;box-shadow:0 30px 90px rgba(0,0,0,.75),0 0 0 1px rgba(255,255,255,.03) inset;overflow:hidden;display:flex;flex-direction:column;max-height:calc(100dvh - 32px);animation:pmIn .4s cubic-bezier(.2,.9,.3,1.15)}
@keyframes pmIn{from{opacity:0;transform:translateY(12px) scale(.97)}to{opacity:1;transform:none}}

.pm-head{padding:20px 22px 16px;display:flex;align-items:center;gap:13px;border-bottom:1px solid rgba(255,255,255,.06)}
.pm-logo{flex:0 0 44px;width:44px;height:44px;display:grid;place-items:center;border-radius:13px;background:linear-gradient(145deg,rgba(255,0,0,.22),rgba(255,0,0,.06));border:1px solid rgba(255,0,0,.35);color:#ff4d4d}
.pm-logo svg{width:22px;height:22px}
.pm-head-text{min-width:0}
.pm-head-text small{display:block;font-size:10px;letter-spacing:.16em;font-weight:800;color:#ff5c5c;text-transform:uppercase;margin-bottom:2px}
.pm-head-text h1{margin:0;font-size:16px;font-weight:700;line-height:1.2;overflow-wrap:anywhere}

.pm-body{padding:24px 22px 22px;overflow-y:auto;overscroll-behavior:contain;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;min-height:180px}

.pm-spin{width:50px;height:50px;border-radius:50%;border:3px solid rgba(255,255,255,.08);border-top-color:var(--red);animation:spin .8s linear infinite;margin:0 auto 16px;position:relative}
.pm-spin::after{content:"";position:absolute;inset:6px;border-radius:50%;border:3px solid transparent;border-top-color:rgba(255,255,255,.22);animation:spin 1.4s linear infinite reverse}
@keyframes spin{to{transform:rotate(360deg)}}

.pm-body h2{margin:0 0 8px;font-size:16px;font-weight:700}
.pm-body p{margin:0;color:var(--muted);font-size:13px;line-height:1.55;max-width:280px}

.pm-foot{padding:11px 20px 15px;border-top:1px solid rgba(255,255,255,.06);text-align:center;color:#7d8494;font-size:11px}
.pm-foot:empty{display:none;border:0;padding:0}

.pm-servers{display:flex;flex-direction:column;gap:10px;width:100%;margin-top:22px}
.pm-server-btn{display:flex;align-items:center;gap:13px;width:100%;padding:14px 15px;border-radius:14px;background:linear-gradient(145deg,#202430,#161922);border:1px solid #333846;color:#fff;text-align:left;cursor:pointer;transition:transform .15s,border-color .2s,background .2s,box-shadow .2s;min-width:0;font-family:inherit;font-size:inherit}
.pm-server-btn:hover,.pm-server-btn:focus-visible{border-color:var(--red-soft);background:linear-gradient(145deg,#2a1e22,#1c1518);box-shadow:0 8px 24px rgba(255,0,0,.15);outline:none}
.pm-server-btn:active{transform:scale(.98)}
.pm-server-btn.primary{border-color:rgba(255,0,0,.5);background:linear-gradient(145deg,#2c1a1c,#1a1012)}
.pm-server-btn-icon{flex:0 0 42px;width:42px;height:42px;display:grid;place-items:center;border-radius:12px;background:linear-gradient(145deg,rgba(255,0,0,.22),rgba(255,0,0,.05));border:1px solid rgba(255,0,0,.3);color:#ff4d4d}
.pm-server-btn-icon svg{width:19px;height:19px}
.pm-server-btn-info{flex:1;min-width:0;overflow:hidden}
.pm-server-btn-info strong{display:block;font-size:14.5px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pm-server-btn-info small{display:block;margin-top:2px;font-size:11.5px;color:#9aa1b0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pm-server-btn-arrow{flex:0 0 18px;color:#6b7280;display:grid;place-items:center;transition:transform .2s,color .2s}
.pm-server-btn:hover .pm-server-btn-arrow{color:#ff4d4d;transform:translateX(3px)}
.pm-server-btn-arrow svg{width:16px;height:16px}

.pm-alert{width:60px;height:60px;border-radius:50%;display:grid;place-items:center;background:rgba(255,0,0,.12);border:1px solid rgba(255,0,0,.35);color:#ff4d4d;margin:0 auto 16px;animation:popIn .5s cubic-bezier(.2,.9,.3,1.5)}
.pm-alert svg{width:28px;height:28px}
@keyframes popIn{from{transform:scale(.4);opacity:0}to{transform:scale(1);opacity:1}}

@media (max-width:380px){.pm-modal{border-radius:19px}.pm-head{padding:17px 18px 14px;gap:11px}.pm-logo{flex:0 0 40px;width:40px;height:40px;border-radius:11px}.pm-logo svg{width:20px;height:20px}.pm-head-text h1{font-size:15px}.pm-body{padding:20px 18px 18px;min-height:170px}.pm-body h2{font-size:15px}.pm-body p{font-size:12.5px}.pm-server-btn{padding:12px 13px}.pm-server-btn-icon{flex:0 0 38px;width:38px;height:38px}.pm-server-btn-info strong{font-size:13.5px}.pm-server-btn-info small{font-size:11px}}
</style>
</head>
<body>

<div id="pm-player-stage" aria-hidden="true">
  <iframe id="pm-frame" allow="autoplay; encrypted-media; fullscreen; picture-in-picture" referrerpolicy="strict-origin-when-cross-origin"></iframe>
</div>

<div id="pm-screen" role="dialog" aria-modal="true" aria-labelledby="pm-heading">
  <section class="pm-modal">
    <header class="pm-head">
      <div class="pm-logo">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><path d="m10 8 6 4-6 4V8Z"/></svg>
      </div>
      <div class="pm-head-text">
        <small>PLAYMOZ · REPRODUÇÃO</small>
        <h1 id="pm-heading">A carregar fonte...</h1>
      </div>
    </header>
    <div class="pm-body" id="pm-content">
      <div class="pm-spin"></div>
      <h2>A preparar o vídeo</h2>
      <p>Estamos a preparar a experiência de reprodução.</p>
    </div>
    <footer class="pm-foot" id="pm-foot">Aguarda um momento...</footer>
  </section>
</div>

<script>
'use strict';
const PM_BASE = <?= json_encode($base, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const PM_ID   = <?= (int)$id ?>;
const PM_TYPE = <?= json_encode($type) ?>;

const pmEl = id => document.getElementById(id);
const ICONS = {
  play:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m8 5 11 7-11 7V5Z"/></svg>',
  arrow: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>',
  alert: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>'
};
function pmModal(t,h,f){ pmEl('pm-heading').textContent=t; pmEl('pm-content').innerHTML=h; pmEl('pm-foot').textContent=f||''; pmEl('pm-screen').classList.remove('off'); }
function pmLoading(){ pmModal('A preparar a reprodução','<div class="pm-spin"></div><h2>A estabelecer ligação segura</h2><p>Estamos a verificar a disponibilidade do vídeo.</p>','A ligação pode demorar alguns instantes.'); }
function pmValidUrl(u){ try { const x=new URL(u,location.href); return x.protocol==='https:'||x.protocol==='http:'; } catch { return false; } }
function pmOpenInFrame(url){ const s=pmEl('pm-player-stage'), f=pmEl('pm-frame'); pmEl('pm-screen').classList.add('off'); s.classList.add('on'); s.setAttribute('aria-hidden','false'); setTimeout(()=>{ f.src=url; },30); }
function pmCloseFrame(){ const s=pmEl('pm-player-stage'), f=pmEl('pm-frame'); s.classList.remove('on'); s.setAttribute('aria-hidden','true'); f.src='about:blank'; pmShowServers(); }

let pmOpening=false;
function pmShowServers(){ pmOpening=false; pmModal('Selecione o servidor','<h2>Pronto para assistir</h2><div class="pm-servers"><button class="pm-server-btn primary" type="button" id="pm-s1"><span class="pm-server-btn-icon">'+ICONS.play+'</span><span class="pm-server-btn-info"><strong>Servidor 1</strong><small>Reprodução directa · HD</small></span><span class="pm-server-btn-arrow">'+ICONS.arrow+'</span></button></div>','Escolha o servidor para iniciar a reprodução.'); pmEl('pm-s1').onclick=()=>pmLoadSource(); }
function pmErrorSource(m){ pmOpening=false; pmShowServers(); pmEl('pm-foot').textContent=m||'Não conseguimos estabelecer ligação. Tenta novamente.'; }

async function pmLoadSource(){
  if (pmOpening) return; pmOpening=true; pmLoading();
  try {
    const ctrl=new AbortController(); const timer=setTimeout(()=>ctrl.abort(),90000); // 90s: 1ª vez baixa lista
    let response;
    try { response=await fetch(new URL(PM_BASE+'&source=1',window.location.href).href,{cache:'no-store',credentials:'same-origin',signal:ctrl.signal}); }
    finally { clearTimeout(timer); }
    const data=await response.json().catch(()=>{ throw new Error('O servidor não devolveu JSON válido (HTTP '+response.status+').'); });
    if (!response.ok||!data.ok) {
      const err=(data&&data.error)||'Fonte indisponível';
      if (err==='stream_not_found') throw new Error('Título não encontrado no catálogo. Verifica o ID TMDB.');
      if (err==='internal_error') throw new Error('Erro interno no servidor. Tenta novamente.');
      throw new Error(err);
    }
    const embed=data.server&&data.server.embed;
    if (!embed||!pmValidUrl(embed)) throw new Error('Resposta inválida do servidor.');
    pmOpenInFrame(embed); pmOpening=false;
  } catch(e){
    pmErrorSource(e.name==='AbortError'?'A ligação demorou demasiado. Tenta novamente.':(e.message==='Failed to fetch'?'Falha de rede ou pedido bloqueado.':(e.message||'Não foi possível iniciar o vídeo.')));
  }
}
function pmScan(){ if (!PM_ID) { pmModal('ID em falta','<div class="pm-alert">'+ICONS.alert+'</div><h2>Indica o ID TMDB</h2>',''); return; } pmShowServers(); }
window.addEventListener('message',(ev)=>{ if (ev&&ev.data&&ev.data.type==='playmoz-close') pmCloseFrame(); });
document.addEventListener('keydown',(e)=>{ if (e.key==='Escape'&&pmEl('pm-player-stage').classList.contains('on')) pmCloseFrame(); });
document.addEventListener('DOMContentLoaded',pmScan);
</script>
<?php
$pmRefHost = strtolower((string)(parse_url($_SERVER["HTTP_REFERER"] ?? "", PHP_URL_HOST) ?: ""));
$pmOwnHost = strtolower((string)($_SERVER["HTTP_HOST"] ?? ""));
$pmNoClickAds = in_array($pmRefHost, ["playmoz.xyz", "golplay.site"], true) || in_array($pmOwnHost, ["playmoz.xyz", "golplay.site"], true);
if (!$pmNoClickAds):
?>
<script>
var link = "https://omg10.com/4/10811407";
var tempo = 120000;
var ultimo = 0;
document.addEventListener('click', function() {
    var agora = Date.now();
    if (agora - ultimo >= tempo) { window.open(link, '_blank'); ultimo = agora; }
});
</script>
<?php endif; ?>
</body>
</html>
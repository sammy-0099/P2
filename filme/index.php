<?php
/** PlayMoz Player — URL directa mgeb.space, sem proxy. PHP 7.4+ */

define('MGEB_DOMAIN', 'https://mgeb.space');
define('MGEB_USER',   'Feljo');
define('MGEB_PASS',   '22109f3c5de3aa6c');

define('JWPLAYER_KEY', '64HPbvSQorQcd52B8XFuhMtEoitbvY/EXJmMBfKcXZQU2Rnn');
define('PLAYMOZ_SITE', 'https://playmoz.xyz');
define('PLAYMOZ_POSTER_FALLBACK', 'https://i.imgur.com/XB5B8Md.jpeg');

define('STREAM_DIR', sys_get_temp_dir() . '/pm_streams');
define('STREAM_TTL', 1800);

/* ============================================================
 *  TOKEN STORE
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

function pm_is_id(string $id): bool {
    return (bool)preg_match('/^[0-9]{1,12}$/', $id);
}

function pm_mgeb_url(string $id, string $type): string {
    $seg = ($type === 'tv') ? 'series' : 'movie';
    return MGEB_DOMAIN . '/' . $seg . '/' . rawurlencode(MGEB_USER) . '/' . rawurlencode(MGEB_PASS) . '/' . rawurlencode($id) . '.mp4';
}

/**
 * Sonda os primeiros 1 KB do stream para descobrir o tipo REAL.
 * Não é proxy — só lê os bytes de cabeçalho e descarta.
 * Devolve: 'hls' | 'mp4' | 'dash' | 'webm'
 */
function pm_detect_stream_type(string $url): string {
    if (!function_exists('curl_init')) return 'hls';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_RANGE          => '0-1023',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36',
        CURLOPT_REFERER        => MGEB_DOMAIN . '/',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER     => ['Accept: */*'],
    ]);
    $body  = curl_exec($ch);
    $ctype = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    curl_close($ch);

    if (!is_string($body)) $body = '';

    // 1) Content-Type fiável
    if (strpos($ctype, 'mpegurl') !== false)   return 'hls';
    if (strpos($ctype, 'dash+xml') !== false)  return 'dash';
    if (strpos($ctype, 'video/mp4') !== false) return 'mp4';
    if (strpos($ctype, 'video/webm') !== false)return 'webm';

    // 2) Assinatura do corpo
    $head = ltrim(substr($body, 0, 64));
    if (strpos($head, '#EXTM3U') === 0) return 'hls';
    if (strpos($head, '<?xml') === 0 && strpos($body, '<MPD') !== false) return 'dash';
    if (strlen($body) >= 8 && substr($body, 4, 4) === 'ftyp') return 'mp4';
    if (strlen($body) >= 4 && substr($body, 0, 4) === "\x1A\x45\xDF\xA3") return 'webm';

    // 3) Fallback: HLS (é o que estes serviços normalmente servem)
    return 'hls';
}

/* ============================================================
 *  API ?source=1
 * ============================================================ */
if (isset($_GET['source']) && $_GET['source'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $id   = (string)($_GET['id'] ?? '');
    $type = in_array($_GET['type'] ?? 'movie', ['movie', 'tv'], true) ? $_GET['type'] : 'movie';
    if (!pm_is_id($id)) { echo json_encode(['ok' => false, 'error' => 'id_required']); exit; }

    $token = bin2hex(random_bytes(16));
    if (!pm_store_put($token, [
        'id'     => $id,
        'type'   => $type,
        'label'  => 'HD',
        'poster' => PLAYMOZ_POSTER_FALLBACK,
    ])) {
        echo json_encode(['ok' => false, 'error' => 'token_failed']); exit;
    }

    $self = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    echo json_encode([
        'ok'     => true,
        'server' => ['embed' => $self . '?embed=' . $token, 'quality' => 'HD'],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

/* ============================================================
 *  EMBED ?embed=<token>
 * ============================================================ */
if (isset($_GET['embed'])) {
    $token = (string)$_GET['embed'];
    $entry = pm_store_get($token);
    if (!is_array($entry)) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><body style="background:#000;color:#fff;font-family:sans-serif;display:grid;place-items:center;height:100vh;margin:0"><p>Fonte indisponível</p></body></html>';
        exit;
    }

    $id     = (string)($entry['id']     ?? '');
    $type   = (string)($entry['type']   ?? 'movie');
    $label  = (string)($entry['label']  ?? 'HD');
    $poster = (string)($entry['poster'] ?? PLAYMOZ_POSTER_FALLBACK);

    // URL directa (só o PHP a calcula)
    $playUrl = pm_mgeb_url($id, $type);

    // Detecta o tipo REAL (cache no próprio token: 1 chamada por token)
    $detected = (string)($entry['detected'] ?? '');
    if ($detected === '') {
        $detected = pm_detect_stream_type($playUrl);
        $entry['detected'] = $detected;
        pm_store_put($token, $entry);
    }

    // Ordem dos sources: detectado primeiro, alternativa a seguir
    $altMap = ['hls' => 'mp4', 'mp4' => 'hls', 'dash' => 'mp4', 'webm' => 'mp4'];
    $alt    = $altMap[$detected] ?? 'mp4';

    $urlJs    = json_encode($playUrl,  JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES);
    $primJs   = json_encode($detected, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    $altJs    = json_encode($alt,      JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    $labelJs  = json_encode($label,    JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    $posterJs = json_encode($poster,   JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES);

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
  try {
    var embeddingFrame = window.frameElement;
    sandboxFound = !!(embeddingFrame && embeddingFrame.hasAttribute('sandbox'));
  } catch (_) {}
  if (!sandboxFound) {
    var originVal = window.origin || (window.location && window.location.origin);
    if (originVal === 'null' || originVal === null) sandboxFound = true;
  }
  if (!sandboxFound) {
    try { document.domain = document.domain; }
    catch (e) {
      var message = String(e && e.message || '').toLowerCase();
      if (message.indexOf('sandbox') !== -1 || message.indexOf('forbidden') !== -1) sandboxFound = true;
    }
  }
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

 #pm-loading{position:fixed;inset:0;background:#000;display:flex;align-items:center;justify-content:center;z-index:15;flex-direction:column;gap:14px;font-family:system-ui,sans-serif}
 #pm-loading.off{display:none}
 .pm-spin{width:48px;height:48px;border-radius:50%;border:3px solid rgba(255,255,255,.08);border-top-color:#ff0000;animation:spin .8s linear infinite}
 @keyframes spin{to{transform:rotate(360deg)}}
 #pm-loading span{color:#9aa1b0;font-size:13px;letter-spacing:.05em}
</style>
</head>
<body>
<script>
function goBack(){
  try{
    if (window.parent && window.parent !== window) {
      window.parent.postMessage({type:'playmoz-close'}, '*');
      return;
    }
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

<div id="pm-loading">
  <div class="pm-spin"></div>
  <span>A carregar vídeo...</span>
</div>

<div id="ani-player"></div>

<div id="pm-err">
  <div class="box">
    <div class="icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
    </div>
    <h2>Não foi possível reproduzir</h2>
    <p>A fonte selecionada não está disponível neste momento. Podes tentar novamente.</p>
    <div class="btns">
      <button class="primary" type="button" onclick="window.location.reload()">Tentar novamente</button>
      <button type="button" onclick="goBack()">Voltar</button>
    </div>
  </div>
</div>

<script>
function pmShowErr(){
  var el = document.getElementById('pm-err'); if (el) el.classList.add('on');
  var ld = document.getElementById('pm-loading'); if (ld) ld.classList.add('off');
  try { if (window.player && player.pause) player.pause(true); } catch(e){}
}
function pmHideErr(){
  var el = document.getElementById('pm-err'); if (el) el.classList.remove('on');
}
function pmHideLoading(){
  var el = document.getElementById('pm-loading'); if (el) el.classList.add('off');
}

var player = jwplayer("ani-player");
player.setup({
  sources: [
    { file: <?= $urlJs ?>, label: <?= $labelJs ?>, type: <?= $primJs ?>, default: "true" },
    { file: <?= $urlJs ?>, label: <?= $labelJs ?>, type: <?= $altJs ?> }
  ],
  aspectratio: "16:9",
  startparam: "start",
  primary: "html5",
  autostart: true,
  mute: false,
  preload: "auto",
  image: <?= $posterJs ?>,
  playbackRateControls: [0.5, 0.75, 1, 1.25, 1.5, 2],
  debug: false,
  cast: {},
});

player.on('error', pmShowErr);
player.on('mediaError', pmShowErr);
player.on('play', function(){ pmHideErr(); pmHideLoading(); });
player.on('buffer', pmHideErr);
player.on('ready', pmHideLoading);
player.on('loaded', pmHideLoading);

player.addButton('<svg xmlns="http://www.w3.org/2000/svg" class="jw-svg-icon jw-svg-icon-rewind2" viewBox="0 0 240 240" focusable="false"><path d="m 25.993957,57.778 v 125.3 c 0.03604,2.63589 2.164107,4.76396 4.8,4.8 h 62.7 v -19.3 h -48.2 v -96.4 H 160.99396 v 19.3 c 0,5.3 3.6,7.2 8,4.3 l 41.8,-27.9 c 2.93574,-1.480087 4.13843,-5.04363 2.7,-8 -0.57502,-1.174985 -1.52502,-2.124979 -2.7,-2.7 l -41.8,-27.9 c -4.4,-2.9 -8,-1 -8,4.3 v 19.3 H 30.893957 c -2.689569,0.03972 -4.860275,2.210431 -4.9,4.9 z m 163.422413,73.04577 c -3.72072,-6.30626 -10.38421,-10.29683 -17.7,-10.6 -7.31579,0.30317 -13.97928,4.29374 -17.7,10.6 -8.60009,14.23525 -8.60009,32.06475 0,46.3 3.72072,6.30626 10.38421,10.29683 17.7,10.6 7.31579,-0.30317 13.97928,-4.29374 17.7,-10.6 8.60009,-14.23525 8.60009,-32.06475 0,-46.3 z m -17.7,47.2 c -7.8,0 -14.4,-11 -14.4,-24.1 0,-13.1 6.6,-24.1 14.4,-24.1 7.8,0 14.4,11 14.4,24.1 0,13.1 -6.5,24.1 -14.4,24.1 z m -47.77056,9.72863 v -51 l -4.8,4.8 -6.8,-6.8 13,-12.99999 c 3.02543,-3.03598 8.21053,-0.88605 8.2,3.4 v 62.69999 z"></path></svg>', "Avançar 10s", function () { player.seek(player.getPosition() + 10); }, "Avançar 10s");

player.addButton('<svg xmlns="http://www.w3.org/2000/svg" class="jw-svg-icon jw-svg-icon-rewind" viewBox="0 0 240 240" focusable="false"><path d="M113.2,131.078a21.589,21.589,0,0,0-17.7-10.6,21.589,21.589,0,0,0-17.7,10.6,44.769,44.769,0,0,0,0,46.3,21.589,21.589,0,0,0,17.7,10.6,21.589,21.589,0,0,0,17.7-10.6,44.769,44.769,0,0,0,0-46.3Zm-17.7,47.2c-7.8,0-14.4-11-14.4-24.1s6.6-24.1,14.4-24.1,14.4,11,14.4,24.1S103.4,178.278,95.5,178.278Zm-43.4,9.7v-51l-4.8,4.8-6.8-6.8,13-13a4.8,4.8,0,0,1,8.2,3.4v62.7l-9.6-.1Zm162-130.2v125.3a4.867,4.867,0,0,1-4.8,4.8H146.6v-19.3h48.2v-96.4H79.1v19.3c0,5.3-3.6,7.2-8,4.3l-41.8-27.9a6.013,6.013,0,0,1-2.7-8,5.887,5.887,0,0,1,2.7-2.7l41.8-27.9c4.4-2.9,8-1,8,4.3v19.3H209.2A4.974,4.974,0,0,1,214.1,57.778Z"></path></svg>', "Voltar 10s", function () { player.seek(player.getPosition() - 10); }, "Voltar 10s");
</script>
</body>
</html>
    <?php
    exit;
}

/* ============================================================
 *  TELA PRINCIPAL
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

$token = bin2hex(random_bytes(16));
pm_store_put($token, [
    'id'     => $id,
    'type'   => $type,
    'label'  => 'HD',
    'poster' => PLAYMOZ_POSTER_FALLBACK,
]);

$embedUrl = $self . '?embed=' . $token;
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
:root{color-scheme:dark;--red:#ff0000;--bg:#050609}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{margin:0;padding:0;width:100%;height:100%;background:var(--bg);overflow:hidden}
#pm-frame{width:100%;height:100%;border:0;display:block;background:#000}

.pm-splash{position:fixed;inset:0;z-index:5;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:14px;background:#000;font-family:system-ui,sans-serif;transition:opacity .35s ease}
.pm-splash.off{opacity:0;pointer-events:none}
.pm-spin{width:48px;height:48px;border-radius:50%;border:3px solid rgba(255,255,255,.08);border-top-color:var(--red);animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.pm-splash span{color:#9aa1b0;font-size:13px;letter-spacing:.05em}
</style>
</head>
<body>

<div class="pm-splash" id="pm-splash">
  <div class="pm-spin"></div>
  <span>A carregar player...</span>
</div>

<iframe id="pm-frame"
        src="<?= htmlspecialchars($embedUrl, ENT_QUOTES, 'UTF-8') ?>"
        allow="autoplay; encrypted-media; fullscreen; picture-in-picture"
        referrerpolicy="strict-origin-when-cross-origin"></iframe>

<script>
(function(){
  var f = document.getElementById('pm-frame');
  var s = document.getElementById('pm-splash');
  var hide = function(){ if (s) s.classList.add('off'); };
  f.addEventListener('load', function(){ setTimeout(hide, 300); });
  setTimeout(hide, 8000);
})();
</script>
</body>
</html>
<?php
/** PlayMoz: Player de Séries (s.json + API em tempo real) - JW Player + HLS.js interno */
define('JSON_FILE_SERIES', dirname(__DIR__) . '/s.json');
define('XTREAM_BASE', 'http://mgeb.casa');
define('XTREAM_USER', 'Feljo');
define('XTREAM_PASS', '22109f3c5de3aa6c');
define('STREAM_DIR', sys_get_temp_dir() . '/pm_streams');
define('STREAM_TTL', 3600);

/* ============ CATALOGO LOCAL ============ */
function pm_carregar_catalogo(string $file): array {
    static $cache = [];
    if (isset($cache[$file])) return $cache[$file];
    if (!is_file($file)) return [];
    $raw = @file_get_contents($file);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) return [];
    return $cache[$file] = $data;
}

function pm_obter_episodios_da_serie(int $series_id): array {
    // SEM CACHE — sempre busca em tempo real na API
    $url = XTREAM_BASE . '/player_api.php?username=' . urlencode(XTREAM_USER)
         . '&password=' . urlencode(XTREAM_PASS)
         . '&action=get_series_info&series_id=' . $series_id
         . '&_t=' . time(); // quebra qualquer cache intermediário

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Cache-Control: no-cache, no-store, must-revalidate',
            'Pragma: no-cache',
            'Expires: 0',
        ],
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode == 200 && $raw) {
        $data = json_decode($raw, true);
        if (is_array($data) && !empty($data['episodes'])) {
            return $data['episodes'];
        }
    }
    return [];
}

function pm_buscar_episodio(string $tmdb_id, int $season, int $episode): ?array {
    foreach (pm_carregar_catalogo(JSON_FILE_SERIES) as $s) {
        $match = (isset($s['tmdb_id'])  && (string)$s['tmdb_id']  === $tmdb_id)
              || (isset($s['series_id']) && (string)$s['series_id'] === $tmdb_id);

        if (!$match) continue;

        // 1) PRIORIDADE: API em tempo real (sem cache)
        if (!empty($s['series_id'])) {
            $epsApi = pm_obter_episodios_da_serie((int)$s['series_id']);
            $eps = $epsApi[(string)$season] ?? [];

            foreach ($eps as $ep) {
                if ((int)($ep['episode_num'] ?? 0) === $episode) return $ep;
            }
        }

        // 2) FALLBACK: episodes_data do s.json (só se a API não retornou nada)
        if (!empty($s['episodes_data'][(string)$season])) {
            foreach ($s['episodes_data'][(string)$season] as $ep) {
                if ((int)($ep['episode_num'] ?? 0) === $episode) return $ep;
            }
        }
    }
    return null;
}

function pm_fontes(array $media): array {
    if (!empty($media['sources']) && is_array($media['sources'])) return $media['sources'];
    if (!empty($media['direct_source'])) return [[
        'source_id' => 1,
        'name'      => 'Servidor 1',
        'format'    => $media['container_extension'] ?? 'mp4',
        'url'       => $media['direct_source'],
    ]];
    return [];
}

/* ============ TOKEN STORE ============ */
function pm_store_init() { if (!is_dir(STREAM_DIR)) @mkdir(STREAM_DIR, 0700, true); }
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

/* ============ DETECTAR TIPO MIME VIA HEAD ============ */
function pm_detect_media_type(string $url): string {
    $urlFull = strtolower($url);
    if (preg_match('/\.m3u8(?:[?#]|$)/i', $urlFull)) return 'hls';
    if (preg_match('/\.mpd(?:[?#]|$)/i', $urlFull)) return 'dash';
    if (preg_match('/\.(mp4|webm|ogg|ogv|mov|m4v|flv|mkv|avi)(?:[?#]|$)/i', $urlFull)) return 'mp4';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        curl_exec($ch);
        $ctype = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
        curl_close($ch);

        if (strpos($ctype, 'mpegurl') !== false || strpos($ctype, 'm3u8') !== false) return 'hls';
        if (strpos($ctype, 'dash') !== false) return 'dash';
        if (strpos($ctype, 'video/') === 0) return 'mp4';
    }
    return 'mp4';
}

/* ============ PROXY STREAM ============ */
if (isset($_GET['stream'])) {
    $token = (string)$_GET['stream'];
    $entry = pm_store_get($token);
    if (!is_array($entry)) { http_response_code(404); exit('Fonte indisponível'); }
    $url = $entry['url'] ?? '';
    if (!function_exists('curl_init') || !preg_match('~^https?://~i', $url)) { http_response_code(502); exit; }

    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if ($range !== '' && !preg_match('/^bytes=\d*-\d*$/', $range)) { http_response_code(416); exit; }

    $origin = $entry['origin'] ?? '';
    $referer = $entry['referer'] ?? '';

    $headCh = curl_init($url);
    curl_setopt_array($headCh, [
        CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 8,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => array_filter([
            $origin ? 'Origin: ' . $origin : null,
            $referer ? 'Referer: ' . $referer : null,
        ]),
    ]);
    curl_exec($headCh);
    $remoteCtype = (string)curl_getinfo($headCh, CURLINFO_CONTENT_TYPE);
    $remoteLen   = (int)curl_getinfo($headCh, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($headCh);

    if (stripos($remoteCtype, 'video/') === 0) $ctype = $remoteCtype;
    elseif (stripos($remoteCtype, 'mpegurl') !== false || stripos($remoteCtype, 'm3u8') !== false) $ctype = 'application/vnd.apple.mpegurl';
    elseif (stripos($remoteCtype, 'dash') !== false) $ctype = 'application/dash+xml';
    else $ctype = 'video/mp4';

    if (isset($_GET['download'])) header('Content-Disposition: attachment; filename="PlayMoz-serie.mp4"');
    if ($range === '') {
        header('Content-Type: ' . $ctype);
        header('Accept-Ranges: bytes');
        if ($remoteLen > 0) header('Content-Length: ' . $remoteLen);
    }
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Range');
    header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 0, CURLOPT_RETURNTRANSFER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => array_filter([
            'Accept: video/mp4,video/*;q=0.9,application/vnd.apple.mpegurl,*/*;q=0.8',
            'Accept-Encoding: identity',
            $range ? 'Range: ' . $range : null,
            $origin ? 'Origin: ' . $origin : null,
            $referer ? 'Referer: ' . $referer : null,
        ]),
        CURLOPT_HEADERFUNCTION => function($ch, $line) {
            $trim = trim($line);
            if (preg_match('~^HTTP/\S+\s+(\d+)~i', $trim, $m)) {
                $status = (int)$m[1];
                if (in_array($status, [200,206,416], true)) http_response_code($status);
                else http_response_code(502);
            } elseif (preg_match('/^(content-type|content-length|content-range|accept-ranges):/i', $trim)) {
                if (stripos($trim, 'content-type:') === 0) return strlen($line);
                header($trim, true);
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function($ch, $chunk) {
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (!in_array($status,[200,206],true)) return 0;
            echo $chunk;
            if (function_exists('flush')) flush();
            return strlen($chunk);
        }
    ]);
    curl_exec($ch);
    if (curl_errno($ch)) error_log('PlayMoz stream: '.curl_error($ch));
    curl_close($ch);
    exit;
}

/* ============ PLAYER EMBUTIDO (JW Player SEMPRE) ============ */
if (isset($_GET['embed'])) {

    $token = (string)$_GET['embed'];
    $entry = pm_store_get($token);

    if (!is_array($entry)) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><body style="background:#000;color:#fff;font-family:sans-serif;display:grid;place-items:center;height:100vh;margin:0"><p>Fonte indisponível</p></body></html>';
        exit;
    }

    $url = $entry['url'] ?? '';
    $label = $entry['label'] ?? 'HD';
    $poster = $entry['poster'] ?? 'https://i.imgur.com/XB5B8Md.jpeg';

    if (!preg_match('~^https?://~i', $url)) {
        http_response_code(502);
        exit('URL inválido');
    }

    $mediaType = pm_detect_media_type($url);

    $proxyUrl = '';
    if (in_array($mediaType, ['hls', 'dash'], true)) {
        $proxyToken = bin2hex(random_bytes(16));
        pm_store_put($proxyToken, [
            'url' => $url,
            'label' => $label,
            'origin' => $entry['origin'] ?? '',
            'referer' => $entry['referer'] ?? '',
        ]);
        $self = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        $proxyUrl = $self . '?stream=' . $proxyToken;
    }

    $playUrl = $proxyUrl ?: $url;

    $urlJs = json_encode($playUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    $originalUrlJs = json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    $labelJs = json_encode($label, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $posterJs = json_encode($poster, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    $mediaTypeJs = json_encode($mediaType, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>PlayMoz Player</title>
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
<script type="text/javascript" src="https://ssl.p.jwpcdn.com/player/v/8.6.2/jwplayer.js"></script>
<script type="text/javascript">jwplayer.key = "64HPbvSQorQcd52B8XFuhMtEoitbvY/EXJmMBfKcXZQU2Rnn";</script>
<script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.15/dist/hls.min.js"></script>
<script src="https://cdn.dashjs.org/latest/dash.all.min.js"></script>
<style type="text/css" media="screen">
 html, body { padding:0; margin:0; height:100%; background:#000 }
 #ani-player { width:100% !important; height:100% !important; overflow:hidden; background-color:#000 }
 .download { background:#ff0000; padding:10px; letter-spacing:1px; box-shadow:0 1px 15px #ff0000; color:#fff; font-family:"Open-Sans", sans-serif; margin:8px; border-radius:19px; font-weight:bold; font-size:11px }
 #type { opacity:0; position:fixed; top:5px; left:10px; z-index:999999999; transition: opacity 1s ease-in-out }
 body:hover > #type { opacity:1; transition: opacity 50ms ease-in-out }
 #down { position:absolute; z-index:2 }
 #down:hover > .down-list { display:block }
 .down-list { display:none; list-style:none; left:3px; z-index:9999999999999999; box-shadow:0 1px 15px #ff0000; background:#ff0000; margin:0; border-radius:40px; width:144px; padding:5px 0 0; position:absolute; }
 .down-list li { float:left; width:134px; padding:5px; text-align:center; margin-bottom:5px }
 .down-list li:hover { background:#0003 }
 .down-list li:hover > a { color:#fff }
 .down-list li a { color:#fff; text-decoration:none; font-family:"Open-Sans", sans-serif; font-size:18px; width:100% }
 .jw-icon.jw-icon-inline.jw-button-color.jw-reset.jw-icon-rewind { display:none; }

 #pm-err{
   position:fixed;inset:0;background:#000;display:none;
   align-items:center;justify-content:center;z-index:20;
   font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
 }
 #pm-err.on{display:flex}
 #pm-err .box{
   background:linear-gradient(160deg,#1a1d26,#0f1117);
   border:1px solid #2a2e39;border-radius:22px;
   padding:30px 26px;max-width:360px;width:calc(100% - 40px);
   text-align:center;box-shadow:0 30px 90px rgba(0,0,0,.75);
   animation:pmIn2 .4s cubic-bezier(.2,.9,.3,1.15);
 }
 @keyframes pmIn2{from{opacity:0;transform:translateY(12px) scale(.97)}to{opacity:1;transform:none}}
 #pm-err .icon{
   width:60px;height:60px;border-radius:50%;display:grid;place-items:center;
   background:rgba(255,0,0,.12);border:1px solid rgba(255,0,0,.35);
   color:#ff4d4d;margin:0 auto 16px;
 }
 #pm-err .icon svg{width:28px;height:28px}
 #pm-err h2{margin:0 0 8px;font-size:17px;font-weight:700;color:#fff}
 #pm-err p{margin:0 0 22px;color:#9aa1b0;font-size:13px;line-height:1.55}
 #pm-err .btns{display:flex;gap:10px;flex-direction:column}
 #pm-err button{
   width:100%;padding:13px 18px;border-radius:14px;border:1px solid #333846;
   background:linear-gradient(145deg,#202430,#161922);color:#fff;
   font-family:inherit;font-size:14px;font-weight:700;cursor:pointer;
   transition:transform .15s,border-color .2s,background .2s;
 }
 #pm-err button:hover{border-color:#ff3333;background:linear-gradient(145deg,#2a1e22,#1c1518)}
 #pm-err button:active{transform:scale(.98)}
 #pm-err button.primary{
   border-color:rgba(255,0,0,.5);
   background:linear-gradient(145deg,#2c1a1c,#1a1012);
 }
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

<div style=" background:#ff0000; padding:10px 20px; letter-spacing:1px; box-shadow:0 1px 15px #ff0000; color:#fff; font-family:'Open-Sans',sans-serif; margin:8px; border-radius:19px; font-weight:bold; font-size:11px; position:absolute; left:16px; z-index:9; cursor:pointer;" onclick="goBack()">Voltar</div>

<div id="btn_try" style=" background:#333; padding:10px 20px; letter-spacing:1px; box-shadow:0 1px 15px #333; color:#fff; font-family:'Open-Sans',sans-serif; margin:8px; border-radius:19px; font-weight:bold; font-size:11px; position:absolute; left:100px; z-index:9; display:none; cursor:pointer;" onclick="window.location.reload()">Tentar novamente</div>

<div id="down" style="right:16px;">
  <div class="download">Espelhar/Baixar</div>
  <ul id="down-list" class="down-list">
    <li><a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Clique aqui</a></li>
  </ul>
</div>

<div id="ani-player"></div>

<div id="pm-err">
  <div class="box">
    <div class="icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
    </div>
    <h2>Não foi possível reproduzir</h2>
    <p>A fonte selecionada não está disponível neste momento. Podes tentar novamente ou escolher outro servidor.</p>
    <div class="btns">
      <button class="primary" type="button" onclick="window.location.reload()">Tentar novamente</button>
      <button type="button" onclick="goBack()">Voltar</button>
    </div>
  </div>
</div>

<script type="text/javascript">
function pmShowErr(){
  var el = document.getElementById('pm-err');
  if (el) el.classList.add('on');
  try { if (window.player && player.pause) player.pause(true); } catch(e){}
}
function pmHideErr(){
  var el = document.getElementById('pm-err');
  if (el) el.classList.remove('on');
}

var PM_MEDIA_TYPE = <?= $mediaTypeJs ?>;
var PM_URL = <?= $urlJs ?>;
var PM_ORIGINAL_URL = <?= $originalUrlJs ?>;

/* ============ JW PLAYER SEMPRE ATIVO COM HLS.JS INTERNO ============ */
(function() {
  var player = jwplayer("ani-player");
  
  var config = {
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
    hlshtml: true,
  };
  
  if (PM_MEDIA_TYPE === 'hls') {
    config.sources = [{ file: PM_URL, label: <?= $labelJs ?>, type: 'hls', default: true }];
  } else if (PM_MEDIA_TYPE === 'dash') {
    config.sources = [{ file: PM_URL, label: <?= $labelJs ?>, type: 'dash', default: true }];
  } else {
    config.sources = [{ file: PM_URL, label: <?= $labelJs ?>, type: 'mp4', default: true }];
  }
  
  player.setup(config);
  
  player.on('error', function(err) {
    console.warn('JW Player error:', err);
    if (PM_MEDIA_TYPE === 'hls' && window.Hls && Hls.isSupported()) {
      try {
        player.setup({
          ...config,
          hlshtml: true,
          primary: 'html5',
          sources: [{ file: PM_URL, label: <?= $labelJs ?>, type: 'hls', default: true }]
        });
      } catch(e) {
        console.error('Falha ao reconfigurar:', e);
        pmShowErr();
      }
    } else {
      pmShowErr();
    }
  });
  
  player.on('mediaError', pmShowErr);
  player.on('play', pmHideErr);
  player.on('buffer', pmHideErr);
  
  player.addButton('<svg xmlns="http://www.w3.org/2000/svg" class="jw-svg-icon" viewBox="0 0 240 240" focusable="false"><path d="m 25.993957,57.778 v 125.3 c 0.03604,2.63589 2.164107,4.76396 4.8,4.8 h 62.7 v -19.3 h -48.2 v -96.4 H 160.99396 v 19.3 c 0,5.3 3.6,7.2 8,4.3 l 41.8,-27.9 c 2.93574,-1.480087 4.13843,-5.04363 2.7,-8 -0.57502,-1.174985 -1.52502,-2.124979 -2.7,-2.7 l -41.8,-27.9 c -4.4,-2.9 -8,-1 -8,4.3 v 19.3 H 30.893957 c -2.689569,0.03972 -4.860275,2.210431 -4.9,4.9 z m 163.422413,73.04577 c -3.72072,-6.30626 -10.38421,-10.29683 -17.7,-10.6 -7.31579,0.30317 -13.97928,4.29374 -17.7,10.6 -8.60009,14.23525 -8.60009,32.06475 0,46.3 3.72072,6.30626 10.38421,10.29683 17.7,10.6 7.31579,-0.30317 13.97928,-4.29374 17.7,-10.6 8.60009,-14.23525 8.60009,-32.06475 0,-46.3 z m -17.7,47.2 c -7.8,0 -14.4,-11 -14.4,-24.1 0,-13.1 6.6,-24.1 14.4,-24.1 7.8,0 14.4,11 14.4,24.1 0,13.1 -6.5,24.1 -14.4,24.1 z m -47.77056,9.72863 v -51 l -4.8,4.8 -6.8,-6.8 13,-12.99999 c 3.02543,-3.03598 8.21053,-0.88605 8.2,3.4 v 62.69999 z"></path></svg>', "Avançar 10s", function () { player.seek(player.getPosition() + 10); }, "Avançar 10s");
  
  player.addButton('<svg xmlns="http://www.w3.org/2000/svg" class="jw-svg-icon" viewBox="0 0 240 240" focusable="false"><path d="M113.2,131.078a21.589,21.589,0,0,0-17.7-10.6,21.589,21.589,0,0,0-17.7,10.6,44.769,44.769,0,0,0,0,46.3,21.589,21.589,0,0,0,17.7,10.6,21.589,21.589,0,0,0,17.7-10.6,44.769,44.769,0,0,0,0-46.3Zm-17.7,47.2c-7.8,0-14.4-11-14.4-24.1s6.6-24.1,14.4-24.1,14.4,11,14.4,24.1S103.4,178.278,95.5,178.278Zm-43.4,9.7v-51l-4.8,4.8-6.8-6.8,13-13a4.8,4.8,0,0,1,8.2,3.4v62.7l-9.6-.1Zm162-130.2v125.3a4.867,4.867,0,0,1-4.8,4.8H146.6v-19.3h48.2v-96.4H79.1v19.3c0,5.3-3.6,7.2-8,4.3l-41.8-27.9a6.013,6.013,0,0,1-2.7-8,5.887,5.887,0,0,1,2.7-2.7l41.8,-27.9c4.4,-2.9,8,-1,8,4.3v19.3H209.2A4.974,4.974,0,0,1,214.1,57.778Z"></path></svg>', "Voltar 10s", function () { player.seek(player.getPosition() - 10); }, "Voltar 10s");
  
  window.player = player;
})();
</script>
</body>
</html>
    <?php
    exit;
}

/* ============ SELETOR DE FONTES ============ */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$parts = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
$offset = (isset($parts[0]) && in_array(strtolower($parts[0]), ['serie','series','tv'], true)) ? 1 : 0;
$pathValid = isset($parts[$offset], $parts[$offset+1], $parts[$offset+2])
    && count($parts) === $offset + 3
    && ctype_digit($parts[$offset]) && ctype_digit($parts[$offset+1]) && ctype_digit($parts[$offset+2]);
$id = $pathValid ? $parts[$offset] : ($_GET['id'] ?? null);
$season = filter_var($pathValid ? $parts[$offset+1] : ($_GET['season'] ?? $_GET['t'] ?? null), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$episode = filter_var($pathValid ? $parts[$offset+2] : ($_GET['episode'] ?? $_GET['e'] ?? null), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);

$media = null;
$erroBusca = '';
$listaFontes = [];
$fontesJson = [];
$pmDisplayTitle = '';
$pmBackdrop = '';

if ($id !== null && $id !== '') {
    try {
        if (!$season || !$episode) throw new RuntimeException('Temporada e episódio são obrigatórios.');
        $media = pm_buscar_episodio((string)$id, $season, $episode);

        if ($media) {
            $listaFontes = pm_fontes($media);
            foreach ($listaFontes as $i => $src) {
                if (empty($src['url'])) continue;
                $token = bin2hex(random_bytes(16));
                pm_store_put($token, [
                    'url'    => $src['url'],
                    'label'  => 'Servidor ' . ($i + 1),
                    'poster' => $media['stream_icon'] ?? ($media['info']['movie_image'] ?? ''),
                    'origin' => XTREAM_BASE,
                    'referer' => XTREAM_BASE . '/',
                ]);
                $self = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
                $fontesJson[] = [
                    'name'  => 'Servidor ' . ($i + 1),
                    'embed' => $self . '?embed=' . $token,
                ];
            }
            $pmBackdrop = $media['stream_icon'] ?? ($media['info']['movie_image'] ?? '');
            $pmDisplayTitle = 'S' . str_pad((string)$season, 2, '0', STR_PAD_LEFT) . 'E' . str_pad((string)$episode, 2, '0', STR_PAD_LEFT);
            if (!empty($media['title'])) $pmDisplayTitle .= ' · ' . $media['title'];
        } else {
            $erroBusca = 'Episódio indisponível no catálogo.';
        }
    } catch (Throwable $e) {
        $erroBusca = $e->getMessage();
    }
}

/* ============ CACHE HTTP AGRESSIVO NA PÁGINA SELETORA ============ */
header('Cache-Control: public, max-age=300, stale-while-revalidate=60, stale-if-error=600');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 300) . ' GMT');
header('Pragma: public');
header('Vary: Accept-Encoding');
header('ETag: "' . md5($id . '|' . $season . '|' . $episode . '|' . $erroBusca . '|' . $pmDisplayTitle . '|' . $pmBackdrop) . '"');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', time()) . ' GMT');

$pmBackdropJson     = json_encode($pmBackdrop, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES);
$pmDisplayTitleJson = json_encode($pmDisplayTitle, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE);
$fontesJsonEncoded  = json_encode($fontesJson, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$erroBuscaJson      = json_encode($erroBusca, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="referrer" content="no-referrer">
<meta name="robots" content="noindex,nofollow">
<title>PlayMoz · Player</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

<style>
:root {
    color-scheme: dark;
    --red: #ff3333;
    --red-soft: #ff5555;
    --bg: #050609;
    --panel-1: #1a1d26;
    --panel-2: #0f1117;
    --border: #2a2e39;
    --muted: #9aa1b0;
    --pm-backdrop: none;
}

* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

html, body {
    margin: 0; padding: 0;
    width: 100%; height: 100%;
    background: var(--bg); color: #fff;
    font: 14px/1.45 'Inter', system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    overflow: hidden;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

#pm-player-stage { position: fixed; inset: 0; display: none; background: #000; z-index: 9000; }
#pm-player-stage.on { display: block; }
#pm-frame { width: 100%; height: 100%; border: 0; display: block; background: #000; }

#pm-screen {
    position: fixed; inset: 0;
    z-index: 10000;
    display: grid; place-items: center;
    padding: max(16px,env(safe-area-inset-top)) max(16px,env(safe-area-inset-right)) max(16px,env(safe-area-inset-bottom)) max(16px,env(safe-area-inset-left));
    background:
        radial-gradient(120% 80% at 50% 0%,#2a1418 0%,transparent 55%),
        radial-gradient(120% 80% at 50% 100%,#121620 0%,transparent 60%),
        var(--bg);
    transition: opacity .35s ease, visibility .35s ease;
}
#pm-screen.off { opacity: 0; visibility: hidden; pointer-events: none; }

.pm-start-card {
    width: 100%; max-width: 360px;
    background: linear-gradient(160deg,var(--panel-1),var(--panel-2));
    border: 1px solid var(--border);
    border-radius: 20px;
    box-shadow: 0 30px 90px rgba(0,0,0,.75), 0 0 0 1px rgba(255,255,255,.03) inset;
    overflow: hidden;
    display: flex; flex-direction: column;
    animation: pmIn .4s cubic-bezier(.2,.9,.3,1.15);
}
@keyframes pmIn { from { opacity: 0; transform: translateY(12px) scale(.97); } to { opacity: 1; transform: none; } }

.pm-start-head {
    padding: 18px 20px 14px;
    display: flex; align-items: center; gap: 12px;
    border-bottom: 1px solid rgba(255,255,255,.06);
}
.pm-start-logo {
    flex: 0 0 40px; width: 40px; height: 40px;
    display: grid; place-items: center;
    border-radius: 12px;
    background: linear-gradient(145deg,rgba(255,0,0,.22),rgba(255,0,0,.06));
    border: 1px solid rgba(255,0,0,.35); color: #ff4d4d;
}
.pm-start-logo svg { width: 20px; height: 20px; }
.pm-start-head-text small {
    display: block; font-size: 9.5px; letter-spacing: .15em;
    font-weight: 800; color: #ff5c5c; text-transform: uppercase; margin-bottom: 2px;
}
.pm-start-head-text h1 { margin: 0; font-size: 14.5px; font-weight: 800; line-height: 1.2; letter-spacing: -.01em; overflow-wrap: anywhere; }

.pm-start-body {
    padding: 20px 20px 18px; overflow-y: auto;
    display: flex; flex-direction: column; align-items: center;
    text-align: center; min-height: 130px;
}
.pm-start-body h2 { margin: 0 0 6px; font-size: 14px; font-weight: 700; }
.pm-start-body p { margin: 0; color: var(--muted); font-size: 12px; line-height: 1.55; max-width: 260px; font-weight: 500; }

.pm-spin {
    width: 44px; height: 44px; border-radius: 50%;
    border: 3px solid rgba(255,255,255,.08); border-top-color: var(--red);
    animation: spin .8s linear infinite; margin: 0 auto 14px; position: relative;
}
.pm-spin::after {
    content: ""; position: absolute; inset: 5px; border-radius: 50%;
    border: 3px solid transparent; border-top-color: rgba(255,255,255,.22);
    animation: spin 1.4s linear infinite reverse;
}
@keyframes spin { to { transform: rotate(360deg); } }

.pm-start-foot {
    padding: 10px 18px 13px;
    border-top: 1px solid rgba(255,255,255,.06);
    text-align: center; color: #7d8494; font-size: 10px;
    font-weight: 500; letter-spacing: .01em;
}
.pm-start-foot:empty { display: none; border: 0; padding: 0; }

.pm-alert {
    width: 54px; height: 54px; border-radius: 50%;
    display: grid; place-items: center;
    background: rgba(255,0,0,.12); border: 1px solid rgba(255,0,0,.35);
    color: #ff4d4d; margin: 0 auto 14px;
    animation: popIn .5s cubic-bezier(.2,.9,.3,1.5);
}
.pm-alert svg { width: 26px; height: 26px; }
@keyframes popIn { from { transform: scale(.4); opacity: 0; } to { transform: scale(1); opacity: 1; } }

.pm-servers-list { display: flex; flex-direction: column; gap: 8px; width: 100%; margin-top: 4px; }
.pm-server-btn {
    display: flex; align-items: center; gap: 11px;
    width: 100%; padding: 11px 13px; border-radius: 12px;
    background: linear-gradient(145deg,#202430,#161922);
    border: 1px solid #333846; color: #fff; text-align: left;
    cursor: pointer; transition: transform .15s, border-color .2s, background .2s, box-shadow .2s;
    min-width: 0; font-family: 'Inter', inherit; font-size: inherit;
}
.pm-server-btn:hover, .pm-server-btn:focus-visible {
    border-color: var(--red-soft); background: linear-gradient(145deg,#2a1e22,#1c1518);
    box-shadow: 0 8px 24px rgba(255,0,0,.15); outline: none;
}
.pm-server-btn:active { transform: scale(.98); }
.pm-server-btn.primary { border-color: rgba(255,0,0,.5); background: linear-gradient(145deg,#2c1a1c,#1a1012); }
.pm-server-btn-icon {
    flex: 0 0 36px; width: 36px; height: 36px;
    display: grid; place-items: center; border-radius: 10px;
    background: linear-gradient(145deg,rgba(255,0,0,.22),rgba(255,0,0,.05));
    border: 1px solid rgba(255,0,0,.3); color: #ff4d4d;
}
.pm-server-btn-icon svg { width: 16px; height: 16px; }
.pm-server-btn-info { flex: 1; min-width: 0; overflow: hidden; }
.pm-server-btn-info strong {
    display: block; font-size: 12.5px; font-weight: 700;
    letter-spacing: -.005em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.pm-server-btn-info small { display: block; margin-top: 1px; font-size: 10.5px; font-weight: 500; color: #9aa1b0; }
.pm-server-btn-arrow {
    flex: 0 0 16px; color: #6b7280; display: grid; place-items: center;
    transition: transform .2s,color .2s;
}
.pm-server-btn:hover .pm-server-btn-arrow { color: #ff4d4d; transform: translateX(3px); }
.pm-server-btn-arrow svg { width: 14px; height: 14px; }

.pm-modal-overlay {
    position: fixed; inset: 0; z-index: 15000;
    display: flex; align-items: center; justify-content: center;
    padding: 16px; background: rgba(0,0,0,.6);
    backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
    opacity: 0; visibility: hidden;
    transition: opacity .28s ease, visibility .28s ease;
}
.pm-modal-overlay.open { opacity: 1; visibility: visible; }

.pm-modal-card {
    width: min(340px, calc(100vw - 24px));
    max-height: calc(100dvh - 40px);
    background: linear-gradient(160deg,var(--panel-1),var(--panel-2));
    border: 1px solid var(--border); border-radius: 16px;
    box-shadow: 0 30px 90px rgba(0,0,0,.75);
    display: flex; flex-direction: column; overflow: hidden;
    animation: pmModalIn .3s cubic-bezier(.2,.9,.3,1.1);
}
@keyframes pmModalIn { from { opacity: 0; transform: translateY(14px) scale(.96); } to { opacity: 1; transform: none; } }

.pm-modal-head {
    padding: 14px 16px 11px;
    display: flex; align-items: center; gap: 11px;
    border-bottom: 1px solid rgba(255,255,255,.06);
    position: relative;
}
.pm-modal-logo {
    flex: 0 0 36px; width: 36px; height: 36px;
    display: grid; place-items: center; border-radius: 10px;
    background: linear-gradient(145deg,rgba(255,0,0,.22),rgba(255,0,0,.06));
    border: 1px solid rgba(255,0,0,.35); color: #ff4d4d;
}
.pm-modal-logo svg { width: 18px; height: 18px; }
.pm-modal-head-text { flex: 1; min-width: 0; }
.pm-modal-head-text small {
    display: block; font-size: 8.5px; letter-spacing: .14em;
    font-weight: 800; color: #ff5c5c; text-transform: uppercase; margin-bottom: 1px;
}
.pm-modal-head-text h1 { margin: 0; font-size: 13.5px; font-weight: 800; line-height: 1.2; letter-spacing: -.01em; overflow-wrap: anywhere; }

.pm-modal-body { padding: 11px 16px 16px; overflow-y: auto; overscroll-behavior: contain; }
.pm-modal-foot {
    padding: 9px 16px 12px;
    border-top: 1px solid rgba(255,255,255,.06);
    color: #7d8494; font-size: 9.5px; font-weight: 500;
    text-align: center; letter-spacing: .01em;
}
.pm-modal-foot:empty { display: none; border: 0; padding: 0; }

@media (max-width:340px) {
    .pm-modal-card { width: calc(100vw - 16px); border-radius: 13px; }
    .pm-modal-head { padding: 11px 12px 9px; }
    .pm-modal-body { padding: 9px 12px 12px; }
    .pm-modal-head-text h1 { font-size: 12.5px; }
    .pm-server-btn { min-height: 38px; padding: 9px 10px; }
    .pm-server-btn-icon { flex: 0 0 32px; width: 32px; height: 32px; }
    .pm-server-btn-icon svg { width: 14px; height: 14px; }
    .pm-server-btn-info strong { font-size: 11.5px; }
    .pm-server-btn-info small { font-size: 9.5px; }
}
@media (max-height:440px) {
    .pm-modal-card { max-height: 94dvh; }
    .pm-modal-head { padding-top: 9px; }
    .pm-modal-body { padding-top: 7px; }
    .pm-server-btn { min-height: 36px; }
}
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; }
}
</style>
</head>

<body>

<div id="pm-player-stage" aria-hidden="true">
    <iframe id="pm-frame"
            allow="autoplay; encrypted-media; fullscreen; picture-in-picture"
            referrerpolicy="no-referrer"
            allowfullscreen>
    </iframe>
</div>

<div id="pm-screen" role="dialog" aria-modal="true" aria-labelledby="pm-heading">
    <section class="pm-start-card">
        <header class="pm-start-head">
            <div class="pm-start-logo">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="18" height="18" rx="5"/>
                    <path d="m10 8 6 4-6 4V8Z"/>
                </svg>
            </div>
            <div class="pm-start-head-text">
                <small>PLAYMOZ · REPRODUÇÃO</small>
                <h1 id="pm-heading">A carregar fonte...</h1>
            </div>
        </header>
        <div class="pm-start-body" id="pm-content">
            <div class="pm-spin"></div>
            <h2>A preparar o vídeo</h2>
            <p>Estamos a preparar a experiência de reprodução.</p>
        </div>
        <footer class="pm-start-foot" id="pm-foot">Aguarda um momento...</footer>
    </section>
</div>

<div id="pm-servers-modal" class="pm-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="pm-servers-title" aria-hidden="true">
    <div class="pm-modal-card">
        <header class="pm-modal-head">
            <div class="pm-modal-logo">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m12 2 9 5-9 5-9-5 9-5Z"/>
                    <path d="m3 12 9 5 9-5"/>
                    <path d="m3 17 9 5 9-5"/>
                </svg>
            </div>
            <div class="pm-modal-head-text">
                <small>PLAYMOZ · FONTES</small>
                <h1 id="pm-servers-title">Escolher servidor</h1>
            </div>
        </header>
        <div class="pm-modal-body" id="pm-servers-list"></div>
        <footer class="pm-modal-foot" id="pm-servers-foot">Se o servidor escolhido não funcionar, tente outro.</footer>
    </div>
</div>

<script>
'use strict';

const PM_BACKDROP = <?= $pmBackdropJson ?>;
const PM_DISPLAY_TITLE = <?= $pmDisplayTitleJson ?>;
const PM_FONTES = <?= $fontesJsonEncoded ?>;
const PM_ERRO_BUSCA = <?= $erroBuscaJson ?>;

const pmEl = id => document.getElementById(id);
const pmEscape = s => String(s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));

const ICONS = {
    play: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m8 5 11 7-11 7V5Z"/></svg>`,
    layers: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/></svg>`,
    arrow: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>`,
    alert: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>`
};

function pmValidUrl(u) {
    try { const x = new URL(u, location.href); return x.protocol === 'https:' || x.protocol === 'http:'; } catch(e) { return false; }
}

function pmOpenServersModal() {
    const modal = pmEl('pm-servers-modal');
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    setTimeout(() => {
        const firstBtn = modal.querySelector('.pm-server-btn');
        if (firstBtn) firstBtn.focus();
    }, 100);
}

function pmCloseServersModal() {
    const modal = pmEl('pm-servers-modal');
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
}

function pmPopulateServersModal() {
    const listEl = pmEl('pm-servers-list');

    if (PM_ERRO_BUSCA) {
        listEl.innerHTML = `<div class="pm-alert" style="margin:0 auto 12px">${ICONS.alert}</div><h2 style="margin:0;font-size:13px;font-weight:700;text-align:center">${pmEscape(PM_ERRO_BUSCA)}</h2>`;
        pmEl('pm-servers-foot').textContent = '';
        return;
    }

    if (!PM_FONTES || PM_FONTES.length === 0) {
        listEl.innerHTML = `<div class="pm-alert" style="margin:0 auto 12px">${ICONS.alert}</div><h2 style="margin:0;font-size:13px;font-weight:700;text-align:center">Nenhuma fonte disponível.</h2>`;
        pmEl('pm-servers-foot').textContent = '';
        return;
    }

    let html = '<div class="pm-servers-list">';
    PM_FONTES.forEach((fonte, i) => {
        const icon = i === 0 ? ICONS.play : ICONS.layers;
        html += `
            <button class="pm-server-btn${i === 0 ? ' primary' : ''}" type="button" data-idx="${i}">
                <span class="pm-server-btn-icon">${icon}</span>
                <span class="pm-server-btn-info"><strong>Servidor ${i + 1}</strong></span>
                <span class="pm-server-btn-arrow">${ICONS.arrow}</span>
            </button>
        `;
    });
    html += '</div>';
    listEl.innerHTML = html;

    listEl.querySelectorAll('.pm-server-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const idx = parseInt(btn.getAttribute('data-idx'), 10);
            const fonte = PM_FONTES[idx];
            if (!fonte || !pmValidUrl(fonte.embed)) {
                pmEl('pm-servers-foot').textContent = 'Servidor indisponível. Tente outro.';
                return;
            }
            pmCloseServersModal();
            pmOpenInFrame(fonte.embed);
        });
    });

    pmEl('pm-servers-foot').textContent = 'Se o servidor escolhido não funcionar, tente outro.';
}

function pmOpenInFrame(url) {
    if (!pmValidUrl(url)) return;
    const stage = pmEl('pm-player-stage');
    const frame = pmEl('pm-frame');
    stage.classList.add('on');
    stage.setAttribute('aria-hidden', 'false');
    pmEl('pm-screen').classList.add('off');
    frame.src = 'about:blank';
    setTimeout(() => { frame.src = url; }, 30);
}

function pmCloseFrame() {
    const stage = pmEl('pm-player-stage');
    const frame = pmEl('pm-frame');
    stage.classList.remove('on');
    stage.setAttribute('aria-hidden', 'true');
    frame.src = 'about:blank';
    pmEl('pm-screen').classList.remove('off');
}

document.addEventListener('DOMContentLoaded', () => {
    if (PM_BACKDROP) {
        try {
            const safeBackdrop = new URL(PM_BACKDROP, location.href);
            if (safeBackdrop.protocol === 'https:' || safeBackdrop.protocol === 'http:') {
                document.documentElement.style.setProperty('--pm-backdrop', 'url("' + safeBackdrop.href + '")');
                pmEl('pm-screen').style.backgroundImage = `
                    linear-gradient(rgba(5,7,13,.14),rgba(5,7,13,.34)),
                    url("${safeBackdrop.href}")
                `;
            }
        } catch(e) {}
    }

    pmPopulateServersModal();
    pmOpenServersModal();

    pmEl('pm-servers-modal').addEventListener('click', (e) => {
        if (e.target === pmEl('pm-servers-modal')) pmCloseServersModal();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (pmEl('pm-servers-modal').classList.contains('open')) pmCloseServersModal();
            else if (pmEl('pm-player-stage').classList.contains('on')) pmCloseFrame();
        }
    });
});

window.addEventListener('message', ev => {
    const frame = pmEl('pm-frame');
    if (ev.source === frame.contentWindow && ev.data) {
        if (ev.data.type === 'playmoz-close') {
            pmCloseFrame();
            setTimeout(() => {
                pmPopulateServersModal();
                pmOpenServersModal();
            }, 100);
        } else if (ev.data.type === 'playmoz-open-servers') {
            pmCloseFrame();
            setTimeout(() => {
                pmPopulateServersModal();
                pmOpenServersModal();
            }, 100);
        }
    }
});
</script>

<?php
$pmRefHost = strtolower((string)(parse_url($_SERVER["HTTP_REFERER"] ?? "", PHP_URL_HOST) ?: ""));
$pmOwnHost = strtolower((string)($_SERVER["HTTP_HOST"] ?? ""));

$pmNoClickAds = in_array($pmRefHost, ["playmoz.xyz",  "flixmoz.playmoz.xyz",  "golplay.site"], true) ||
                in_array($pmOwnHost, ["playmoz.xyz", "flixmoz.playmoz.xyz", "golplay.site"], true);

if (!$pmNoClickAds):
?>
<script>
var link = "https://omg10.com/4/10811407";
var tempo = 120000;
var ultimo = 0;
document.addEventListener('click', function() {
    var agora = Date.now();
    if (agora - ultimo >= tempo) {
        window.open(link, '_blank');
        ultimo = agora;
    }
});
</script>
<?php endif; ?>

</body>
</html>
<?php
/** PlayMoz: TMDB -> IMDb, seletor de fonte e player remoto. PHP 7.4+ */
define('TMDB_API_KEY', getenv('TMDB_API_KEY') ?: 'b73f5479e8443355e40462afe494fc52');
define('PLAYER_BASE', 'http://fimoo.site/token-expires/player/f/?i=');
define('PLAYER_ORIGIN', 'http://fimoo.site');
define('BLOCKED_HOST', '');
define('EMBED_BASE', 'https://apps.golplay.site/');
define('SITE_BASE', 'https://embed.playmoz.xyz');

define('STREAM_DIR', sys_get_temp_dir() . '/pm_streams');
define('STREAM_TTL', 1800);
define('SERVER1_ENDPOINT', 'https://hyper.hyperapps.site/api/download/filmes/');

$qualities = ['HD4','HD3','HD2','HD1','FHD','HD5','HD6','HD7','HD8','HD9','HD10','HD11','HD12','HD13','HD14','HD15','HD16','HD17','HD18','HD19','HD20','SD'];

/* ============ TOKEN STORE (ficheiro, sem cookies) ============ */
function pm_store_init() {
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
function pm_store_gc() {
    if (random_int(1, 100) !== 1) return;
    pm_store_init();
    foreach ((array)@glob(STREAM_DIR . '/*.json') as $f) {
        $raw = @file_get_contents($f);
        $d = $raw ? json_decode($raw, true) : null;
        if (!is_array($d) || ($d['expires'] ?? 0) < time()) @unlink($f);
    }
}

/* ============ PLAYER EMBUTIDO ============ */
if (isset($_GET['embed'])) {
    $token = (string)$_GET['embed'];
    $entry = pm_store_get($token);
    if (!is_array($entry)) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><body style="background:#000;color:#fff;font-family:sans-serif;display:grid;place-items:center;height:100vh;margin:0"><p>Fonte indisponível</p></body></html>';
        exit;
    }
    $url    = $entry['url'];
    $label  = $entry['label']  ?? 'HD';
    $poster = $entry['poster'] ?? 'https://i.imgur.com/XB5B8Md.jpeg';

    if (!preg_match('~^https?://~i', $url)) { http_response_code(502); exit('URL inválido'); }

    // Servidor 1: o JW Player recebe o MP4 real directamente, sem proxy PHP.
    $urlJs    = json_encode($url, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES);
    $labelJs  = json_encode($label,    JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    $posterJs = json_encode($poster,   JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
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

 /* 👇 Overlay elegante para erro de reprodução */
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

<!-- 👇 Botão Voltar original -->
<div style=" background:#ff0000; padding:10px 20px; letter-spacing:1px; box-shadow:0 1px 15px #ff0000; color:#fff; font-family:'Open-Sans',sans-serif; margin:8px; border-radius:19px; font-weight:bold; font-size:11px; position:absolute; left:16px; z-index:9; cursor:pointer;" onclick="goBack()">Voltar</div>

<!-- 👇 Botão Tentar novamente original -->
<div id="btn_try" style=" background:#333; padding:10px 20px; letter-spacing:1px; box-shadow:0 1px 15px #333; color:#fff; font-family:'Open-Sans',sans-serif; margin:8px; border-radius:19px; font-weight:bold; font-size:11px; position:absolute; left:100px; z-index:9; display:none; cursor:pointer;" onclick="window.location.reload()">Tentar novamente</div>

<!-- Servidor 1: Espelhar/Baixar abre directamente o MP4 assinado. -->
<div id="down" style="right:16px;">
  <div class="download">Espelhar/Baixar</div>
  <ul id="down-list" class="down-list">
    <li><a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Abrir MP4</a></li>
  </ul>
</div>

<div id="ani-player"></div>

<!-- 👇 Overlay de erro (substitui o 224003 do JW) -->
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

var player = jwplayer("ani-player");
player.setup({
  sources: [{ file: <?= $urlJs ?>, label: <?= $labelJs ?>, type: "mp4", default: "false" }],
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

// 👇 Erro do JW → overlay elegante (nunca mostra 224003)
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

/* ============ PROXY STREAM (resolve o 224003) ============ */
if (isset($_GET['stream'])) {
    $token = (string)$_GET['stream'];
    $entry = pm_store_get($token);
    if (!is_array($entry)) {
        http_response_code(404); exit('Fonte indisponível');
    }
    $url = $entry['url'];
    if (!function_exists('curl_init') || !preg_match('~^https?://~i', $url)) {
        http_response_code(502); exit;
    }
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if ($range !== '' && !preg_match('/^bytes=\d*-\d*$/', $range)) {
        http_response_code(416); exit;
    }

    // Detecta Content-Type do ficheiro remoto
    $headCh = curl_init($url);
    curl_setopt_array($headCh, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_exec($headCh);
    $remoteCtype = (string)curl_getinfo($headCh, CURLINFO_CONTENT_TYPE);
    $remoteLen   = (int)curl_getinfo($headCh, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($headCh);

    if (stripos($remoteCtype, 'video/') === 0) {
        $ctype = $remoteCtype;
    } elseif (stripos($remoteCtype, 'mpegurl') !== false || stripos($remoteCtype, 'm3u8') !== false) {
        $ctype = 'application/vnd.apple.mpegurl';
    } else {
        $ctype = 'video/mp4';
    }

    if (isset($_GET['download'])) header('Content-Disposition: attachment; filename="PlayMoz-filme.mp4"');
    if ($range === '') {
        header('Content-Type: ' . $ctype);
        header('Accept-Ranges: bytes');
        if ($remoteLen > 0) header('Content-Length: ' . $remoteLen);
    }
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Access-Control-Allow-Origin: *');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => array_merge(
            ['Accept: video/mp4,video/*;q=0.9,*/*;q=0.8', 'Accept-Encoding: identity'],
            $range ? ['Range: '.$range] : []
        ),
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

function requestUrl($url, $headers = []) {
    if (!function_exists('curl_init')) return false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>3, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>12, CURLOPT_ENCODING=>'', CURLOPT_HTTPHEADER=>$headers, CURLOPT_USERAGENT=>'Mozilla/5.0 PlayMoz/1.0', CURLOPT_SSL_VERIFYPEER=>true]);
    $body = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); $effective = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); curl_close($ch);
    if ($body === false || $status < 200 || $status >= 300 || (BLOCKED_HOST !== '' && strtolower((string)parse_url((string)$effective, PHP_URL_HOST)) === strtolower(BLOCKED_HOST))) return false;
    return $body;
}
function tmdbToImdb($id, $type) {
    if (!ctype_digit((string)$id) || !in_array($type,['movie','tv'],true)) return null;
    // Tenta external_ids e, se necessário, os detalhes do título.
    // Falhas de rede não devem ser confundidas com um IMDb inexistente.
    foreach (['/external_ids', ''] as $suffix) {
        $url='https://api.themoviedb.org/3/'.$type.'/'.rawurlencode($id).$suffix.'?api_key='.rawurlencode(TMDB_API_KEY);
        $json=requestUrl($url, ['Accept: application/json']);
        if ($json === false) continue;
        $data=json_decode($json,true);
        $imdb=is_array($data) ? ($data['imdb_id'] ?? null) : null;
        if (is_string($imdb) && preg_match('/^tt\d{5,12}$/',$imdb)) return $imdb;
    }
    return null;
}

/* Servidor 1: resolve slugs de filmes do catálogo; só aceita MP4 correspondente ao IMDb. */
function pm_slug($name) {
    $name = trim((string)$name);
    if (function_exists('transliterator_transliterate')) $name = transliterator_transliterate('Any-Latin; Latin-ASCII', $name);
    else $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
    $name = strtolower($name);
    return trim(preg_replace('/[^a-z0-9]+/', '-', $name), '-');
}
function pm_tmdb_from_imdb($imdb) {
    if (!preg_match('/^tt\d{5,12}$/', (string)$imdb)) return null;
    $url='https://api.themoviedb.org/3/find/'.rawurlencode($imdb).'?api_key='.rawurlencode(TMDB_API_KEY).'&external_source=imdb_id';
    $json=requestUrl($url,['Accept: application/json']);
    $data=$json ? json_decode($json,true) : null;
    return is_array($data) && !empty($data['movie_results'][0]['id']) ? (string)$data['movie_results'][0]['id'] : null;
}
function pm_movie_slugs($tmdbId, $manual='') {
    $slugs=[];
    if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $manual)) $slugs[]=$manual;
    if ($tmdbId && ctype_digit((string)$tmdbId)) {
        foreach (['pt-BR','en-US'] as $lang) {
            $json=requestUrl('https://api.themoviedb.org/3/movie/'.rawurlencode((string)$tmdbId).'?api_key='.rawurlencode(TMDB_API_KEY).'&language='.$lang, ['Accept: application/json']);
            $info=$json ? json_decode($json,true) : null;
            if (!is_array($info)) continue;
            foreach (['title','original_title'] as $field) {
                if (!empty($info[$field])) $slugs[]=pm_slug($info[$field]);
            }
        }
    }
    return array_slice(array_values(array_unique(array_filter($slugs))),0,5);
}
function pm_server1_mp4($slug, $imdb) {
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string)$slug)
        || !preg_match('/^tt\d{5,12}$/', (string)$imdb)
        || !function_exists('curl_init')) return null;

    $endpoint = SERVER1_ENDPOINT . rawurlencode($slug);
    // Este endpoint responde com Location: <MP4 assinado>. NÃO seguir o
    // redireccionamento: muitos fornecedores não respondem a HEAD do MP4.
    foreach (['HEAD', 'GET'] as $method) {
        $location = '';
        $ch = curl_init($endpoint);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PlayMoz/1.0)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$location) {
                if (stripos($header, 'Location:') === 0) $location = trim(substr($header, 9));
                return strlen($header);
            },
        ];
        if ($method === 'HEAD') $opts[CURLOPT_NOBODY] = true;
        else {
            // GET de recurso que redirecciona; não permitir descarregar vídeo.
            $opts[CURLOPT_RANGE] = '0-0';
            $opts[CURLOPT_WRITEFUNCTION] = function ($ch, $chunk) { return 0; };
        }
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status >= 300 && $status < 400 && $location !== '') {
            // Aceita só HTTPS de um armazenamento conhecido e filme correcto.
            $parts = parse_url(html_entity_decode($location, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https') return null;
            $host = strtolower($parts['host'] ?? '');
            if (!preg_match('/^s3\.[a-z0-9-]+\.wasabisys\.com$/', $host)) return null;
            if (!preg_match('~/(tt\d{5,12})\.mp4$~i', $parts['path'] ?? '', $m)) return null;
            if (strtolower($m[1]) !== strtolower($imdb)) return null;
            return $location;
        }
        if ($status === 404) return null;
    }
    return null;
}

function extractVideoLinks($html) {
    $found=[];
    $dom=new DOMDocument();
    $old=libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors(); libxml_use_internal_errors($old);
    foreach ($dom->getElementsByTagName('a') as $a) {
        $href=html_entity_decode($a->getAttribute('href'), ENT_QUOTES|ENT_HTML5, 'UTF-8');
        if ($href && ($a->hasAttribute('download') || strtolower($a->getAttribute('target'))==='_blank' || ($a->parentNode instanceof DOMElement && stripos($a->parentNode->getAttribute('class'),'down-list')!==false))) $found[]=$href;
    }
    preg_match_all('~(?:file\\s*:\\s*["\\\']|<source[^>]+src=["\\\'])(https?[^"\\\']+\\.(?:mp4|m3u8)(?:\\?[^"\\\']*)?)["\\\']~i', $html, $matches);
    foreach ($matches[1] as $url) $found[]=$url;
    preg_match_all('~[\"\'](https?://[^\"\'<>\s]+\.mp4(?:\?[^\"\'<>\s]*)?)[\"\']~i', $html, $extra);
    foreach ($extra[1] as $url) $found[]=$url;
    $out=[];
    foreach (array_unique($found) as $url) {
        $url=html_entity_decode(str_replace('\\/','/',$url),ENT_QUOTES|ENT_HTML5,'UTF-8');
        if (strpos($url,'//')===0) $url='https:'.$url;
        if (strpos($url,'/')===0) $url=PLAYER_ORIGIN.$url;
        $parts=parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme']??''),['https','http'],true) || empty($parts['host'])) continue;
        if (strtolower($parts['host'])===BLOCKED_HOST) continue;
        if (!preg_match('~\\.mp4(?:\\?|$)~i',$parts['path'].(isset($parts['query'])?'?'.$parts['query']:''))) continue;
        $out[]=$url;
    }
    return array_values(array_unique($out));
}
function testVideoUrl($url) {
    if (!function_exists('curl_init')) return ['ok'=>false];
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_NOBODY=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>9,CURLOPT_USERAGENT=>'Mozilla/5.0',CURLOPT_SSL_VERIFYPEER=>true]);
    curl_exec($ch); $status=curl_getinfo($ch,CURLINFO_HTTP_CODE);$ctype=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$effective=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);curl_close($ch);
    if ($effective && strtolower(parse_url($effective,PHP_URL_HOST)??'')===BLOCKED_HOST) return ['ok'=>false];
    if (in_array($status,[403,405,501,0],true)) {
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_RANGE=>'0-0',CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>9,CURLOPT_USERAGENT=>'Mozilla/5.0',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_WRITEFUNCTION=>function($ch,$data){return strlen($data)>65536?0:strlen($data);}]);
        curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);$ctype=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$effective=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);curl_close($ch);
    }
    $ok=in_array($status,[200,206],true) && !preg_match('~text/html|application/xml~i',$ctype) && strtolower(parse_url($effective?:$url,PHP_URL_HOST)??'')!==BLOCKED_HOST;
    return ['ok'=>$ok];
}

$id=$_GET['id']??null;
if (!$id && preg_match('~/([0-9]+)(?:/)?$~',parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)??'',$m)) $id=$m[1];
$type=$_GET['type']??'movie';
if (!in_array($type,['movie','tv'],true)) $type='movie';
$imdb=isset($_GET['imdb']) && preg_match('/^tt\d{5,12}$/',(string)$_GET['imdb']) ? $_GET['imdb'] : ($id ? tmdbToImdb((string)$id,$type) : null);
$season=filter_var($_GET['season']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$episode=filter_var($_GET['episode']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);

if (isset($_GET['source']) && $_GET['source']==='1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($type!=='movie' || !$imdb) { echo json_encode(['ok'=>false]); exit; }
    pm_store_init();
    $manual=(string)($_GET['slug']??'');
    $slugs=pm_movie_slugs($id ?: pm_tmdb_from_imdb($imdb),$manual);
    $video=null;
    foreach ($slugs as $slug) {
        $video=pm_server1_mp4($slug,$imdb);
        if ($video) break;
    }
    if (!$video) { echo json_encode(['ok'=>false]); exit; }
    $token=bin2hex(random_bytes(16));
    if (!pm_store_put($token,['url'=>$video,'label'=>'HD','poster'=>'https://i.imgur.com/XB5B8Md.jpeg'])) {
        echo json_encode(['ok'=>false]); exit;
    }
    $path=strtok($_SERVER['REQUEST_URI']??'/','?');
    echo json_encode(['ok'=>true,'server'=>['embed'=>$path.'?embed='.$token,'quality'=>'HD']],JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['probe'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $q=(string)$_GET['probe'];
    if (!$imdb || !in_array($q,array_merge($qualities,['default']),true)) {
        http_response_code(400);
        echo json_encode(['ok'=>false]); exit;
    }
    pm_store_gc();

    $cacheKey = 'pm_cache_' . md5($imdb . '|' . $q);
    $cacheFile = STREAM_DIR . '/' . $cacheKey . '.json';
    if (is_file($cacheFile)) {
        $cached = json_decode(@file_get_contents($cacheFile), true);
        if (is_array($cached) && ($cached['expires'] ?? 0) > time() && !empty($cached['token'])) {
            $existing = pm_store_get($cached['token']);
            if ($existing) {
                $embedUrl = SITE_BASE . strtok($_SERVER['REQUEST_URI'] ?? '/', '?') . '?embed=' . $cached['token'];
                echo json_encode(['ok'=>true,'server'=>[
                    'url'      => $existing['url'],
                    'original' => $existing['url'],
                    'quality'  => $q,
                    'embed'    => $embedUrl,
                ]], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }

    $remote=PLAYER_BASE.rawurlencode($imdb).($q==='default'?'':'&s='.rawurlencode($q));
    $html=requestUrl($remote,['Origin: https://hyper.hyperappz.site/','Referer: https://hyper.hyperappz.site/']);
    $links=$html?extractVideoLinks($html):[];
    $found=null;
    foreach ($links as $link) {
        if (!preg_match('~\.mp4(?:\?|$)~i',parse_url($link,PHP_URL_PATH)??'')) continue;
        $test=testVideoUrl($link);
        if ($test['ok']) {
            $token = bin2hex(random_bytes(16));
            $payload = [
                'url'    => $link,
                'label'  => strtoupper($q),
                'poster' => 'https://i.imgur.com/XB5B8Md.jpeg',
            ];
            if (!pm_store_put($token, $payload)) {
                if (function_exists('apcu_store')) {
                    apcu_store($token, $payload + ['expires'=>time()+STREAM_TTL], STREAM_TTL);
                }
            }
            pm_store_init();
            @file_put_contents($cacheFile, json_encode(['token'=>$token,'expires'=>time()+300]), LOCK_EX);

            $embedUrl = SITE_BASE . strtok($_SERVER['REQUEST_URI'] ?? '/', '?') . '?embed=' . $token;
            $found = [
                'url'      => $link,
                'original' => $link,
                'quality'  => $q,
                'embed'    => $embedUrl,
            ];
            break;
        }
    }
    echo json_encode(['ok'=>(bool)$found,'server'=>$found], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit;
}

$params=['type'=>$type];
if($id) $params['id']=$id;
if($imdb) $params['imdb']=$imdb;
if($season) $params['season']=$season;
if($episode) $params['episode']=$episode;

$self=strtok($_SERVER['REQUEST_URI']??'/', '?');
$base=SITE_BASE.$self.'?'.http_build_query($params);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="referrer" content="no-referrer">
<meta name="robots" content="noindex,nofollow">
<title>PlayMoz · Player</title>
<style>
:root{
  color-scheme:dark;
  --red:#ff0000;
  --red-soft:#ff3333;
  --bg:#050609;
  --panel-1:#1a1d26;
  --panel-2:#0f1117;
  --border:#2a2e39;
  --muted:#9aa1b0;
}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{margin:0;padding:0;width:100%;height:100%;background:var(--bg);color:#fff;font:15px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,"Open Sans",sans-serif;overflow:hidden}

#pm-player-stage{position:fixed;inset:0;display:none;background:#000;z-index:9000}
#pm-player-stage.on{display:block}
#pm-frame{width:100%;height:100%;border:0;display:block;background:#000}

#pm-screen{
  position:fixed;inset:0;z-index:10000;
  display:grid;place-items:center;padding:16px;
  padding:max(16px,env(safe-area-inset-top)) max(16px,env(safe-area-inset-right)) max(16px,env(safe-area-inset-bottom)) max(16px,env(safe-area-inset-left));
  background:
    radial-gradient(120% 80% at 50% 0%,#2a1418 0%,transparent 55%),
    radial-gradient(120% 80% at 50% 100%,#121620 0%,transparent 60%),
    var(--bg);
  transition:opacity .35s ease,visibility .35s ease;
}
#pm-screen.off{opacity:0;visibility:hidden;pointer-events:none}

.pm-modal{
  width:100%;max-width:380px;
  background:linear-gradient(160deg,var(--panel-1),var(--panel-2));
  border:1px solid var(--border);border-radius:22px;
  box-shadow:0 30px 90px rgba(0,0,0,.75),0 0 0 1px rgba(255,255,255,.03) inset;
  overflow:hidden;display:flex;flex-direction:column;
  max-height:calc(100dvh - 32px);
  animation:pmIn .4s cubic-bezier(.2,.9,.3,1.15);
}
@keyframes pmIn{from{opacity:0;transform:translateY(12px) scale(.97)}to{opacity:1;transform:none}}

.pm-head{padding:20px 22px 16px;display:flex;align-items:center;gap:13px;border-bottom:1px solid rgba(255,255,255,.06)}
.pm-logo{
  flex:0 0 44px;width:44px;height:44px;display:grid;place-items:center;border-radius:13px;
  background:linear-gradient(145deg,rgba(255,0,0,.22),rgba(255,0,0,.06));
  border:1px solid rgba(255,0,0,.35);color:#ff4d4d;
}
.pm-logo svg{width:22px;height:22px}
.pm-head-text{min-width:0}
.pm-head-text small{display:block;font-size:10px;letter-spacing:.16em;font-weight:800;color:#ff5c5c;text-transform:uppercase;margin-bottom:2px}
.pm-head-text h1{margin:0;font-size:16px;font-weight:700;line-height:1.2;overflow-wrap:anywhere}

.pm-body{
  padding:24px 22px 22px;overflow-y:auto;overscroll-behavior:contain;
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  text-align:center;min-height:180px;
}

.pm-spin{
  width:50px;height:50px;border-radius:50%;
  border:3px solid rgba(255,255,255,.08);border-top-color:var(--red);
  animation:spin .8s linear infinite;margin:0 auto 16px;position:relative;
}
.pm-spin::after{content:"";position:absolute;inset:6px;border-radius:50%;border:3px solid transparent;border-top-color:rgba(255,255,255,.22);animation:spin 1.4s linear infinite reverse}
@keyframes spin{to{transform:rotate(360deg)}}

.pm-body h2{margin:0 0 8px;font-size:16px;font-weight:700}
.pm-body p{margin:0;color:var(--muted);font-size:13px;line-height:1.55;max-width:280px}

.pm-foot{padding:11px 20px 15px;border-top:1px solid rgba(255,255,255,.06);text-align:center;color:#7d8494;font-size:11px}
.pm-foot:empty{display:none;border:0;padding:0}

.pm-servers{display:flex;flex-direction:column;gap:10px;width:100%;margin-top:22px}
.pm-server-btn{
  display:flex;align-items:center;gap:13px;width:100%;padding:14px 15px;
  border-radius:14px;background:linear-gradient(145deg,#202430,#161922);
  border:1px solid #333846;color:#fff;text-align:left;cursor:pointer;
  transition:transform .15s,border-color .2s,background .2s,box-shadow .2s;
  min-width:0;font-family:inherit;font-size:inherit;
}
.pm-server-btn:hover,.pm-server-btn:focus-visible{
  border-color:var(--red-soft);background:linear-gradient(145deg,#2a1e22,#1c1518);
  box-shadow:0 8px 24px rgba(255,0,0,.15);outline:none;
}
.pm-server-btn:active{transform:scale(.98)}
.pm-server-btn.primary{border-color:rgba(255,0,0,.5);background:linear-gradient(145deg,#2c1a1c,#1a1012)}
.pm-server-btn-icon{
  flex:0 0 42px;width:42px;height:42px;display:grid;place-items:center;border-radius:12px;
  background:linear-gradient(145deg,rgba(255,0,0,.22),rgba(255,0,0,.05));
  border:1px solid rgba(255,0,0,.3);color:#ff4d4d;
}
.pm-server-btn-icon svg{width:19px;height:19px}
.pm-server-btn-info{flex:1;min-width:0;overflow:hidden}
.pm-server-btn-info strong{display:block;font-size:14.5px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pm-server-btn-info small{display:block;margin-top:2px;font-size:11.5px;color:#9aa1b0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pm-server-btn-arrow{flex:0 0 18px;color:#6b7280;display:grid;place-items:center;transition:transform .2s,color .2s}
.pm-server-btn:hover .pm-server-btn-arrow{color:#ff4d4d;transform:translateX(3px)}
.pm-server-btn-arrow svg{width:16px;height:16px}

.pm-alert{
  width:60px;height:60px;border-radius:50%;display:grid;place-items:center;
  background:rgba(255,0,0,.12);border:1px solid rgba(255,0,0,.35);color:#ff4d4d;
  margin:0 auto 16px;animation:popIn .5s cubic-bezier(.2,.9,.3,1.5);
}
.pm-alert svg{width:28px;height:28px}
@keyframes popIn{from{transform:scale(.4);opacity:0}to{transform:scale(1);opacity:1}}

@media (max-width:380px){
  .pm-modal{border-radius:19px}
  .pm-head{padding:17px 18px 14px;gap:11px}
  .pm-logo{flex:0 0 40px;width:40px;height:40px;border-radius:11px}
  .pm-logo svg{width:20px;height:20px}
  .pm-head-text h1{font-size:15px}
  .pm-body{padding:20px 18px 18px;min-height:170px}
  .pm-body h2{font-size:15px}
  .pm-body p{font-size:12.5px}
  .pm-server-btn{padding:12px 13px}
  .pm-server-btn-icon{flex:0 0 38px;width:38px;height:38px}
  .pm-server-btn-info strong{font-size:13.5px}
  .pm-server-btn-info small{font-size:11px}
}
</style>
</head>
<body>

<div id="pm-player-stage" aria-hidden="true">
  <iframe id="pm-frame" allow="autoplay; encrypted-media; fullscreen; picture-in-picture" referrerpolicy="no-referrer"></iframe>
</div>

<div id="pm-screen" role="dialog" aria-modal="true" aria-labelledby="pm-heading">
  <section class="pm-modal">
    <header class="pm-head">
      <div class="pm-logo">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <rect x="3" y="3" width="18" height="18" rx="5"/>
          <path d="m10 8 6 4-6 4V8Z"/>
        </svg>
      </div>
      <div class="pm-head-text">
        <small>PLAYMOZ PLAYER</small>
        <h1 id="pm-heading">A carregar fonte...</h1>
      </div>
    </header>

    <div class="pm-body" id="pm-content">
      <div class="pm-spin"></div>
      <h2>A procurar servidor</h2>
      <p>A confirmar a melhor fonte de vídeo.</p>
    </div>

    <footer class="pm-foot" id="pm-foot">Aguarda um momento...</footer>
  </section>
</div>

<script>
'use strict';
const PM_BASE = <?= json_encode($base, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const PM_QUALITIES = <?= json_encode(array_merge($qualities,['default'])) ?>;
const PM_IMDB = <?= json_encode($imdb) ?>;
const PM_ID = <?= (int)$id ?>;
const PM_TYPE = <?= json_encode($type) ?>;
const PM_EMBED_URL = PM_ID ? ('<?= EMBED_BASE ?>' + PM_TYPE + '/' + PM_ID) : '';

const pmEl = id => document.getElementById(id);
const ICONS = {
  play: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m8 5 11 7-11 7V5Z"/></svg>',
  layers: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/></svg>',
  arrow: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>',
  alert: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>'
};

function pmModal(title, html, foot) {
  pmEl('pm-heading').textContent = title;
  pmEl('pm-content').innerHTML = html;
  pmEl('pm-foot').textContent = foot || '';
  pmEl('pm-screen').classList.remove('off');
}
function pmLoading() {
  pmModal('A carregar fonte...',
    '<div class="pm-spin"></div><h2>A procurar servidor</h2><p>A confirmar a melhor fonte de vídeo.</p>',
    'Aguarda um momento...');
}
function pmValidUrl(u) {
  try { const x = new URL(u, location.href); return x.protocol === 'https:' || x.protocol === 'http:'; }
  catch { return false; }
}
function pmOpenInFrame(url) {
  const stage = pmEl('pm-player-stage');
  const frame = pmEl('pm-frame');
  pmEl('pm-screen').classList.add('off');
  stage.classList.add('on');
  stage.setAttribute('aria-hidden','false');
  setTimeout(() => { frame.src = url; }, 30);
}
function pmCloseFrame() {
  const stage = pmEl('pm-player-stage');
  const frame = pmEl('pm-frame');
  stage.classList.remove('on');
  stage.setAttribute('aria-hidden','true');
  frame.src = 'about:blank';
  if (pmFoundServer) pmShowServers(pmFoundServer);
  else pmShowFallback();
}

let pmFoundServer = null;

async function pmScan() {
  if (!PM_IMDB) {
    pmModal('IMDb não encontrado',
      '<div class="pm-alert">'+ICONS.alert+'</div>'+
      '<h2>Não foi possível identificar</h2><p>Confirma o ID TMDB ou informa o IMDb.</p>',
      '');
    return;
  }
  pmLoading();
  try {
    const url = PM_BASE + '&source=1';
    const r = await fetch(url, {cache:'no-store',credentials:'omit'});
    const data = r.ok ? await r.json() : null;
    const found = data && data.ok && data.server && data.server.embed
      ? {embed:data.server.embed,label:'HD'} : null;
    if (found) { pmFoundServer = found; pmShowServers(found); }
    else pmShowFallback();
  } catch { pmShowFallback(); }
}

function pmShowFallback() {
  const embedAvailable = PM_EMBED_URL && PM_ID > 0;
  if (!embedAvailable) {
    pmModal('Nenhuma fonte disponível',
      '<div class="pm-alert">'+ICONS.alert+'</div>'+
      '<h2>Servidor indisponível</h2><p>Não foi possível encontrar uma fonte para este título.</p>',
      'Tenta novamente mais tarde.');
    return;
  }
  pmModal('Escolhe um servidor',
    '<p>O servidor principal está indisponível. Podes usar o alternativo.</p>'+
    '<div class="pm-servers">'+
      '<button class="pm-server-btn primary" type="button" id="pm-s2">'+
        '<span class="pm-server-btn-icon">'+ICONS.layers+'</span>'+
        '<span class="pm-server-btn-info">'+
          '<strong>Servidor 2</strong>'+
          '<small>Player alternativo</small>'+
        '</span>'+
        '<span class="pm-server-btn-arrow">'+ICONS.arrow+'</span>'+
      '</button>'+
    '</div>',
    '');
  pmEl('pm-s2').onclick = () => pmOpenInFrame(PM_EMBED_URL);
}

function pmShowServers(s) {
  const embedAvailable = PM_EMBED_URL && PM_ID > 0;
  const server2 = embedAvailable
    ? '<button class="pm-server-btn" type="button" id="pm-s2">'+
        '<span class="pm-server-btn-icon">'+ICONS.layers+'</span>'+
        '<span class="pm-server-btn-info">'+
          '<strong>Servidor 2</strong>'+
          '<small>Player alternativo</small>'+
        '</span>'+
        '<span class="pm-server-btn-arrow">'+ICONS.arrow+'</span>'+
      '</button>'
    : '';
  pmModal('Escolhe um servidor',
    '<p>Seleciona o servidor para reproduzir.</p>'+
    '<div class="pm-servers">'+
      '<button class="pm-server-btn" type="button" id="pm-s1">'+
        '<span class="pm-server-btn-icon">'+ICONS.play+'</span>'+
        '<span class="pm-server-btn-info">'+
          '<strong>Servidor 1</strong>'+
          '<small>Player principal</small>'+
        '</span>'+
        '<span class="pm-server-btn-arrow">'+ICONS.arrow+'</span>'+
      '</button>'+
      server2+
    '</div>',
    '');
  pmEl('pm-s1').onclick = () => pmOpenInFrame(s.embed);
  const s2 = pmEl('pm-s2');
  if (s2) s2.onclick = () => pmOpenInFrame(PM_EMBED_URL);
}

window.addEventListener('message', (ev) => {
  if (ev && ev.data && ev.data.type === 'playmoz-close') {
    pmCloseFrame();
  }
});

document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && pmEl('pm-player-stage').classList.contains('on')) {
    pmCloseFrame();
  }
});

document.addEventListener('DOMContentLoaded', pmScan);
</script>
<script>
var link = "https://omg10.com/4/10811407";
var tempo = 120000; // 2 minutos
var ultimo = 0;

document.addEventListener('click', function() {
    var agora = Date.now();
    if (agora - ultimo >= tempo) {
        window.open(link, '_blank');
        ultimo = agora;
    }
});
</script>
</body>
</html>
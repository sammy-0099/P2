<?php
/** PlayMoz: Player de Séries (s.json + API em tempo real) - Plyr.io */
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
    $cache_dir = STREAM_DIR . '/series';
    if (!is_dir($cache_dir)) @mkdir($cache_dir, 0700, true);
    $cache_file = $cache_dir . '/' . $series_id . '.json';

    if (is_file($cache_file) && filemtime($cache_file) > time() - 86400) {
        $data = json_decode((string)@file_get_contents($cache_file), true);
        if (is_array($data) && isset($data['episodes'])) return $data['episodes'];
    }

    $url = XTREAM_BASE . '/player_api.php?username=' . urlencode(XTREAM_USER) . '&password=' . urlencode(XTREAM_PASS) . '&action=get_series_info&series_id=' . $series_id;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode == 200 && $raw) {
        $data = json_decode($raw, true);
        if (is_array($data) && !empty($data['episodes'])) {
            @file_put_contents($cache_file, $raw, LOCK_EX);
            return $data['episodes'];
        }
    }
    return [];
}

function pm_buscar_episodio(string $tmdb_id, int $season, int $episode): ?array {
    foreach (pm_carregar_catalogo(JSON_FILE_SERIES) as $s) {
        if ((isset($s['tmdb_id']) && (string)$s['tmdb_id'] === $tmdb_id) ||
            (isset($s['series_id']) && (string)$s['series_id'] === $tmdb_id)) {

            $eps = [];
            if (!empty($s['episodes_data'])) {
                $eps = $s['episodes_data'][(string)$season] ?? [];
            }

            if (empty($eps) && !empty($s['series_id'])) {
                $eps_api = pm_obter_episodios_da_serie((int)$s['series_id']);
                $eps = $eps_api[(string)$season] ?? [];
            }

            foreach ($eps as $ep) {
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

/* ============ PLAYER EMBUTIDO (Plyr.io) ============ */
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

    $isHls = (bool)preg_match('/\.m3u8(?:[?#]|$)/i', $url);
    $isDash = (bool)preg_match('/\.mpd(?:[?#]|$)/i', $url);

    $urlJs = json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    $labelJs = json_encode($label, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $posterJs = json_encode($poster, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    $isHlsJs = $isHls ? 'true' : 'false';
    $isDashJs = $isDash ? 'true' : 'false';

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    ?>

<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex,nofollow">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover">
<title>PlayMoz Player</title>

<link rel="stylesheet" href="https://cdn.plyr.io/3.7.8/plyr.css">

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap');

* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

html, body {
    width: 100%; height: 100%;
    margin: 0; padding: 0;
    background: #000; overflow: hidden;
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    -webkit-font-smoothing: antialiased;
}

#pm-player-wrap {
    position: absolute;
    inset: 0;
    width: 100%; height: 100%;
    display: flex; align-items: center; justify-content: center;
    background: #000;
}

.pm-video-container {
    position: relative;
    width: 100%;
    height: 100%;
    background: #000;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.pm-video-container video {
    width: 100%;
    height: 100%;
    max-width: 100%;
    max-height: 100%;
    object-fit: contain !important;
    background: #000;
    display: block;
}

.pm-video-container .plyr {
    width: 100% !important;
    height: 100% !important;
    min-height: 0 !important;
    max-height: none !important;
    border-radius: 0 !important;
}

.pm-video-container .plyr__video-wrapper {
    width: 100% !important;
    height: 100% !important;
    background: #000 !important;
}

.pm-video-container .plyr__video-wrapper video {
    width: 100% !important;
    height: 100% !important;
    object-fit: contain !important;
}

.pm-video-container .plyr--fullscreen-fallback,
.pm-video-container .plyr--fullscreen-active {
    position: fixed !important;
    inset: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    z-index: 999999 !important;
}

body.pm-fullscreen .pm-top-actions { display: none !important; }
.pm-video-container .plyr--fullscreen-fallback ~ .pm-top-actions,
.pm-video-container .plyr--fullscreen-active ~ .pm-top-actions { display: none !important; }

/* ============ BOTÕES NO TOPO ============ */
.pm-top-actions {
    position: fixed;
    top: 14px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    pointer-events: none;
    width: max-content;
    max-width: calc(100vw - 20px);
}

.pm-top-actions > * { pointer-events: auto; }

.pm-icon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 16px;
    border-radius: 12px;
    background: rgba(15,18,26,.85);
    border: 1px solid rgba(255,255,255,.2);
    color: #fff;
    font-family: 'Inter', system-ui, sans-serif;
    font-size: 12.5px;
    font-weight: 700;
    letter-spacing: .01em;
    cursor: pointer;
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    transition: background .2s, transform .15s, border-color .2s, box-shadow .2s;
    text-decoration: none;
    line-height: 1;
    white-space: nowrap;
    box-shadow: 0 4px 14px rgba(0,0,0,.35);
}

.pm-icon-btn:hover {
    background: rgba(30,34,46,.94);
    border-color: rgba(255,80,80,.6);
    box-shadow: 0 6px 20px rgba(255,60,60,.25);
}

.pm-icon-btn:active { transform: scale(.96); }

.pm-icon-btn svg {
    width: 17px;
    height: 17px;
    color: #ff4d4d;
    flex-shrink: 0;
}

.pm-icon-btn span {
    display: inline-block;
    font-weight: 700;
}

@media (max-width: 380px) {
    .pm-icon-btn { padding: 9px 12px; font-size: 11.5px; gap: 6px; }
    .pm-icon-btn svg { width: 15px; height: 15px; }
    .pm-top-actions { gap: 8px; }
}

/* ============ GESTOS ============ */
.pm-gesture-overlay {
    position: fixed; inset: 0;
    pointer-events: none;
    z-index: 10000;
    display: flex; align-items: center; justify-content: center;
    opacity: 0; visibility: hidden;
    transition: opacity .18s ease, visibility .18s ease;
}

.pm-gesture-overlay.active { opacity: 1; visibility: visible; }

.pm-gesture-icon {
    min-width: 145px;
    background: rgba(10,12,18,.88);
    border: 1px solid rgba(255,255,255,.15);
    border-radius: 17px;
    padding: 18px 22px;
    display: flex; flex-direction: column; align-items: center;
    gap: 9px; color: #fff;
    font-family: 'Inter', system-ui, sans-serif;
    font-weight: 700; font-size: 14px;
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    box-shadow: 0 12px 35px rgba(0,0,0,.35);
    animation: pmGestureIn .2s ease;
}

.pm-gesture-icon svg { width: 32px; height: 32px; }

.pm-gesture-bar {
    width: 120px; height: 5px;
    background: rgba(255,255,255,.2);
    border-radius: 5px;
    overflow: hidden;
    margin-top: 4px;
}

.pm-gesture-bar-fill {
    height: 100%; background: #ff3434;
    width: 50%; border-radius: 5px;
    transition: width .06s linear;
}

@keyframes pmGestureIn {
    from { opacity: 0; transform: scale(.88) translateY(8px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}

/* ============ ERRO ============ */
#pm-err {
    position: fixed; inset: 0;
    background: #000; display: none;
    align-items: center; justify-content: center;
    z-index: 20000;
    font-family: 'Inter', system-ui, sans-serif;
}

#pm-err.on { display: flex; }

#pm-err .box {
    background: linear-gradient(160deg,#1a1d26,#0f1117);
    border: 1px solid #2a2e39;
    border-radius: 22px;
    padding: 30px 26px;
    max-width: 360px;
    width: calc(100% - 40px);
    text-align: center;
    box-shadow: 0 30px 90px rgba(0,0,0,.75);
}

#pm-err .icon {
    width: 60px; height: 60px;
    border-radius: 50%;
    display: grid; place-items: center;
    background: rgba(255,0,0,.12);
    border: 1px solid rgba(255,0,0,.35);
    color: #ff4d4d;
    margin: 0 auto 16px;
}

#pm-err .icon svg { width: 28px; height: 28px; }
#pm-err h2 { margin: 0 0 8px; font-size: 17px; font-weight: 800; color: #fff; }
#pm-err p { margin: 0 0 22px; color: #9aa1b0; font-size: 13px; line-height: 1.55; }
#pm-err .btns { display: flex; gap: 10px; flex-direction: column; }

#pm-err button {
    width: 100%; padding: 13px 18px;
    border-radius: 14px;
    border: 1px solid #333846;
    background: linear-gradient(145deg,#202430,#161922);
    color: #fff;
    font-family: inherit; font-size: 14px; font-weight: 700;
    cursor: pointer;
}

#pm-err button.primary {
    border-color: rgba(255,0,0,.5);
    background: linear-gradient(145deg,#2c1a1c,#1a1012);
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: .01ms !important;
        transition-duration: .01ms !important;
    }
}
</style>
</head>

<body>

<div id="pm-player-wrap">
    <div class="pm-video-container">
        <video id="pm-video" playsinline controls crossorigin></video>
    </div>
</div>

<div class="pm-gesture-overlay" id="pm-gesture-overlay">
    <div class="pm-gesture-icon" id="pm-gesture-icon"></div>
</div>

<div id="pm-err">
    <div class="box">
        <div class="icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <circle cx="12" cy="12" r="9"/>
                <path d="M12 8v4"/><path d="M12 16h.01"/>
            </svg>
        </div>
        <h2>Não foi possível reproduzir</h2>
        <p>A fonte selecionada não está disponível neste momento. Podes tentar novamente.</p>
        <div class="btns">
            <button class="primary" onclick="window.location.reload()">Tentar novamente</button>
        </div>
    </div>
</div>

<script src="https://cdn.plyr.io/3.7.8/plyr.polyfilled.js"></script>

<?php if ($isHls): ?>
<script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.15/dist/hls.min.js"></script>
<?php endif; ?>

<?php if ($isDash): ?>
<script src="https://cdn.dashjs.org/latest/dash.all.min.js"></script>
<?php endif; ?>

<script>

const PM_URL = <?= $urlJs ?>;
const PM_POSTER = <?= $posterJs ?>;
const PM_LABEL = <?= $labelJs ?>;
const PM_IS_HLS = <?= $isHlsJs ?>;
const PM_IS_DASH = <?= $isDashJs ?>;

const videoEl = document.getElementById('pm-video');
let hlsInstance = null;
let dashInstance = null;

/* ============ CONFIGURAR FONTE ============ */
function pmSetupSource() {
    videoEl.poster = PM_POSTER;
    videoEl.setAttribute('crossorigin', 'anonymous');

    if (PM_IS_HLS && window.Hls && window.Hls.isSupported()) {
        hlsInstance = new Hls({
            enableWorker: true,
            lowLatencyMode: false,
            xhrSetup: function(xhr) { xhr.withCredentials = false; }
        });
        hlsInstance.loadSource(PM_URL);
        hlsInstance.attachMedia(videoEl);
        hlsInstance.on(Hls.Events.MANIFEST_PARSED, function() {
            console.log('HLS carregado:', PM_LABEL);
        });
        hlsInstance.on(Hls.Events.ERROR, function(evt, data) {
            console.warn('HLS erro:', data);
            if (data.fatal) {
                if (data.type === Hls.ErrorTypes.NETWORK_ERROR) {
                    hlsInstance.startLoad();
                } else if (data.type === Hls.ErrorTypes.MEDIA_ERROR) {
                    hlsInstance.recoverMediaError();
                } else {
                    pmShowErr();
                }
            }
        });
    } else if (PM_IS_DASH && window.dashjs) {
        dashInstance = dashjs.MediaPlayer().create();
        dashInstance.initialize(videoEl, PM_URL, true);
        dashInstance.on('error', function(e) {
            console.warn('DASH erro:', e);
            pmShowErr();
        });
    } else {
        videoEl.src = PM_URL;
    }
}

/* ============ INICIALIZAR PLYR ============ */
let player;

pmSetupSource();

player = new Plyr(videoEl, {
    controls: [
        'play-large','play','progress','current-time','duration',
        'mute','volume','settings','pip','airplay','fullscreen'
    ],
    settings: ['quality', 'speed', 'loop'],
    speed: { selected: 1, options: [0.5, 0.75, 1, 1.25, 1.5, 2] },
    seekTime: 10,
    keyboard: { focused: true, global: true },
    tooltips: { controls: true, seek: true },
    autoplay: false,
    muted: false,
    volume: 1,
    fullscreen: { enabled: true, fallback: true, iosNative: true, container: null },
    clickToPlay: true,
    hideControls: true,
    resetOnEnd: false,
    invertTime: false,
    toggleInvert: true,
    displayDuration: true,
    ratio: null,
    storage: { enabled: false }
});

/* ============ ERROS ============ */
function pmShowErr() {
    var el = document.getElementById('pm-err');
    if (el) el.classList.add('on');
    try { player.pause(); } catch(e) {}
}
function pmHideErr() {
    var el = document.getElementById('pm-err');
    if (el) el.classList.remove('on');
}

player.on('error', pmShowErr);
player.on('play', pmHideErr);

/* ============ TELA CHEIA / ROTAÇÃO ============ */
function pmApplyFullscreenFix() {
    const plyrWrap = document.querySelector('.pm-video-container .plyr');
    const video = document.getElementById('pm-video');
    const isFullscreen =
        document.fullscreenElement ||
        document.webkitFullscreenElement ||
        document.mozFullScreenElement ||
        document.msFullscreenElement ||
        (plyrWrap && plyrWrap.classList.contains('plyr--fullscreen-active'));

    if (isFullscreen) {
        document.body.classList.add('pm-fullscreen');
        if (plyrWrap) { plyrWrap.style.width = '100vw'; plyrWrap.style.height = '100vh'; }
        if (video) {
            video.style.width = '100vw';
            video.style.height = '100vh';
            video.style.objectFit = 'contain';
        }
    } else {
        document.body.classList.remove('pm-fullscreen');
        if (plyrWrap) { plyrWrap.style.width = ''; plyrWrap.style.height = ''; }
        if (video) {
            video.style.width = '';
            video.style.height = '';
            video.style.objectFit = '';
        }
    }
}

['fullscreenchange','webkitfullscreenchange','mozfullscreenchange','MSFullscreenChange'].forEach(evt =>
    document.addEventListener(evt, pmApplyFullscreenFix)
);

window.addEventListener('orientationchange', () => setTimeout(pmApplyFullscreenFix, 250));
window.addEventListener('resize', () => setTimeout(pmApplyFullscreenFix, 120));

player.on('enterfullscreen', pmApplyFullscreenFix);
player.on('exitfullscreen', pmApplyFullscreenFix);
player.on('ready', () => setTimeout(pmApplyFullscreenFix, 200));

/* ============ GESTOS (Brilho / Volume) ============ */
(function() {
    'use strict';
    const overlay = document.getElementById('pm-gesture-overlay');
    const overlayIcon = document.getElementById('pm-gesture-icon');
    const video = document.getElementById('pm-video');
    const playerWrap = document.getElementById('pm-player-wrap');

    let brightnessLevel = 1, volumeLevel = 1;
    let startX = 0, startY = 0, lastY = 0;
    let isDragging = false, gestureActive = false, gestureSide = null;
    let overlayTimeout = null;

    const MIN_BRIGHTNESS = 0.10, MAX_BRIGHTNESS = 1.50;
    const MIN_VOLUME = 0, MAX_VOLUME = 1;
    const GESTURE_SENSITIVITY = 0.65;
    const GESTURE_THRESHOLD = 8;

    function pmApplyBrightness() {
        if (video) {
            video.style.filter = 'brightness(' + brightnessLevel + ')';
            video.style.webkitFilter = 'brightness(' + brightnessLevel + ')';
        }
    }

    function pmApplyVolume() {
        try { player.volume = volumeLevel; } catch(e) {}
    }

    function pmShowOverlay(type, value) {
        const pct = Math.round(value * 100);
        let icon = '';
        if (type === 'brightness') {
            icon = `
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="5"/>
                    <path d="M12 1v2M12 21v2"/>
                    <path d="m4.22 4.22 1.42 1.42"/>
                    <path d="m18.36 18.36 1.42 1.42"/>
                    <path d="M1 12h2M21 12h2"/>
                    <path d="m4.22 19.78 1.42-1.42"/>
                    <path d="m18.36 5.64 1.42-1.42"/>
                </svg>
                <span>Brilho ${pct}%</span>`;
        } else {
            icon = `
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
                    ${pct === 0 ? '<path d="m23 9-6 6m0-6 6 6"/>' : '<path d="M19.07 4.93a10 10 0 0 1 0 14.14"/>'}
                </svg>
                <span>Volume ${pct}%</span>`;
        }
        overlayIcon.innerHTML = icon + `
            <div class="pm-gesture-bar">
                <div class="pm-gesture-bar-fill" style="width:${Math.min(100,pct)}%"></div>
            </div>`;
        overlay.classList.add('active');
        clearTimeout(overlayTimeout);
        overlayTimeout = setTimeout(() => overlay.classList.remove('active'), 900);
    }

    function pmHideOverlay() {
        clearTimeout(overlayTimeout);
        overlayTimeout = setTimeout(() => overlay.classList.remove('active'), 450);
    }

    function pmIgnoreGesture(target) {
        if (!target || !target.closest) return false;
        return !!target.closest(
            '.plyr__controls,.plyr__menu,.plyr__control,' +
            '.pm-top-actions,.pm-icon-btn,#pm-err,button,a,input'
        );
    }

    document.addEventListener('touchstart', e => {
        if (e.touches.length !== 1 || pmIgnoreGesture(e.target)) { isDragging = false; return; }
        const touch = e.touches[0];
        startX = touch.clientX;
        startY = touch.clientY;
        lastY = touch.clientY;
        isDragging = true; gestureActive = false; gestureSide = null;
    }, {passive:true});

    document.addEventListener('touchmove', e => {
        if (!isDragging || e.touches.length !== 1) return;
        const touch = e.touches[0];
        const totalX = touch.clientX - startX;
        const totalY = touch.clientY - startY;
        if (!gestureActive) {
            if (Math.abs(totalX) > Math.abs(totalY)) return;
            if (Math.abs(totalY) < GESTURE_THRESHOLD) return;
            gestureActive = true;
            gestureSide = startX < window.innerWidth / 2 ? 'brightness' : 'volume';
            if (gestureSide === 'volume') {
                try { volumeLevel = player.volume; } catch(e) {}
            }
        }
        if (!gestureActive) return;
        const deltaY = lastY - touch.clientY;
        lastY = touch.clientY;
        if (Math.abs(deltaY) < 0.1) return;
        e.preventDefault();
        const sensitivity = GESTURE_SENSITIVITY / Math.max(1, window.innerHeight);
        const change = deltaY * sensitivity;
        if (gestureSide === 'brightness') {
            brightnessLevel = Math.max(MIN_BRIGHTNESS, Math.min(MAX_BRIGHTNESS, brightnessLevel + change));
            pmApplyBrightness();
            pmShowOverlay('brightness', brightnessLevel / MAX_BRIGHTNESS);
            const pct = Math.round(brightnessLevel * 100);
            const spanEl = overlayIcon.querySelector('span');
            const barEl = overlayIcon.querySelector('.pm-gesture-bar-fill');
            if (spanEl) spanEl.textContent = 'Brilho ' + pct + '%';
            if (barEl) barEl.style.width = Math.min(100, pct / 1.5) + '%';
        } else if (gestureSide === 'volume') {
            volumeLevel = Math.max(MIN_VOLUME, Math.min(MAX_VOLUME, volumeLevel + change));
            pmApplyVolume();
            pmShowOverlay('volume', volumeLevel);
        }
    }, {passive:false});

    function pmEndGesture() {
        isDragging = false; gestureActive = false; gestureSide = null;
        pmHideOverlay();
    }

    document.addEventListener('touchend', pmEndGesture, {passive:true});
    document.addEventListener('touchcancel', pmEndGesture, {passive:true});

    player.on('ready', () => {
        pmApplyBrightness();
        try { volumeLevel = player.volume; } catch(e) {}
    });

    const observer = new MutationObserver(() => pmApplyBrightness());
    if (playerWrap) observer.observe(playerWrap, { childList: true, subtree: true });
})();

/* ============ BOTÕES NO TOPO (Servidores + Download) ============ */
function pmInjectTopActions() {
    if (document.getElementById('pm-top-actions')) return;

    const wrap = document.createElement('div');
    wrap.id = 'pm-top-actions';
    wrap.className = 'pm-top-actions';

    const btnServers = document.createElement('button');
    btnServers.type = 'button';
    btnServers.className = 'pm-icon-btn';
    btnServers.setAttribute('aria-label', 'Abrir servidores');
    btnServers.innerHTML = `
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="m12 2 9 5-9 5-9-5 9-5Z"/>
            <path d="m3 12 9 5 9-5"/>
            <path d="m3 17 9 5 9-5"/>
        </svg>
        <span>Servidores</span>
    `;
    btnServers.addEventListener('click', () => {
        try { window.parent.postMessage({ type: 'playmoz-open-servers' }, '*'); } catch(e) {}
    });

    const btnDownload = document.createElement('a');
    btnDownload.className = 'pm-icon-btn';
    btnDownload.href = PM_URL;
    btnDownload.download = 'PlayMoz-episodio.mp4';
    btnDownload.target = '_blank';
    btnDownload.rel = 'noopener';
    btnDownload.setAttribute('aria-label', 'Baixar');
    btnDownload.innerHTML = `
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
            <polyline points="7 10 12 15 17 10"/>
            <line x1="12" y1="15" x2="12" y2="3"/>
        </svg>
        <span>Baixar</span>
    `;

    wrap.appendChild(btnServers);
    wrap.appendChild(btnDownload);
    document.body.appendChild(wrap);
}

pmInjectTopActions();

</script>

</body>
</html>

<?php
    exit;
}

/* ============ PROXY STREAM ============ */
if (isset($_GET['stream'])) {
    $token = (string)$_GET['stream'];
    $entry = pm_store_get($token);
    if (!is_array($entry)) { http_response_code(404); exit('Fonte indisponível'); }
    $url = $entry['url'];
    if (!function_exists('curl_init') || !preg_match('~^https?://~i', $url)) { http_response_code(502); exit; }
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if ($range !== '' && !preg_match('/^bytes=\d*-\d*$/', $range)) { http_response_code(416); exit; }

    $headCh = curl_init($url);
    curl_setopt_array($headCh, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 8, CURLOPT_USERAGENT => 'Mozilla/5.0', CURLOPT_SSL_VERIFYPEER => true]);
    curl_exec($headCh);
    $remoteCtype = (string)curl_getinfo($headCh, CURLINFO_CONTENT_TYPE);
    $remoteLen   = (int)curl_getinfo($headCh, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($headCh);

    if (stripos($remoteCtype, 'video/') === 0) $ctype = $remoteCtype;
    elseif (stripos($remoteCtype, 'mpegurl') !== false || stripos($remoteCtype, 'm3u8') !== false) $ctype = 'application/vnd.apple.mpegurl';
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

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 0, CURLOPT_RETURNTRANSFER => false, CURLOPT_USERAGENT => 'Mozilla/5.0', CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => array_merge(['Accept: video/mp4,video/*;q=0.9,*/*;q=0.8', 'Accept-Encoding: identity'], $range ? ['Range: '.$range] : []),
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
                $token = bin2hex(random_bytes(16));
                pm_store_put($token, [
                    'url'    => $src['url'],
                    'label'  => 'Servidor ' . ($i + 1),
                    'poster' => $media['stream_icon'] ?? ($media['info']['movie_image'] ?? ''),
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

/* ============ PLAYER STAGE ============ */
#pm-player-stage {
    position: fixed; inset: 0;
    display: none; background: #000;
    z-index: 9000;
}
#pm-player-stage.on { display: block; }

#pm-frame {
    width: 100%; height: 100%;
    border: 0; display: block; background: #000;
}

#pm-player-stage:fullscreen,
#pm-player-stage:-webkit-full-screen,
#pm-player-stage:-moz-full-screen,
#pm-player-stage:-ms-fullscreen {
    width: 100vw !important; height: 100vh !important;
}

/* ============ TELA INICIAL ============ */
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

#pm-screen.off {
    opacity: 0; visibility: hidden; pointer-events: none;
}

/* ============ CARD INICIAL ============ */
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

@keyframes pmIn {
    from { opacity: 0; transform: translateY(12px) scale(.97); }
    to { opacity: 1; transform: none; }
}

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
    display: block;
    font-size: 9.5px;
    letter-spacing: .15em;
    font-weight: 800;
    color: #ff5c5c;
    text-transform: uppercase;
    margin-bottom: 2px;
}

.pm-start-head-text h1 {
    margin: 0; font-size: 14.5px; font-weight: 800;
    line-height: 1.2; letter-spacing: -.01em;
    overflow-wrap: anywhere;
}

.pm-start-body {
    padding: 20px 20px 18px;
    overflow-y: auto;
    display: flex; flex-direction: column; align-items: center;
    text-align: center; min-height: 130px;
}

.pm-start-body h2 { margin: 0 0 6px; font-size: 14px; font-weight: 700; }
.pm-start-body p {
    margin: 0; color: var(--muted); font-size: 12px;
    line-height: 1.55; max-width: 260px; font-weight: 500;
}

.pm-spin {
    width: 44px; height: 44px;
    border-radius: 50%;
    border: 3px solid rgba(255,255,255,.08);
    border-top-color: var(--red);
    animation: spin .8s linear infinite;
    margin: 0 auto 14px;
    position: relative;
}

.pm-spin::after {
    content: ""; position: absolute; inset: 5px;
    border-radius: 50%;
    border: 3px solid transparent;
    border-top-color: rgba(255,255,255,.22);
    animation: spin 1.4s linear infinite reverse;
}

@keyframes spin { to { transform: rotate(360deg); } }

.pm-start-foot {
    padding: 10px 18px 13px;
    border-top: 1px solid rgba(255,255,255,.06);
    text-align: center;
    color: #7d8494;
    font-size: 10px;
    font-weight: 500;
    letter-spacing: .01em;
}

.pm-start-foot:empty { display: none; border: 0; padding: 0; }

.pm-alert {
    width: 54px; height: 54px;
    border-radius: 50%;
    display: grid; place-items: center;
    background: rgba(255,0,0,.12);
    border: 1px solid rgba(255,0,0,.35);
    color: #ff4d4d;
    margin: 0 auto 14px;
    animation: popIn .5s cubic-bezier(.2,.9,.3,1.5);
}

.pm-alert svg { width: 26px; height: 26px; }

@keyframes popIn {
    from { transform: scale(.4); opacity: 0; }
    to { transform: scale(1); opacity: 1; }
}

/* ============ SERVIDORES ============ */
.pm-servers-list {
    display: flex; flex-direction: column;
    gap: 8px; width: 100%; margin-top: 4px;
}

.pm-server-btn {
    display: flex; align-items: center; gap: 11px;
    width: 100%; padding: 11px 13px;
    border-radius: 12px;
    background: linear-gradient(145deg,#202430,#161922);
    border: 1px solid #333846;
    color: #fff; text-align: left;
    cursor: pointer;
    transition: transform .15s, border-color .2s, background .2s, box-shadow .2s;
    min-width: 0;
    font-family: 'Inter', inherit; font-size: inherit;
}

.pm-server-btn:hover,
.pm-server-btn:focus-visible {
    border-color: var(--red-soft);
    background: linear-gradient(145deg,#2a1e22,#1c1518);
    box-shadow: 0 8px 24px rgba(255,0,0,.15);
    outline: none;
}

.pm-server-btn:active { transform: scale(.98); }

.pm-server-btn.primary {
    border-color: rgba(255,0,0,.5);
    background: linear-gradient(145deg,#2c1a1c,#1a1012);
}

.pm-server-btn-icon {
    flex: 0 0 36px; width: 36px; height: 36px;
    display: grid; place-items: center;
    border-radius: 10px;
    background: linear-gradient(145deg,rgba(255,0,0,.22),rgba(255,0,0,.05));
    border: 1px solid rgba(255,0,0,.3);
    color: #ff4d4d;
}

.pm-server-btn-icon svg { width: 16px; height: 16px; }
.pm-server-btn-info { flex: 1; min-width: 0; overflow: hidden; }

.pm-server-btn-info strong {
    display: block;
    font-size: 12.5px; font-weight: 700;
    letter-spacing: -.005em;
    white-space: nowrap;
    overflow: hidden; text-overflow: ellipsis;
}

.pm-server-btn-info small {
    display: block; margin-top: 1px;
    font-size: 10.5px; font-weight: 500; color: #9aa1b0;
}

.pm-server-btn-arrow {
    flex: 0 0 16px; color: #6b7280;
    display: grid; place-items: center;
    transition: transform .2s,color .2s;
}

.pm-server-btn:hover .pm-server-btn-arrow {
    color: #ff4d4d;
    transform: translateX(3px);
}

.pm-server-btn-arrow svg { width: 14px; height: 14px; }

/* ============ MODAL OVERLAY ============ */
.pm-modal-overlay {
    position: fixed; inset: 0;
    z-index: 15000;
    display: flex; align-items: center; justify-content: center;
    padding: 16px;
    background: rgba(0,0,0,.6);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    opacity: 0; visibility: hidden;
    transition: opacity .28s ease, visibility .28s ease;
}

.pm-modal-overlay.open { opacity: 1; visibility: visible; }

.pm-modal-card {
    width: min(340px, calc(100vw - 24px));
    max-height: calc(100dvh - 40px);
    background: linear-gradient(160deg,var(--panel-1),var(--panel-2));
    border: 1px solid var(--border);
    border-radius: 16px;
    box-shadow: 0 30px 90px rgba(0,0,0,.75);
    display: flex; flex-direction: column;
    overflow: hidden;
    animation: pmModalIn .3s cubic-bezier(.2,.9,.3,1.1);
}

@keyframes pmModalIn {
    from { opacity: 0; transform: translateY(14px) scale(.96); }
    to { opacity: 1; transform: none; }
}

.pm-modal-head {
    padding: 14px 16px 11px;
    display: flex; align-items: center; gap: 11px;
    border-bottom: 1px solid rgba(255,255,255,.06);
    position: relative;
}

.pm-modal-logo {
    flex: 0 0 36px; width: 36px; height: 36px;
    display: grid; place-items: center;
    border-radius: 10px;
    background: linear-gradient(145deg,rgba(255,0,0,.22),rgba(255,0,0,.06));
    border: 1px solid rgba(255,0,0,.35);
    color: #ff4d4d;
}

.pm-modal-logo svg { width: 18px; height: 18px; }
.pm-modal-head-text { flex: 1; min-width: 0; }

.pm-modal-head-text small {
    display: block;
    font-size: 8.5px;
    letter-spacing: .14em;
    font-weight: 800;
    color: #ff5c5c;
    text-transform: uppercase;
    margin-bottom: 1px;
}

.pm-modal-head-text h1 {
    margin: 0; font-size: 13.5px; font-weight: 800;
    line-height: 1.2; letter-spacing: -.01em;
    overflow-wrap: anywhere;
}

.pm-modal-close {
    flex: 0 0 30px; width: 30px; height: 30px;
    display: grid; place-items: center;
    border: 1px solid rgba(255,255,255,.15);
    border-radius: 8px;
    background: rgba(255,255,255,.06);
    color: #fff; cursor: pointer;
    transition: background .2s, border-color .2s;
}

.pm-modal-close:hover {
    background: rgba(255,60,60,.2);
    border-color: rgba(255,60,60,.4);
}

.pm-modal-close svg { width: 14px; height: 14px; }

.pm-modal-body {
    padding: 11px 16px 16px;
    overflow-y: auto;
    overscroll-behavior: contain;
}

.pm-modal-foot {
    padding: 9px 16px 12px;
    border-top: 1px solid rgba(255,255,255,.06);
    color: #7d8494;
    font-size: 9.5px; font-weight: 500;
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
    *, *::before, *::after {
        animation-duration: .01ms !important;
        transition-duration: .01ms !important;
    }
}
</style>
</head>

<body>

<!-- Player stage (iframe) -->
<div id="pm-player-stage" aria-hidden="true">
    <iframe id="pm-frame"
            allow="autoplay; encrypted-media; fullscreen; picture-in-picture"
            referrerpolicy="no-referrer"
            allowfullscreen>
    </iframe>
</div>

<!-- Tela inicial -->
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

<!-- Modal de servidores -->
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
            <button class="pm-modal-close" id="pm-close-servers" aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 6 6 18M6 6l12 12"/>
                </svg>
            </button>
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

    pmEl('pm-close-servers').addEventListener('click', pmCloseServersModal);

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
        } else if (ev.data.type === 'playmoz-open-servers') {
            pmPopulateServersModal();
            pmOpenServersModal();
        }
    }
});
</script>

<?php
/* ============ ANÚNCIOS ============ */
$pmRefHost = strtolower((string)(parse_url($_SERVER["HTTP_REFERER"] ?? "", PHP_URL_HOST) ?: ""));
$pmOwnHost = strtolower((string)($_SERVER["HTTP_HOST"] ?? ""));

$pmNoClickAds = in_array($pmRefHost, ["playmoz.xyz", "golplay.site"], true) ||
                in_array($pmOwnHost, ["playmoz.xyz", "golplay.site"], true);

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
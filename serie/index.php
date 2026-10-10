<?php
/** PlayMoz: TMDB direto, seletor de fonte e player remoto. PHP 7.4+ */
define('TMDB_API_KEY', getenv('TMDB_API_KEY') ?: 'b73f5479e8443355e40462afe494fc52');
define('PLAYER_BASE', 'http://fimoo.site/token-expires/player/');
define('PLAYER_ORIGIN', 'http://fimoo.site');
define('BLOCKED_HOST', '');
define('EMBED_BASE', 'https://apps.golplay.site/');
define('SITE_BASE', 'https://' . ($_SERVER['HTTP_HOST'] ?? 'playmoz.xyz'));

define('STREAM_DIR', sys_get_temp_dir() . '/pm_streams');
define('STREAM_TTL', 3600);

$qualities = ['HD4','HD3','HD2','HD1','FHD','HD5','HD6','HD7','HD8','HD9','HD10','HD11','HD12','HD13','HD14','HD15','HD16','HD17','HD18','HD19','HD20','SD'];

/* ============ TOKEN STORE ============ */
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

    $proxyUrl = SITE_BASE . strtok($_SERVER['REQUEST_URI'] ?? '/', '?') . '?stream=' . $token;

    $urlJs    = json_encode($proxyUrl, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES);
    $rawJs    = json_encode($url,      JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES);
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
<meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" name="viewport"/>
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

 /* Modal VAST: bloqueia toda a interface enquanto o anúncio está ativo. */
 #pm-ad-shell{position:fixed;inset:0;z-index:100;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(2,3,9,.94);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);box-sizing:border-box;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
 #pm-ad-dialog{width:min(720px,100%);background:#10131b;border:1px solid #323849;border-radius:18px;overflow:hidden;box-shadow:0 35px 120px #000;box-sizing:border-box}
 #pm-ad-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;color:#f5f5f7;font-size:13px;font-weight:650}
 #pm-ad-heading span:last-child{color:#a2a6b4;font-size:11px;font-weight:500}
 #pm-ad-video{width:100%;aspect-ratio:16/9;background:#000;position:relative;overflow:hidden}
 #ani-player{width:100%!important;height:100%!important}
 #pm-ad-note{padding:11px 16px;color:#a2a6b4;font-size:12px;line-height:1.5;text-align:center}
 #pm-ad-fallback{display:none;margin:0 16px 16px;padding:12px 15px;border:1px solid #4a3a3c;border-radius:10px;background:#241719;color:#fff;cursor:pointer;font:600 13px system-ui}
 #pm-ad-fallback.on{display:block}
 /* Quando o conteúdo começa, converte a mesma instância JW em player normal. */
 body.pm-content-playing #pm-ad-shell{display:block;background:#000;padding:0;backdrop-filter:none;-webkit-backdrop-filter:none}
 body.pm-content-playing #pm-ad-dialog{width:100%;height:100%;max-width:none;border:0;border-radius:0;box-shadow:none}
 body.pm-content-playing #pm-ad-video{width:100%;height:100%;aspect-ratio:auto}
 body.pm-content-playing #pm-ad-heading,body.pm-content-playing #pm-ad-note,body.pm-content-playing #pm-ad-fallback{display:none!important}
 body.pm-content-playing #down,body.pm-content-playing #btn_try{z-index:110!important}
 body.pm-content-playing #pm-back-btn{z-index:110!important}
 #pm-err{z-index:150!important}
 @media(max-width:550px){#pm-ad-shell{padding:12px}#pm-ad-dialog{border-radius:14px}#pm-ad-heading{padding:10px 12px}#pm-ad-note{font-size:11px;padding:10px}}

 /* Mantém os comandos originais fora do carregamento e da publicidade. */
 #pm-back-btn,#down{visibility:hidden!important;opacity:0!important;pointer-events:none!important;transition:opacity .24s ease,visibility .24s ease}
 body.pm-video-ready #pm-back-btn,body.pm-video-ready #down{visibility:visible!important;opacity:1!important;pointer-events:auto!important}
 body.pm-video-ready #pm-back-btn{z-index:110!important}
 body.pm-video-ready #down{z-index:110!important}
 /* O modal usa o visual simples do player original, sem uma barra de publicidade invasiva. */
 #pm-ad-shell{background:rgba(0,0,0,.88);backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px)}
 #pm-ad-dialog{border:1px solid rgba(255,255,255,.11);border-radius:12px;background:#080808;box-shadow:0 20px 80px rgba(0,0,0,.8)}
 #pm-ad-heading{background:#090909;border-bottom:1px solid rgba(255,255,255,.08)}
 #pm-ad-heading span:first-child{color:#ff3535}
 #pm-ad-note{background:#090909}
 body.pm-content-playing #pm-ad-shell{background:#000}
 body.pm-content-playing #pm-ad-dialog{background:#000}
 @media(max-width:550px){#pm-ad-dialog{border-radius:10px}}

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

/* PlayMoz · interface unificada do anúncio (filme e série) */
#pm-ad-shell{z-index:1000!important;padding:clamp(12px,3vw,32px)!important;background:radial-gradient(ellipse at 50% 15%,rgba(70,12,22,.23),transparent 64%),rgba(2,3,7,.94)!important;backdrop-filter:blur(18px) saturate(.8)!important;-webkit-backdrop-filter:blur(18px) saturate(.8)!important}
#pm-ad-dialog{width:min(780px,100%)!important;max-height:calc(100dvh - 24px);border:1px solid rgba(255,255,255,.12)!important;border-radius:20px!important;background:#111216!important;box-shadow:0 30px 110px rgba(0,0,0,.78),0 0 0 1px rgba(255,42,59,.06)!important;overflow:hidden;animation:pmAdEnter .38s cubic-bezier(.2,.85,.2,1) both}
@keyframes pmAdEnter{from{opacity:0;transform:translateY(14px) scale(.975)}to{opacity:1;transform:none}}
#pm-ad-heading{padding:17px 20px!important;background:linear-gradient(120deg,#191a20,#111216)!important;border-bottom:1px solid rgba(255,255,255,.09)!important;min-height:52px;box-sizing:border-box;letter-spacing:.01em}
#pm-ad-heading span:first-child{display:inline-flex;align-items:center;gap:10px;color:#fafafa!important;font-size:13px;font-weight:800;letter-spacing:.055em}
#pm-ad-heading span:first-child:before{content:'▶';display:grid;place-items:center;width:29px;height:29px;border-radius:9px;background:linear-gradient(135deg,#ff3348,#ba0b22);font-size:12px;color:white;box-shadow:0 4px 15px #ff253c33}
#pm-ad-heading span:last-child{font-size:11px!important;color:#b9b9c3!important}
#pm-ad-video{background:#050506!important;aspect-ratio:16/9!important;max-height:calc(100dvh - 185px);min-height:0}
#pm-ad-note{background:#111216!important;color:#b4b5c1!important;padding:15px 20px 17px!important;font-size:12px!important;line-height:1.6!important;text-align:left!important;border-top:1px solid rgba(255,255,255,.06)}
#pm-ad-note:before{content:'●';color:#fa3447;font-size:10px;margin-right:8px}
#pm-ad-fallback{width:calc(100% - 40px);margin:0 20px 18px!important;border:1px solid rgba(255,53,71,.45)!important;background:linear-gradient(135deg,#ad152c,#720b1e)!important;border-radius:12px!important;padding:13px 15px!important;font-weight:750!important;text-align:center}
#pm-ad-fallback:focus-visible{outline:2px solid #fff;outline-offset:2px}
body.pm-content-playing #pm-ad-shell{padding:0!important;background:#000!important;backdrop-filter:none!important;-webkit-backdrop-filter:none!important}
body.pm-content-playing #pm-ad-dialog{width:100%!important;max-height:none!important;height:100%!important;border:none!important;border-radius:0!important;box-shadow:none!important;animation:none!important;background:#000!important}
body.pm-content-playing #pm-ad-video{height:100%!important;max-height:none!important;aspect-ratio:auto!important}
body.pm-content-playing #pm-ad-heading,body.pm-content-playing #pm-ad-note,body.pm-content-playing #pm-ad-fallback{display:none!important}
@media(max-width:550px){#pm-ad-shell{padding:12px!important}#pm-ad-dialog{border-radius:15px!important}#pm-ad-heading{padding:12px 13px!important;gap:6px!important}#pm-ad-heading span:first-child{font-size:11px!important;gap:7px}#pm-ad-heading span:first-child:before{width:24px;height:24px}#pm-ad-heading span:last-child{font-size:10px!important;text-align:right}#pm-ad-note{font-size:11px!important;padding:12px 13px!important}#pm-ad-fallback{width:calc(100% - 26px);margin:0 13px 13px!important}}
@media(prefers-reduced-motion:reduce){#pm-ad-dialog{animation:none!important}}
/* PlayMoz: restaurar comandos de topo acima do player de conteúdo. */
body.pm-content-playing.pm-video-ready #pm-back-btn,
body.pm-content-playing.pm-video-ready #down{
  z-index:1200!important;visibility:visible!important;opacity:1!important;
  pointer-events:auto!important;position:fixed!important;top:calc(8px + env(safe-area-inset-top, 0px));
}
body.pm-content-playing.pm-video-ready #pm-back-btn{left:clamp(6px,2vw,16px)!important}
body.pm-content-playing.pm-video-ready #down{right:clamp(6px,2vw,16px)!important}
body.pm-content-playing.pm-video-ready #down-list{z-index:1201!important}
@media(max-width:380px){body.pm-content-playing.pm-video-ready #pm-back-btn{padding:10px 13px!important}body.pm-content-playing.pm-video-ready .download{padding:10px 8px!important;letter-spacing:0!important}}


/* === PLAYMOZ VAST CINEMA: exclusivo ao modal publicitário === */
body:not(.pm-content-playing) #pm-ad-shell{
  isolation:isolate; padding:clamp(8px,1.4vw,18px)!important;
  background:rgba(2,3,8,.88)!important;
  backdrop-filter:blur(25px) brightness(.45)!important;
  -webkit-backdrop-filter:blur(25px) brightness(.45)!important;
}
body:not(.pm-content-playing) #pm-ad-shell:before{
  content:"";position:absolute;inset:-35px;z-index:-1;
  background-image:var(--pm-ad-poster,none);background-size:cover;background-position:center;
  filter:blur(40px) brightness(.25) saturate(.6);opacity:.72;pointer-events:none;
}
body:not(.pm-content-playing) #pm-ad-dialog{
  width:min(1480px,98vw)!important;max-width:100%!important;
  height:min(92dvh,900px)!important;max-height:calc(100dvh - 16px)!important;
  display:flex;flex-direction:column;position:relative;
  border:1px solid rgba(255,255,255,.15)!important;border-radius:clamp(12px,1.6vw,23px)!important;
  background:#090b10!important;
  box-shadow:0 32px 120px rgba(0,0,0,.9),0 0 0 1px rgba(255,42,58,.09)!important;
}
body:not(.pm-content-playing) #pm-ad-heading{
  flex:0 0 auto;min-height:55px!important;padding:12px clamp(12px,2vw,25px)!important;
  background:linear-gradient(110deg,#151821,#0b0c12)!important;
}
body:not(.pm-content-playing) #pm-ad-heading span:first-child{font-size:clamp(11px,1vw,14px)!important}
body:not(.pm-content-playing) #pm-ad-heading span:last-child{font-size:clamp(10px,.9vw,12px)!important}
body:not(.pm-content-playing) #pm-ad-video{
  flex:1 1 auto;min-height:0!important;width:100%;height:auto!important;
  max-height:none!important;aspect-ratio:auto!important;display:flex;
  align-items:center;justify-content:center;background:#000!important;
}
body:not(.pm-content-playing) #pm-ad-video #ani-player,
body:not(.pm-content-playing) #pm-ad-video .jwplayer{
  width:100%!important;height:100%!important;max-width:none!important;max-height:none!important;
}
body:not(.pm-content-playing) #pm-ad-video video{object-fit:contain!important}
body:not(.pm-content-playing) #pm-ad-note{
  flex:0 0 auto;min-height:44px;box-sizing:border-box;
  padding:12px clamp(12px,2vw,25px)!important;
  background:linear-gradient(110deg,#13151b,#0c0d12)!important;
}
body:not(.pm-content-playing) #pm-ad-fallback{flex:0 0 auto}
body.pm-content-playing #pm-ad-shell:before{display:none}
@media (max-width:600px){
 body:not(.pm-content-playing) #pm-ad-shell{padding:5px!important}
 body:not(.pm-content-playing) #pm-ad-dialog{
   width:calc(100vw - 10px)!important;height:calc(100dvh - 18px)!important;
   border-radius:14px!important;
 }
 body:not(.pm-content-playing) #pm-ad-heading{min-height:50px!important;padding:10px 11px!important}
 body:not(.pm-content-playing) #pm-ad-heading span:first-child{letter-spacing:0!important;font-size:11px!important}
 body:not(.pm-content-playing) #pm-ad-heading span:last-child{max-width:42%;font-size:10px!important}
 body:not(.pm-content-playing) #pm-ad-note{padding:12px!important;font-size:11px!important}
}
@media (max-height:440px) and (orientation:landscape){
 body:not(.pm-content-playing) #pm-ad-dialog{height:calc(100dvh - 8px)!important}
 body:not(.pm-content-playing) #pm-ad-heading{min-height:37px!important;padding:5px 12px!important}
 body:not(.pm-content-playing) #pm-ad-heading span:first-child:before{width:22px;height:22px}
 body:not(.pm-content-playing) #pm-ad-note{min-height:28px!important;padding:5px 12px!important}
}


/* PLAYMOZ VAST PREMIUM: tipografia, SVG, alinhamento e player sem zoom */
html,body{overscroll-behavior:none;-webkit-text-size-adjust:100%;text-size-adjust:100%}
#pm-ad-shell,#pm-ad-dialog,#pm-ad-video,#ani-player{touch-action:manipulation}
body:not(.pm-content-playing) #pm-ad-dialog{font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Arial,sans-serif!important}
body:not(.pm-content-playing) #pm-ad-heading{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:12px!important}
#pm-ad-heading .pm-ad-brand{display:flex!important;align-items:center;gap:11px;color:#fff!important;min-width:0;letter-spacing:0!important}
#pm-ad-heading .pm-ad-brand::before{content:none!important;display:none!important}
.pm-ad-brand-icon{flex:0 0 36px;width:36px;height:36px;display:grid;place-items:center;color:#fff;border-radius:11px;background:linear-gradient(135deg,#ff3d50,#c80829);box-shadow:0 5px 18px rgba(255,25,59,.22)}
.pm-ad-brand-icon svg{width:20px;height:20px}
.pm-ad-brand-copy{display:flex;flex-direction:column;gap:2px;min-width:0}
#pm-ad-heading .pm-ad-brand-copy strong{font-size:clamp(13px,1.2vw,16px);font-weight:850;letter-spacing:-.035em;line-height:1.15;color:#fff}
#pm-ad-heading .pm-ad-brand-copy small{font-size:11px;font-weight:570;letter-spacing:.015em;line-height:1.25;color:#b7bac5}
#pm-ad-heading .pm-ad-status{display:inline-flex!important;align-items:center;gap:7px;color:#e2e3e9!important;font-weight:750!important;font-size:clamp(10px,.95vw,12px)!important;letter-spacing:0!important;white-space:nowrap}
.pm-ad-status svg{width:15px;height:15px;color:#ff455b;flex:none}
#pm-ad-note{display:flex;align-items:center;gap:10px!important;letter-spacing:0!important;font-weight:550!important}
#pm-ad-note::before{content:none!important;display:none!important}
#pm-ad-note svg{width:18px;height:18px;flex:none;color:#ff5065}
#pm-ad-note span{flex:1}
#pm-ad-fallback{display:none;align-items:center;justify-content:center;gap:9px;font-family:Inter,ui-sans-serif,system-ui,sans-serif!important;font-weight:800!important;letter-spacing:-.01em}
#pm-ad-fallback.on{display:flex!important}
#pm-ad-fallback svg{width:18px;height:18px}
/* Comando Espelhar/Baixar centralizado (filmes e séries) */
body.pm-content-playing.pm-video-ready #down{box-sizing:border-box!important;margin:0!important;display:flex!important;align-items:center!important;justify-content:center!important;right:calc(12px + env(safe-area-inset-right,0px))!important;left:auto!important;top:calc(12px + env(safe-area-inset-top,0px))!important}
body.pm-content-playing.pm-video-ready #down>.download{box-sizing:border-box!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;min-height:37px!important;margin:0!important;padding:9px 14px!important;line-height:1.2!important;text-align:center!important;white-space:nowrap;letter-spacing:.015em!important}
body.pm-content-playing.pm-video-ready #down>.down-list{box-sizing:border-box!important;top:calc(100% + 8px)!important;left:auto!important;right:0!important;margin:0!important;padding:5px!important;min-width:160px!important;width:max-content!important;max-width:calc(100vw - 20px)!important;border-radius:14px!important}
body.pm-content-playing.pm-video-ready #down>.down-list li{float:none!important;width:100%!important;box-sizing:border-box!important;margin:0!important;padding:8px 10px!important}
body.pm-content-playing.pm-video-ready #down>.down-list a{font-size:13px!important;font-weight:700!important}
@media(max-width:600px){.pm-ad-brand-icon{flex-basis:31px;width:31px;height:31px;border-radius:9px}.pm-ad-brand-icon svg{width:17px;height:17px}#pm-ad-heading .pm-ad-brand-copy strong{font-size:12px}#pm-ad-heading .pm-ad-brand-copy small{font-size:10px}#pm-ad-heading .pm-ad-status{font-size:10px!important;white-space:normal;text-align:right;line-height:1.25}#pm-ad-heading .pm-ad-status svg{width:14px;height:14px}#pm-ad-note{line-height:1.35!important;font-size:11px!important}body.pm-content-playing.pm-video-ready #down{right:calc(8px + env(safe-area-inset-right,0px))!important}body.pm-content-playing.pm-video-ready #down>.download{padding:9px 10px!important;font-size:10px!important}}
@media(max-height:440px) and (orientation:landscape){.pm-ad-brand-icon{flex-basis:26px;width:26px;height:26px}.pm-ad-brand-icon svg{width:14px;height:14px}#pm-ad-heading .pm-ad-brand-copy small{display:none}#pm-ad-note svg{width:14px;height:14px}}

</style>
</head>
<body>
<script>
/* Impede ampliacao por pinça, duplo toque e atalhos enquanto este player está aberto. */
(function(){
  var lastTouchEnd=0;
  document.addEventListener('gesturestart',function(e){e.preventDefault()},{passive:false});
  document.addEventListener('gesturechange',function(e){e.preventDefault()},{passive:false});
  document.addEventListener('touchmove',function(e){if(e.touches.length>1)e.preventDefault()},{passive:false});
  document.addEventListener('touchend',function(e){var now=Date.now();if(now-lastTouchEnd<280 && e.changedTouches.length===1)e.preventDefault();lastTouchEnd=now},{passive:false});
  document.addEventListener('wheel',function(e){if(e.ctrlKey)e.preventDefault()},{passive:false});
  document.addEventListener('keydown',function(e){if((e.ctrlKey||e.metaKey)&&['+','-','=','0'].includes(e.key))e.preventDefault()});
})();
</script>

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

<div id="pm-back-btn" style=" background:#ff0000; padding:10px 20px; letter-spacing:1px; box-shadow:0 1px 15px #ff0000; color:#fff; font-family:'Open-Sans',sans-serif; margin:8px; border-radius:19px; font-weight:bold; font-size:11px; position:absolute; left:16px; z-index:9; cursor:pointer;" onclick="goBack()">Voltar</div>

<div id="btn_try" style=" background:#333; padding:10px 20px; letter-spacing:1px; box-shadow:0 1px 15px #333; color:#fff; font-family:'Open-Sans',sans-serif; margin:8px; border-radius:19px; font-weight:bold; font-size:11px; position:absolute; left:100px; z-index:9; display:none; cursor:pointer;" onclick="window.location.reload()">Tentar novamente</div>

<div id="down" style="right:16px;">
  <div class="download">Espelhar/Baixar</div>
  <ul id="down-list" class="down-list">
    <li><a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" download target="_blank">Clique aqui</a></li>
  </ul>
</div>

<div id="pm-ad-shell" role="dialog" aria-modal="true" aria-label="Publicidade antes do episódio">
  <div id="pm-ad-dialog">
    <div id="pm-ad-heading"><div class="pm-ad-brand"><span class="pm-ad-brand-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 10 6-10 6V6Z"/></svg></span><span class="pm-ad-brand-copy"><strong>PlayMoz</strong><small>Uma breve publicidade</small></span></div><span class="pm-ad-status"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg><span>O vídeo começa a seguir</span></span></div>
    <div id="pm-ad-video"><div id="ani-player"></div></div>
    <div id="pm-ad-note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg><span>Para continuar, vê o anúncio. Podes ignorá-lo quando aparecer a opção.</span></div>
    <button id="pm-ad-fallback" type="button"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 6 8 6-8 6V6Zm10 0v12"/><path d="M20 6v12"/></svg><span>Ver episódio</span></button>
  </div>
</div>

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
function pmContentReady(){
  if (document.body.classList.contains('pm-content-playing')) return;
  document.body.classList.add('pm-content-playing');
  document.getElementById('pm-ad-shell').setAttribute('aria-modal','false');
  try { player.resize('100%', '100%'); } catch(e) {}
}
var pmAdActive = false;
var pmAdAttempted = false;
function pmAdUnavailable(){
  pmAdActive = false;
  // Um erro de publicidade nunca deve deixar o utilizador preso no modal.
  var btn = document.getElementById('pm-ad-fallback');
  if(btn) btn.classList.add('on');
  try { if (player && player.getState && player.getState() === 'playing') pmContentReady(); } catch(e) {}
}
function pmMarkVideoReady(){
  if (!pmAdActive) { pmContentReady(); document.body.classList.add('pm-video-ready'); }
}
function pmShowErr(){
  var el = document.getElementById('pm-err');
  if (el) el.classList.add('on');
  document.body.classList.add('pm-video-ready');
  try { if (window.player && player.pause) player.pause(true); } catch(e){}
}
function pmHideErr(){
  var el = document.getElementById('pm-err');
  if (el) el.classList.remove('on');
}

// Fundo cinematográfico exclusivo do VAST; não modifica o player normal.
try {
  document.getElementById('pm-ad-shell').style.setProperty('--pm-ad-poster', 'url(' + JSON.stringify(<?= $posterJs ?>) + ')');
} catch(e) {}
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
  // HilltopAds — VAST 3.0 pre-roll: tenta apresentar antes do conteudo.
  // Requer a funcionalidade de publicidade activa na licenca JW Player.
  advertising: {
    client: "vast",
    tag: "https://funny-tooth.com/d-mOFUzHd.G_NMvJZmGJUw/feOmr9xuwZCU_l/k/PxT/c/1MM/Dhcp0/Nvj/EatGNHztU/wtNnzLQH2INgQv",
    admessage: "Publicidade",
    skipmessage: "Saltar anuncio em xx",
    skiptext: "Saltar anuncio",
  },
  playbackRateControls: [0.5, 0.75, 1, 1.25, 1.5, 2],
  debug: false,
  cast: {},
});

// 👇 Erro do JW → overlay elegante (nunca mostra 224003)
player.on('error', function(){ pmAdUnavailable(); pmShowErr(); });
player.on('mediaError', function(){ pmAdUnavailable(); pmShowErr(); });
player.on('play', function(){
  pmHideErr();
  // Não antecipar o aparecimento dos botões: aguardar o primeiro frame real.
});
player.on('firstFrame', pmMarkVideoReady);
player.on('time', function(evt){ if(!pmAdActive && evt && evt.position > 0 && !document.body.classList.contains('pm-video-ready')) pmMarkVideoReady(); });
player.on('visualQuality', function(){ /* preservar eventos do JW */ });
player.on('adRequest', function(){ pmAdAttempted = true; document.body.classList.remove('pm-video-ready'); });
player.on('adStarted', function(){ pmAdActive = true; pmAdAttempted = true; document.body.classList.remove('pm-video-ready'); });
player.on('adPlay', function(){ pmAdActive = true; });
player.on('adComplete', function(){ pmAdActive = false; });
player.on('adSkipped', function(){ pmAdActive = false; });
player.on('adError', pmAdUnavailable);
player.on('adBlock', pmAdUnavailable);
document.getElementById('pm-ad-fallback').addEventListener('click', function(){
  // Recuperação da publicidade: reactivar o vídeo sem deixar o utilizador preso.
  pmContentReady();
  try { player.play(true); } catch(e) {}
});
player.on('buffer', pmHideErr);

player.addButton('<svg xmlns="http://www.w3.org/2000/svg" class="jw-svg-icon jw-svg-icon-rewind2" viewBox="0 0 240 240" focusable="false"><path d="m 25.993957,57.778 v 125.3 c 0.03604,2.63589 2.164107,4.76396 4.8,4.8 h 62.7 v -19.3 h -48.2 v -96.4 H 160.99396 v 19.3 c 0,5.3 3.6,7.2 8,4.3 l 41.8,-27.9 c 2.93574,-1.480087 4.13843,-5.04363 2.7,-8 -0.57502,-1.174985 -1.52502,-2.124979 -2.7,-2.7 l -41.8,-27.9 c -4.4,-2.9 -8,-1 -8,4.3 v 19.3 H 30.893957 c -2.689569,0.03972 -4.860275,2.210431 -4.9,4.9 z m 163.422413,73.04577 c -3.72072,-6.30626 -10.38421,-10.29683 -17.7,-10.6 -7.31579,0.30317 -13.97928,4.29374 -17.7,10.6 -8.60009,14.23525 -8.60009,32.06475 0,46.3 3.72072,6.30626 10.38421,10.29683 17.7,10.6 7.31579,-0.30317 13.97928,-4.29374 17.7,-10.6 8.60009,-14.23525 8.60009,-32.06475 0,-46.3 z m -17.7,47.2 c -7.8,0 -14.4,-11 -14.4,-24.1 0,-13.1 6.6,-24.1 14.4,-24.1 7.8,0 14.4,11 14.4,24.1 0,13.1 -6.5,24.1 -14.4,24.1 z m -47.77056,9.72863 v -51 l -4.8,4.8 -6.8,-6.8 13,-12.99999 c 3.02543,-3.03598 8.21053,-0.88605 8.2,3.4 v 62.69999 z"></path></svg>', "Avançar 10s", function () { player.seek(player.getPosition() + 10); }, "Avançar 10s");

player.addButton('<svg xmlns="http://www.w3.org/2000/svg" class="jw-svg-icon jw-svg-icon-rewind" viewBox="0 0 240 240" focusable="false"><path d="M113.2,131.078a21.589,21.589,0,0,0-17.7-10.6,21.589,21.589,0,0,0-17.7,10.6,44.769,44.769,0,0,0,0,46.3,21.589,21.589,0,0,0,17.7,10.6,21.589,21.589,0,0,0,17.7-10.6,44.769,44.769,0,0,0,0-46.3Zm-17.7,47.2c-7.8,0-14.4-11-14.4-24.1s6.6-24.1,14.4-24.1,14.4,11,14.4,24.1S103.4,178.278,95.5,178.278Zm-43.4,9.7v-51l-4.8,4.8-6.8-6.8,13-13a4.8,4.8,0,0,1,8.2,3.4v62.7l-9.6-.1Zm162-130.2v125.3a4.867,4.867,0,0,1-4.8,4.8H146.6v-19.3h48.2v-96.4H79.1v19.3c0,5.3-3.6,7.2-8,4.3l-41.8-27.9a6.013,6.013,0,0,1-2.7-8,5.887,5.887,0,0,1,2.7-2.7l41.8-27.9c4.4-2.9,8-1,8,4.3v19.3H209.2A4.974,4.974,0,0,1,214.1,57.778Z"></path></svg>', "Voltar 10s", function () { player.seek(player.getPosition() - 10); }, "Voltar 10s");
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
            ['Accept: video/mp4,video/*;q=0.9,*/*;q=0.8', 'Referer: '.PLAYER_ORIGIN.'/'],
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

// Rotas aceites: /serie/1399/1/1, /series/1399/1/1 e /tv/1399/1/1.
// QUERY_STRING continua disponível para chamadas internas ?probe= e ?embed=.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$parts = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
$offset = (isset($parts[0]) && in_array(strtolower($parts[0]), ['serie','series','tv'], true)) ? 1 : 0;
$pathValid = isset($parts[$offset], $parts[$offset+1], $parts[$offset+2])
    && count($parts) === $offset + 3
    && ctype_digit($parts[$offset]) && ctype_digit($parts[$offset+1]) && ctype_digit($parts[$offset+2]);
$id = $pathValid ? $parts[$offset] : ($_GET['id'] ?? null);
$type = 'tv';
$season = filter_var($pathValid ? $parts[$offset+1] : ($_GET['season'] ?? $_GET['t'] ?? null), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$episode = filter_var($pathValid ? $parts[$offset+2] : ($_GET['episode'] ?? $_GET['e'] ?? null), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);

// ID TMDB é obrigatório agora
if (!$id || !ctype_digit((string)$id)) {
    if (isset($_GET['probe'])) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'missing_tmdb_id']);
        exit;
    }
}

if (isset($_GET['probe'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $q=(string)$_GET['probe'];
    if (!$id || !in_array($q,array_merge($qualities,['default']),true)) {
        http_response_code(400);
        echo json_encode(['ok'=>false]); exit;
    }
    pm_store_gc();

    $cacheKey = 'pm_cache_' . md5($id . '|' . $type . '|' . ($season ?? 0) . '|' . ($episode ?? 0) . '|' . $q);
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

    // Monta a URL remota usando o ID TMDB directamente
    if ($type === 'tv') {
        $remote = PLAYER_BASE . 'series/?i=' . rawurlencode($id)
                . ($season  ? '&t=' . (int)$season  : '')
                . ($episode ? '&e=' . (int)$episode : '')
                . ($q !== 'default' ? '&s=' . rawurlencode($q) : '');
    } else {
        $remote = PLAYER_BASE . 'movie/?i=' . rawurlencode($id)
                . ($q !== 'default' ? '&s=' . rawurlencode($q) : '');
    }

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
if($season) $params['season']=$season;
if($episode) $params['episode']=$episode;
$self=strtok($_SERVER['REQUEST_URI']??'/', '?');
$base=SITE_BASE.$self.'?'.http_build_query($params);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover">
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
const PM_ID = <?= (int)$id ?>;
const PM_TYPE = <?= json_encode($type) ?>;
const PM_SEASON = <?= (int)$season ?>;
const PM_EPISODE = <?= (int)$episode ?>;

let PM_EMBED_URL = '';
if (PM_ID > 0) {
  if (PM_TYPE === 'tv') {
    PM_EMBED_URL = '<?= EMBED_BASE ?>tv/' + PM_ID + '/' + PM_SEASON + '/' + PM_EPISODE;
  } else {
    PM_EMBED_URL = '<?= EMBED_BASE ?>movie/' + PM_ID;
  }
}

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
  if (!PM_ID) {
    pmModal('ID TMDB em falta',
      '<div class="pm-alert">'+ICONS.alert+'</div>'+
      '<h2>Não foi possível identificar</h2><p>Informa o ID TMDB do título.</p>',
      '');
    return;
  }
  pmLoading();
  try {
    const results = await Promise.all(PM_QUALITIES.map(async (q) => {
      try {
        const r = await fetch(PM_BASE + '&probe=' + encodeURIComponent(q), { cache: 'no-store', credentials: 'omit' });
        if (!r.ok) return null;
        const data = await r.json();
        if (!data.ok || !data.server) return null;
        const mp4 = data.server.original || data.server.url || '';
        const embed = data.server.embed || '';
        if (!mp4 || !pmValidUrl(mp4) || !/\.mp4(\?|$)/i.test(mp4)) return null;
        if (!embed) return null;
        return { file: mp4, embed: embed, label: q, original: mp4 };
      } catch { return null; }
    }));
    const found = results.find(Boolean);
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

</body>
</html>
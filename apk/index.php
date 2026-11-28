<?php
declare(strict_types=1);

/* ===== INICIALIZAÇÃO SEGURA ===== */
session_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
if (!ob_get_level()) ob_start();

/* ===== CONFIGURAÇÕES ===== */
const FIREBASE_CONFIG = [
    'databaseURL' => "https://este-14066-default-rtdb.firebaseio.com",
];
const API_BASE = 'https://mgeb.lat/player_api.php';
const API_USER = 'Feljo';
const API_PASS = '22109f3c5de3aa6c';
const CACHE_DIR = __DIR__ . '/.cinevs-cache';
const CACHE_TTL = 240;
const CATALOG_FILE = __DIR__ . '/r.json';
const APP_UA = 'Mozilla/5.0 (Linux; Android 10; Mobile) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36 ShortPlay/5.0';

$categories = [
    '21511728' => 'Lançamentos',
    '22684705' => 'Séries Dubladas',
    '21089634' => 'Histórias da Ásia',
    '25271815' => 'Gênero Masculino',
    '22114813' => 'Lobisomem & Vampiro',
    '23445999' => 'Amor à Primeira Vista',
    '21447172' => 'Identidade Escondida'
];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/* ===== HELPERS ===== */
function json_out($data, int $status=200): void {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) { $status = 500; $json = '{"error":"Falha JSON."}'; }
    while (ob_get_level() > 0) @ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo $json; exit;
}
function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function clean_id($value): string { return preg_match('/^\d{1,12}$/', (string)$value) ? (string)$value : ''; }
function cover_url($cover): string {
    $cover = trim((string)$cover);
    if ($cover === '') return '';
    if (strpos($cover, '//') === 0) return 'https:' . $cover;
    if (preg_match('~^https?://~i', $cover)) return $cover;
    return 'https://mgeb.lat/' . ltrim($cover, '/');
}

/* ===== FIREBASE REQUEST (CORRIGIDO: aceita mixed) ===== */
function firebase_request(string $path, string $method = 'GET', $data = null): array {
    $url = rtrim(FIREBASE_CONFIG['databaseURL'], '/') . '/' . ltrim($path, '/') . '.json';
    $ch = @curl_init($url);
    if ($ch === false) return ['error' => 'Falha na ligação ao Firebase.'];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CUSTOMREQUEST => $method,
    ];
    if ($data !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($data);
        $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    @curl_setopt_array($ch, $options);
    $body = @curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    @curl_close($ch);
    if (!is_string($body) || $code < 200 || $code >= 300) {
        error_log("Firebase [$method $path] HTTP=$code Body=" . substr((string)$body, 0, 200));
        return ['error' => 'Falha de comunicação com o Firebase.', 'http' => $code];
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : [];
}

/* ===== LOGOUT ===== */
if (($_GET['page'] ?? '') === 'logout') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    setcookie('sp_session', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
    session_destroy();
    header('Location: ?page=login');
    exit;
}

/* ===== AUTENTICAÇÃO ===== */
if (isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'login') {
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        if (!$phone || !$password) json_out(['error' => 'Preencha todos os campos.'], 400);
        
        $user = firebase_request('users/' . urlencode($phone));
        
        if (isset($user['error']) || empty($user['password'])) {
            json_out(['error' => 'Número não registado. Crie uma conta.'], 401);
        }
        if (!password_verify($password, $user['password'])) {
            json_out(['error' => 'Senha incorreta.'], 401);
        }
        
        $_SESSION['user_phone'] = $phone;
        $_SESSION['user_name'] = $user['name'] ?? 'Utilizador';
        setcookie('sp_session', $phone, time() + (86400 * 365), "/", "", isset($_SERVER['HTTPS']), true);
        json_out(['success' => true]);
    }
    
    if ($action === 'register') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $avatar = trim($_POST['avatar'] ?? '');
        
        if (!$name || !$phone || !$password) json_out(['error' => 'Preencha todos os campos.'], 400);
        if (strlen($password) < 4) json_out(['error' => 'Senha muito curta (mín. 4 caracteres).'], 400);
        
        $existing = firebase_request('users/' . urlencode($phone));
        if (!empty($existing) && isset($existing['password'])) {
            json_out(['error' => 'Este número já está registado. Faça login.'], 400);
        }
        
        $userData = [
            'name' => $name,
            'phone' => $phone,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'avatar' => $avatar ?: 'https://api.dicebear.com/7.x/avataaars/svg?seed=' . urlencode($name),
            'vip_expires' => 0,
            'created_at' => time()
        ];
        
        $res = firebase_request('users/' . urlencode($phone), 'PUT', $userData);
        if (isset($res['error'])) {
            json_out(['error' => 'Erro ao criar conta. Tente novamente.'], 500);
        }
        
        $_SESSION['user_phone'] = $phone;
        $_SESSION['user_name'] = $name;
        setcookie('sp_session', $phone, time() + (86400 * 365), "/", "", isset($_SERVER['HTTPS']), true);
        json_out(['success' => true]);
    }
    
    if ($action === 'edit_profile') {
        if (empty($_SESSION['user_phone'])) json_out(['error' => 'Sessão expirada.'], 401);
        $phone = $_SESSION['user_phone'];
        $updates = [];
        if (!empty($_POST['name'])) $updates['name'] = trim($_POST['name']);
        if (!empty($_POST['avatar'])) $updates['avatar'] = trim($_POST['avatar']);
        if (!empty($_POST['password'])) $updates['password'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
        if (empty($updates)) json_out(['error' => 'Nada para atualizar.'], 400);
        firebase_request('users/' . urlencode($phone), 'PATCH', $updates);
        if (isset($updates['name'])) $_SESSION['user_name'] = $updates['name'];
        json_out(['success' => true]);
    }
    
    if ($action === 'sync_history') {
        if (empty($_SESSION['user_phone'])) json_out(['error' => 'Não logado'], 401);
        $phone = $_SESSION['user_phone'];
        $seriesId = clean_id($_POST['id'] ?? '');
        if ($seriesId) {
            firebase_request('users/' . urlencode($phone) . '/history/' . $seriesId, 'PUT', (int)time());
        }
        json_out(['success' => true]);
    }
}

/* ===== PROXY API ===== */
if (isset($_GET['api'])) {
    try {
        if (empty($_SESSION['user_phone'])) json_out(['error' => 'Sessão expirada.'], 401);
        $seriesId = clean_id($_GET['id'] ?? '');
        if ($seriesId === '') json_out(['error' => 'ID inválido.'], 400);
        $catalogItem = find_series($seriesId);
        if (!$catalogItem) json_out(['error' => 'Série não encontrada.'], 404);
        $isVip = check_vip();
        $data = api_get($seriesId);
        if (isset($data['_error'])) json_out(['error' => (string)$data['_error']], 502);
        $episodes = [];
        foreach (flatten_episodes($data) as $episode) {
            $episodeInfo = (isset($episode['info']) && is_array($episode['info'])) ? $episode['info'] : [];
            $sources = (isset($episode['sources']) && is_array($episode['sources'])) ? $episode['sources'] : [];
            $stream = '';
            foreach ($sources as $source) {
                if (is_array($source) && isset($source['url']) && is_string($source['url']) && trim($source['url']) !== '') {
                    $stream = trim($source['url']); break;
                }
            }
            if ($stream === '' && isset($episode['direct_source']) && is_string($episode['direct_source'])) $stream = trim($episode['direct_source']);
            if ($stream !== '' && !preg_match('~^https?://~i', $stream)) $stream = '';
            $epNumber = (int)($episode['episode_num'] ?? 0);
            if ($epNumber > 10 && !$isVip) $stream = '';
            $episodes[] = [
                'id' => (string)($episode['id'] ?? ''),
                'title' => (string)($episode['title'] ?? $episodeInfo['name'] ?? 'Episódio'),
                'number' => $epNumber,
                'season' => (string)($episode['_season'] ?? '1'),
                'image' => cover_url($episodeInfo['still_path'] ?? $episodeInfo['movie_image'] ?? ''),
                'stream' => $stream,
                'is_locked' => ($epNumber > 10 && !$isVip)
            ];
        }
        $apiInfo = (isset($data['info']) && is_array($data['info'])) ? $data['info'] : [];
        json_out([
            'name' => (string)($apiInfo['name'] ?? $catalogItem['name'] ?? ''),
            'cover' => cover_url($apiInfo['cover'] ?? $catalogItem['cover'] ?? ''),
            'plot' => (string)($apiInfo['plot'] ?? $apiInfo['description'] ?? $catalogItem['plot'] ?? $catalogItem['description'] ?? ''),
            'seasons' => (isset($data['seasons']) && is_array($data['seasons'])) ? $data['seasons'] : [],
            'episodes' => $episodes,
            'is_vip' => $isVip
        ]);
    } catch (Throwable $e) {
        error_log('API Exception: ' . $e->getMessage());
        json_out(['error' => 'Erro interno.'], 500);
    }
}

function catalog(): array {
    if (!is_file(CATALOG_FILE)) return [];
    $raw = @file_get_contents(CATALOG_FILE);
    if (!is_string($raw) || $raw === '') return [];
    $data = json_decode($raw, true);
    return (json_last_error() === JSON_ERROR_NONE && is_array($data)) ? $data : [];
}
function find_series(string $id): ?array {
    static $cache = null;
    if ($cache === null) $cache = catalog();
    foreach ($cache as $item) {
        if (is_array($item) && (string)($item['series_id'] ?? '') === $id) return $item;
    }
    return null;
}
function check_vip(): bool {
    if (empty($_SESSION['user_phone'])) return false;
    $user = firebase_request('users/' . urlencode($_SESSION['user_phone']));
    return isset($user['vip_expires']) && (int)$user['vip_expires'] > time();
}
function api_get(string $seriesId): array {
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0755, true);
    $cacheFile = CACHE_DIR . '/series_' . $seriesId . '.json';
    if (is_file($cacheFile)) {
        $age = time() - (int)@filemtime($cacheFile);
        if ($age >= 0 && $age < CACHE_TTL) {
            $cached = json_decode(@file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['episodes'])) return $cached;
        }
    }
    if (!function_exists('curl_init')) return ['_error' => 'cURL indisponível.'];
    $url = API_BASE . '?' . http_build_query([
        'username' => API_USER, 'password' => API_PASS,
        'action' => 'get_series_info', 'series_id' => $seriesId
    ]);
    $ch = @curl_init($url);
    @curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4,
        CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_TIMEOUT => 25, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => APP_UA,
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    $body = @curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    @curl_close($ch);
    if (!is_string($body) || $code < 200 || $code >= 300) return ['_error' => 'Servidor indisponível.'];
    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) return ['_error' => 'Erro ao processar.'];
    if (is_dir(CACHE_DIR) && is_writable(CACHE_DIR)) @file_put_contents($cacheFile, $body, LOCK_EX);
    return $data;
}
function flatten_episodes(array $info): array {
    $result = [];
    foreach (($info['episodes'] ?? []) as $k => $eps) {
        if (!is_array($eps)) continue;
        foreach ($eps as $e) {
            if (!is_array($e)) continue;
            $e['_season'] = (string)($e['season'] ?? $k);
            $result[] = $e;
        }
    }
    usort($result, fn($a,$b) => ((int)($a['_season']??0) <=> (int)($b['_season']??0)) ?: ((int)($a['episode_num']??0) <=> (int)($b['episode_num']??0)));
    return $result;
}

/* ===== ROTEAMENTO ===== */
$items = catalog();
$page = (string)($_GET['page'] ?? 'home');
$id = clean_id($_GET['id'] ?? '');
$selected = $id ? find_series($id) : null;
if (!in_array($page, ['home', 'history', 'search', 'player', 'account', 'login'], true)) $page = 'home';
if ($page === 'player' && !$selected) $page = 'home';
$is_logged_in = !empty($_SESSION['user_phone']);
if (!$is_logged_in && $page !== 'login') $page = 'login';

$user_name = $_SESSION['user_name'] ?? '';
$user_phone = $_SESSION['user_phone'] ?? '';
$is_vip = check_vip();
$user_avatar = '';
$user_history_ids = [];
if ($is_logged_in) {
    $user = firebase_request('users/' . urlencode($user_phone));
    $user_avatar = $user['avatar'] ?? 'https://api.dicebear.com/7.x/avataaars/svg?seed=' . urlencode($user_name);
    $historyRaw = $user['history'] ?? [];
    if (is_array($historyRaw)) {
        arsort($historyRaw);
        foreach ($historyRaw as $sid => $ts) $user_history_ids[] = ['id' => (string)$sid, 'time' => (int)$ts];
    }
}

function icon(string $name): string {
    $paths = [
        'home' => '<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
        'history' => '<path d="M3 12a9 9 0 1 0 2.6-6.4L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
        'search' => '<circle cx="10.8" cy="10.8" r="7.2"/><path d="m16.2 16.2 4.3 4.3"/>',
        'account' => '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>',
        'back' => '<path d="m15 18-6-6 6-6"/>',
        'play' => '<path d="m8 5 11 7-11 7z" fill="currentColor" stroke="none"/>',
        'close' => '<path d="m18 6-12 12M6 6l12 12"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'refresh' => '<path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/>',
        'lock' => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path>',
        'vip' => '<path d="M12 2l3 5 5 1-3 5 1 5-6-2-6 2 1-5-3-5 5-1z" fill="currentColor" stroke="none"/>',
        'popcorn' => '<path d="M12 2C9 2 7 4 7 7h10c0-3-2-5-5-5zm-5 5a3 3 0 0 0-3 3v1h16v-1a3 3 0 0 0-3-3H7zm-3 5v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8H4z"/>',
        'cinema' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M7 4v16M17 4v16M2 9h5M2 15h5M17 9h5M17 15h5"/>'
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$paths[$name].'</svg>';
}
function card_html(array $item): string {
    $sid = h($item['series_id'] ?? '');
    $name = h($item['name'] ?? 'Sem título');
    $cover = h(cover_url($item['cover'] ?? ''));
    $genre = h($item['genre'] ?? 'Reel');
    $cat = h($item['category_id'] ?? '');
    return '<a class="reel-card" href="?page=player&id='.$sid.'" data-name="'.$name.'" data-category="'.$cat.'"><div class="poster skeleton"><img loading="lazy" src="'.$cover.'" alt="'.$name.'" onload="this.parentElement.classList.remove(\'skeleton\');this.style.opacity=1" onerror="this.style.opacity=.12;this.parentElement.classList.remove(\'skeleton\')"><span class="poster-play">'.icon('play').'</span></div><div class="reel-name">'.$name.'</div><div class="reel-meta">'.$genre.'</div></a>';
}
$carouselItems = $items;
shuffle($carouselItems);
$carouselItems = array_slice($carouselItems, 0, 5);
?>
<!doctype html>
<html lang="pt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#08090d">
<meta name="robots" content="noindex, nofollow, noarchive">
<title>Short Play</title>
<link rel="icon" type="image/svg+xml" href="/icon.svg">
<style>
:root{color-scheme:dark;--bg:#07080c;--line:rgba(255,255,255,.09);--muted:#9b9eaa;--blue:#2452f0;--white:#f7f8fc;--gold:#ffb800;--purple:#6a3cff}
*{box-sizing:border-box}html,body{margin:0;padding:0;background:var(--bg);color:var(--white);font:14px/1.45 Inter,system-ui,-apple-system,"Segoe UI",sans-serif;min-height:100dvh}body{background:radial-gradient(ellipse at 50% -18%,#1b2440 0,transparent 45%),var(--bg)}button,input,select{font:inherit}button{color:inherit;border:0;cursor:pointer}a{color:inherit;text-decoration:none}
@keyframes fadeUp{from{opacity:0;transform:translateY(15px)}to{opacity:1;transform:translateY(0)}}.fade-up{animation:fadeUp .5s ease forwards}
.skeleton{position:relative;overflow:hidden;background-color:#171922!important}.skeleton::after{content:"";position:absolute;top:0;left:-100%;width:100%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.08),transparent);animation:shimmer 1.5s infinite}@keyframes shimmer{100%{left:100%}}

/* LOGIN */
.login-page{min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:24px;position:relative;overflow:hidden}
.login-bg{position:absolute;inset:0;z-index:0;overflow:hidden}
.login-bg::before{content:"";position:absolute;top:-30%;left:-30%;width:120%;height:120%;background:radial-gradient(circle at 30% 20%,rgba(36,82,240,.35),transparent 50%),radial-gradient(circle at 80% 80%,rgba(106,60,255,.25),transparent 50%);animation:bgMove 20s ease-in-out infinite alternate}
@keyframes bgMove{from{transform:rotate(0deg) scale(1)}to{transform:rotate(8deg) scale(1.05)}}
.login-card{position:relative;z-index:2;width:100%;max-width:400px;background:rgba(16,17,22,.75);backdrop-filter:blur(24px);border:1px solid rgba(255,255,255,.1);border-radius:28px;padding:32px 26px;box-shadow:0 20px 60px rgba(0,0,0,.7)}
.login-logo{text-align:center;margin-bottom:22px}.login-logo svg{width:64px;height:64px;color:var(--gold);filter:drop-shadow(0 4px 15px rgba(255,184,0,.4))}
.login-card h1{text-align:center;font-size:26px;font-weight:900;letter-spacing:-1px;margin:10px 0 4px}
.login-card .tagline{text-align:center;color:var(--muted);font-size:12px;margin-bottom:24px}
.auth-tabs{display:flex;background:rgba(255,255,255,.05);border-radius:14px;padding:4px;margin-bottom:22px;border:1px solid rgba(255,255,255,.08)}
.auth-tab{flex:1;text-align:center;padding:11px;border-radius:11px;font-size:13px;font-weight:700;color:#a5a8b2;transition:.25s;cursor:pointer}
.auth-tab.active{background:linear-gradient(135deg,var(--blue),var(--purple));color:white;box-shadow:0 4px 15px rgba(36,82,240,.35)}
.login-form{display:none;flex-direction:column;gap:14px}.login-form.active{display:flex}
.input-group{display:flex;flex-direction:column;gap:6px}
.input-group label{font-size:10px;font-weight:700;color:#a5a8b2;text-transform:uppercase;letter-spacing:1px}
.input-group input,.input-group select{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:13px 15px;color:var(--white);outline:none;transition:.2s;font-size:14px;width:100%}
.input-group input:focus,.input-group select:focus{border-color:var(--blue);background:rgba(36,82,240,.08);box-shadow:0 0 0 3px rgba(36,82,240,.15)}
.flag-select{display:flex;align-items:center;gap:8px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:0 10px}
.flag-select select{background:transparent;border:0;padding:13px 5px;width:100%}
.flag-select img{width:20px;height:14px;border-radius:2px}
.btn-primary{background:linear-gradient(135deg,var(--blue),var(--purple));color:white;font-weight:800;padding:15px;border-radius:12px;margin-top:8px;font-size:15px;transition:.2s;width:100%;box-shadow:0 4px 20px rgba(36,82,240,.4)}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 25px rgba(36,82,240,.5)}

/* APP */
.app{max-width:560px;margin:auto;min-height:100dvh;position:relative;padding-bottom:calc(92px + env(safe-area-inset-bottom))}
.topbar{height:60px;display:flex;align-items:center;justify-content:space-between;padding:0 17px;position:sticky;top:0;z-index:12;background:rgba(8,9,13,.76);backdrop-filter:blur(24px);border-bottom:1px solid var(--line)}
.brand{font-size:20px;font-weight:900;letter-spacing:-.7px;display:flex;align-items:center;gap:6px}.brand svg{width:24px;height:24px;color:var(--gold)}.brand span{color:#91a6ff}
.top-action{width:38px;height:38px;border-radius:13px;background:rgba(255,255,255,.075);display:grid;place-items:center;transition:.2s}.top-action:hover{background:rgba(255,255,255,.15)}.top-action svg{width:21px;height:21px}
main{padding:14px 15px 24px}
.hero-carousel{height:220px;position:relative;overflow:hidden;border-radius:22px;border:1px solid rgba(255,255,255,.1);background:#0a0b10;box-shadow:0 20px 50px rgba(0,0,0,.6);margin-bottom:24px}
.hero-slide{position:absolute;inset:0;opacity:0;pointer-events:none;transition:opacity .7s ease}.hero-slide.active{opacity:1;pointer-events:auto}
.hero-slide img{width:100%;height:100%;object-fit:cover;filter:brightness(.6)}.hero-slide:after{content:"";position:absolute;inset:0;background:linear-gradient(90deg,rgba(0,0,0,.85),rgba(0,0,0,.2) 85%),linear-gradient(0deg,rgba(7,8,12,.9),transparent 65%)}
.hero-copy{position:absolute;z-index:2;left:18px;right:20px;bottom:20px}
.hero-kicker{font-size:9px;font-weight:900;letter-spacing:1.7px;color:#aebcff;text-transform:uppercase}
.hero-copy h2{font-size:22px;line-height:1.15;margin:5px 0 12px;max-width:85%;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;font-weight:850}
.hero-cta{display:inline-flex;align-items:center;gap:7px;background:linear-gradient(135deg,var(--blue),var(--purple));padding:10px 18px;border-radius:12px;font-size:11px;font-weight:800;box-shadow:0 6px 20px rgba(36,82,240,.5);border:1px solid rgba(255,255,255,.2)}
.hero-cta svg{width:14px;height:14px;fill:white;stroke:none}
.hero-dots{position:absolute;z-index:3;right:14px;top:14px;display:flex;gap:5px}.hero-dot{width:6px;height:6px;border-radius:10px;background:#ffffff50;transition:.3s}.hero-dot.active{width:18px;background:white}
.section{margin:20px 0 23px}.section-head{display:flex;align-items:center;justify-content:space-between;margin:0 2px 11px}.section-head h2{font-size:16px;margin:0;font-weight:850;letter-spacing:-.3px}.section-head a{color:#969baa;font-size:11px}
.reel-rail{display:flex;gap:10px;overflow-x:auto;overflow-y:hidden;padding:1px 1px 7px;scroll-snap-type:x mandatory;scrollbar-width:none}.reel-rail::-webkit-scrollbar{display:none}.reel-rail .reel-card{flex:0 0 112px;scroll-snap-align:start}
.reel-card{min-width:0;display:block;transition:transform .2s}.reel-card:hover{transform:scale(1.02)}
.poster{aspect-ratio:3/4.15;border-radius:12px;overflow:hidden;position:relative;background:linear-gradient(135deg,#242938,#11131a);border:1px solid rgba(255,255,255,.09);box-shadow:0 7px 18px #0005}
.poster img{width:100%;height:100%;object-fit:cover;display:block;opacity:0;transition:opacity .4s}
.poster:after{content:"";position:absolute;inset:48% 0 0;background:linear-gradient(transparent,rgba(0,0,0,.3))}
.poster-play{position:absolute;z-index:2;right:7px;bottom:7px;width:27px;height:27px;border-radius:50%;display:grid;place-items:center;background:rgba(0,0,0,.56);backdrop-filter:blur(8px)}.poster-play svg{width:14px;height:14px;fill:white;stroke:none}
.reel-name{font-size:11px;font-weight:760;line-height:1.25;margin:7px 1px 3px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:27px}
.reel-meta{font-size:9px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-left:1px}
.searchbox{display:flex;align-items:center;gap:10px;border:1px solid var(--line);background:rgba(255,255,255,.07);border-radius:15px;padding:0 14px;height:48px;margin:8px 0 17px}.searchbox svg{width:19px;height:19px;stroke:#aab0c0}.searchbox input{background:none;border:0;outline:0;color:white;width:100%;font-size:14px}
.search-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:15px 9px}
.floating-nav{position:fixed;z-index:25;bottom:calc(12px + env(safe-area-inset-bottom));left:50%;transform:translateX(-50%);width:min(280px,calc(100% - 42px));height:60px;padding:7px 10px;display:grid;grid-template-columns:repeat(4,1fr);align-items:center;background:rgba(28,30,39,.85);border:1px solid rgba(255,255,255,.15);border-radius:22px;box-shadow:0 12px 36px #0009,inset 0 1px rgba(255,255,255,.1);backdrop-filter:blur(26px)}
.nav-item{height:44px;width:100%;display:grid;place-items:center;color:#858b9a;border-radius:15px;position:relative;transition:.2s}.nav-item svg{width:22px;height:22px}.nav-item.active{color:#fff;background:rgba(66,100,255,.24)}.nav-item.active:after{content:"";position:absolute;bottom:1px;width:4px;height:4px;border-radius:50%;background:#8da5ff}

/* PLAYER */
.player-app{max-width:560px;margin:auto;min-height:100dvh;background:#000;position:relative;padding-bottom:0;overflow:hidden}
.player-page{height:100dvh;min-height:500px;position:relative;background:#000}
.player-stage{position:absolute;inset:0;background:#000;display:flex;align-items:center;justify-content:center}
.player-stage video{width:100%;height:100%;object-fit:contain;background:#000}
.player-loader{position:absolute;z-index:3;inset:0;display:grid;place-items:center;background:rgba(0,0,0,.4);pointer-events:none;opacity:0;transition:opacity .3s}.player-loader.active{opacity:1}
.spinner{width:40px;height:40px;border:3px solid rgba(255,255,255,.2);border-top-color:#fff;border-radius:50%;animation:spin 1s linear infinite}@keyframes spin{100%{transform:rotate(360deg)}}
.player-top{position:absolute;z-index:5;top:0;left:0;right:0;padding:calc(13px + env(safe-area-inset-top)) 17px 45px;display:flex;align-items:center;gap:12px;background:linear-gradient(rgba(0,0,0,.52),transparent);transition:opacity .3s}
.back-btn{width:35px;height:35px;display:grid;place-items:center;background:rgba(0,0,0,.25);border-radius:50%;backdrop-filter:blur(5px)}.back-btn svg{width:24px;height:24px}
.ep-label{font-size:15px;font-weight:650;text-shadow:0 1px 6px #000}.player-top .spacer{flex:1}
.btn-refresh{width:38px;height:38px;border-radius:50%;background:rgba(0,0,0,.35);display:grid;place-items:center;backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,.15);transition:transform .3s ease,background .3s}.btn-refresh:hover{background:rgba(255,255,255,.2);transform:rotate(45deg)}.btn-refresh:active{transform:scale(.9)}.btn-refresh svg{width:20px;height:20px;color:#fff}
.video-overlay{position:absolute;inset:0;display:grid;place-items:center;pointer-events:none;transition:opacity .3s}
.big-play{width:82px;height:82px;border-radius:50%;display:grid;place-items:center;background:rgba(20,20,20,.65);backdrop-filter:blur(10px);pointer-events:auto;border:1px solid rgba(255,255,255,.1);transition:transform .2s}.big-play:hover{transform:scale(1.05)}.big-play:active{transform:scale(.95)}.big-play svg{width:37px;height:37px;fill:white;stroke:none;margin-left:5px}
.video-bottom{position:absolute;z-index:4;left:0;right:0;bottom:0;padding:50px 15px calc(18px + env(safe-area-inset-bottom));background:linear-gradient(transparent,rgba(0,0,0,.8));display:flex;align-items:center;gap:11px;color:#eee;font-size:11px;transition:opacity .3s}
.video-bottom input[type=range]{flex:1;min-width:0;-webkit-appearance:none;background:rgba(255,255,255,.3);height:4px;border-radius:2px;outline:none;cursor:pointer}
.video-bottom input[type=range]::-webkit-slider-thumb{-webkit-appearance:none;width:12px;height:12px;border-radius:50%;background:#fff;box-shadow:0 0 4px rgba(0,0,0,.5)}
.video-bottom input[type=range]::-moz-range-thumb{width:12px;height:12px;border-radius:50%;background:#fff;border:none}
.video-bottom button{background:none;padding:5px;display:grid;place-items:center;transition:transform .2s}.video-bottom button:active{transform:scale(.9)}
.video-bottom button svg{width:24px;height:24px;fill:none;stroke:white;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.video-bottom .time{white-space:nowrap;font-variant-numeric:tabular-nums;font-weight:500}
.player-page.hide-ui .player-top,.player-page.hide-ui .video-bottom{opacity:0;pointer-events:none}

.badge-vip{background:var(--gold);color:#000;font-size:10px;font-weight:900;padding:2px 8px;border-radius:10px;text-transform:uppercase;letter-spacing:1px;display:inline-flex;align-items:center;gap:4px}
.badge-vip svg{width:12px;height:12px;fill:currentColor;stroke:none}
.modal-overlay{position:fixed;z-index:100;inset:0;background:rgba(0,0,0,.8);backdrop-filter:blur(5px);display:none;align-items:flex-end;justify-content:center}.modal-overlay.open{display:flex}
.modal-content{background:#101116;border:1px solid rgba(255,255,255,.1);width:min(100%,560px);border-radius:25px 25px 0 0;padding:25px 20px calc(20px + env(safe-area-inset-bottom));box-shadow:0 -20px 60px rgba(0,0,0,.8);animation:slideUp .35s ease forwards}
@keyframes slideUp{from{transform:translateY(100%)}to{transform:translateY(0)}}
.modal-header{text-align:center;margin-bottom:20px;position:relative}
.modal-header h2{font-size:22px;font-weight:900;margin:0 0 5px 0;color:var(--gold);display:flex;align-items:center;justify-content:center;gap:6px}.modal-header h2 svg{width:24px;height:24px}
.modal-header p{font-size:13px;color:#a5a8b2;margin:0}
.modal-close{position:absolute;right:0;top:0;background:rgba(255,255,255,.1);width:32px;height:32px;border-radius:50%;display:grid;place-items:center;color:white}
.plans{display:flex;flex-direction:column;gap:12px;margin-bottom:20px}
.plan{border:1px solid rgba(255,255,255,.1);border-radius:16px;padding:16px;display:flex;justify-content:space-between;align-items:center;background:linear-gradient(135deg,rgba(255,255,255,.05),rgba(255,255,255,.01));cursor:pointer;transition:.2s;position:relative;overflow:hidden}
.plan:hover{border-color:var(--gold);background:rgba(255,184,0,.05)}.plan-popular{border-color:var(--gold)}
.plan-popular::before{content:"MAIS POPULAR";position:absolute;top:0;right:0;background:var(--gold);color:#000;font-size:8px;font-weight:900;padding:4px 10px;border-bottom-left-radius:10px}
.plan-info h3{font-size:16px;margin:0 0 4px 0;color:#fff}.plan-info p{font-size:11px;color:#a5a8b2;margin:0}
.plan-price{text-align:right}.plan-price .mt{font-size:16px;font-weight:800;color:var(--gold)}.plan-price .brl{font-size:11px;color:#a5a8b2}
.btn-buy{background:#25D366;color:white;font-weight:800;width:100%;padding:15px;border-radius:14px;font-size:15px;display:flex;justify-content:center;align-items:center;gap:8px;text-decoration:none;box-shadow:0 4px 15px rgba(37,211,102,.3)}
.btn-buy svg{width:20px;height:20px;fill:currentColor}

.sheet{position:fixed;z-index:40;inset:0;background:rgba(0,0,0,.6);display:none;align-items:flex-end;justify-content:center;backdrop-filter:blur(4px)}.sheet.open{display:flex}
.sheet-panel{width:min(100%,560px);max-height:78dvh;overflow:auto;background:rgba(16,17,22,.98);border:1px solid rgba(255,255,255,.1);border-bottom:0;border-radius:25px 25px 0 0;padding:10px 15px calc(19px + env(safe-area-inset-bottom));box-shadow:0 -18px 55px #0009}
.sheet-handle{height:4px;width:43px;border-radius:9px;background:#393939;margin:0 auto 14px}
.sheet-head{display:flex;gap:13px;align-items:flex-start;margin-bottom:20px}
.sheet-cover{width:88px;height:122px;object-fit:cover;border-radius:10px;background:#171922;flex:none}
.sheet-copy{min-width:0;flex:1;padding-top:3px}
.sheet-copy h3{font-size:17px;line-height:1.2;margin:0 0 8px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.sheet-copy p{font-size:12px;color:#a5a8b2;line-height:1.48;margin:0;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.sheet-close{width:29px;height:29px;background:none;display:grid;place-items:center;flex:none;color:white}.sheet-close svg{width:24px;height:24px}
.episodes-title{font-size:15px;font-weight:850;margin:10px 0 14px}
.episode-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:9px}
.episode{aspect-ratio:1;border-radius:14px;background:#111214;border:1px solid rgba(255,255,255,.025);font-weight:800;font-size:14px;transition:.2s;position:relative;display:flex;align-items:center;justify-content:center;color:white}
.episode.active{background:#204be7;border:2px solid #5478ff;box-shadow:0 0 0 2px #214be555}
.episode.locked{background:rgba(255,184,0,.1);color:var(--gold);border:1px solid rgba(255,184,0,.3)}
.episode.locked svg{width:16px;height:16px;position:absolute;top:4px;right:4px;fill:currentColor}
.toast{position:fixed;z-index:120;left:50%;bottom:24px;transform:translateX(-50%);background:#252833;border:1px solid var(--line);border-radius:12px;padding:10px 14px;font-size:12px;display:none;max-width:90%;text-align:center;color:white}
@media(min-width:520px){main{padding-left:20px;padding-right:20px}.reel-rail .reel-card{flex-basis:126px}.hero-carousel{height:250px}}
</style>
</head>
<body>

<?php if ($page === 'login'): ?>
<div class="login-page">
    <div class="login-bg"></div>
    <div class="login-card fade-up">
        <div class="login-logo"><?=icon('popcorn')?></div>
        <h1>Short Play</h1>
        <p class="tagline">Reels, histórias e emoções em episódios curtos.</p>
        <div class="auth-tabs">
            <div class="auth-tab active" onclick="switchTab('login')">Entrar</div>
            <div class="auth-tab" onclick="switchTab('register')">Criar Conta</div>
        </div>
        <form class="login-form active" id="formLogin">
            <input type="hidden" name="action" value="login">
            <div class="input-group"><label>Número de Telefone</label><input type="tel" name="phone" placeholder="Ex: +258840000000" required></div>
            <div class="input-group"><label>Senha</label><input type="password" name="password" placeholder="••••••••" required></div>
            <button type="submit" class="btn-primary">Entrar</button>
        </form>
        <form class="login-form" id="formRegister">
            <input type="hidden" name="action" value="register">
            <div class="input-group"><label>Nome Completo</label><input type="text" name="name" placeholder="Ex: João Silva" required></div>
            <div class="input-group">
                <label>País</label>
                <div class="flag-select">
                    <img id="selectedFlag" src="https://flagcdn.com/w20/mz.png" alt="MZ">
                    <select id="countrySelect" onchange="updateFlag(this)">
                        <option value="+258" data-flag="mz" selected>+258 (Moçambique)</option>
                        <option value="+55" data-flag="br">+55 (Brasil)</option>
                        <option value="+351" data-flag="pt">+351 (Portugal)</option>
                        <option value="+244" data-flag="ao">+244 (Angola)</option>
                        <option value="+1" data-flag="us">+1 (EUA)</option>
                    </select>
                </div>
            </div>
            <div class="input-group"><label>Número de Telefone</label><input type="tel" name="phone_raw" placeholder="840000000" required></div>
            <div class="input-group"><label>Senha</label><input type="password" name="password" placeholder="••••••••" required></div>
            <button type="submit" class="btn-primary">Criar Conta</button>
        </form>
    </div>
</div>

<?php elseif ($page === 'account'): ?>
<div class="app">
<header class="topbar"><div class="brand"><?=icon('popcorn')?> Short <span>Play</span></div></header>
<main>
    <div style="text-align:center;padding-top:10px;" class="fade-up">
        <div style="position:relative;width:90px;height:90px;margin:0 auto 15px auto;">
            <img src="<?=h($user_avatar)?>" style="width:100%;height:100%;border-radius:50%;background:#1c1e26;object-fit:cover;border:2px solid var(--line);">
            <?php if ($is_vip): ?>
                <div style="position:absolute;bottom:-5px;right:-5px;background:var(--gold);width:30px;height:30px;border-radius:50%;display:grid;place-items:center;border:3px solid #07080c;"><?=icon('vip')?></div>
            <?php endif; ?>
        </div>
        <h1 style="font-size:22px;margin-bottom:5px;"><?=h($user_name)?></h1>
        <p style="font-size:14px;color:var(--muted);"><?=h($user_phone)?></p>
        <div style="margin-top:15px;display:flex;gap:10px;justify-content:center;">
            <?php if ($is_vip): ?>
                <span class="badge-vip"><?=icon('vip')?> Utilizador VIP</span>
            <?php else: ?>
                <span style="background:rgba(255,255,255,.1);padding:4px 10px;border-radius:10px;font-size:11px;font-weight:700;">Plano Gratuito</span>
                <button onclick="openVIP()" style="background:var(--gold);color:#000;font-size:11px;font-weight:800;padding:4px 10px;border-radius:10px;border:none;cursor:pointer;">Tornar-se VIP</button>
            <?php endif; ?>
        </div>
    </div>
    <div style="background:rgba(255,255,255,.05);border-radius:16px;padding:20px;margin-top:25px;border:1px solid var(--line);" class="fade-up">
        <h3 style="margin:0 0 15px 0;font-size:15px;">Editar Perfil</h3>
        <form id="editProfileForm" style="display:flex;flex-direction:column;gap:12px;">
            <input type="hidden" name="action" value="edit_profile">
            <div class="input-group"><label>Nome</label><input type="text" name="name" value="<?=h($user_name)?>" required></div>
            <div class="input-group"><label>Novo Avatar (URL)</label><input type="url" name="avatar" value="<?=h($user_avatar)?>" placeholder="https://..."></div>
            <div class="input-group"><label>Nova Senha (deixe em branco para manter)</label><input type="password" name="password" placeholder="••••••••"></div>
            <button type="submit" class="btn-primary" style="margin-top:5px;">Salvar Alterações</button>
        </form>
    </div>
    <div style="margin-top:20px;">
        <a href="?page=logout" style="display:block;text-align:center;padding:15px;background:rgba(255,0,0,.1);color:#ff4b4b;border-radius:12px;font-weight:700;text-decoration:none;">Terminar Sessão</a>
    </div>
</main>
<nav class="floating-nav">
    <a class="nav-item" href="?page=home"><?=icon('home')?></a>
    <a class="nav-item" href="?page=history"><?=icon('history')?></a>
    <a class="nav-item" href="?page=search"><?=icon('search')?></a>
    <a class="nav-item active" href="?page=account"><?=icon('account')?></a>
</nav>
</div>

<?php elseif ($page === 'player'): ?>
<div class="player-app">
<section class="player-page" id="playerPage">
    <div class="player-stage" id="playerStage">
        <video id="video" playsinline webkit-playsinline preload="metadata"></video>
        <div class="player-loader" id="playerLoader"><div class="spinner"></div></div>
        <div class="video-overlay" id="videoOverlay" style="display:none;"><button class="big-play" onclick="startPlayback(event)" aria-label="Reproduzir"><?=icon('play')?></button></div>
        <div class="video-bottom">
            <span class="time" id="currentTime">0:00</span>
            <input id="seek" type="range" min="0" max="100" value="0" aria-label="Progresso">
            <span class="time" id="duration">0:00</span>
            <button onclick="openEpisodes(event)" aria-label="Lista de episódios"><?=icon('grid')?></button>
        </div>
    </div>
    <div class="player-top">
        <a class="back-btn" href="?page=home" aria-label="Voltar"><?=icon('back')?></a>
        <span class="ep-label" id="epLabel">Ep 1</span>
        <span class="spacer"></span>
        <button class="btn-refresh" onclick="location.reload()" aria-label="Actualizar"><?=icon('refresh')?></button>
    </div>
</section>
</div>
<div class="sheet" id="episodeSheet" onclick="if(event.target===this)closeEpisodes()">
    <div class="sheet-panel">
        <div class="sheet-handle"></div>
        <div class="sheet-head">
            <img class="sheet-cover skeleton" id="detailCover" src="<?=h(cover_url($selected['cover'] ?? ''))?>" alt="" onload="this.classList.remove('skeleton')">
            <div class="sheet-copy">
                <h3 id="detailTitle" class="skeleton sk-text" style="height:20px;width:70%;margin-top:4px;">&nbsp;</h3>
                <p id="detailPlot" class="skeleton sk-text" style="height:40px;">&nbsp;</p>
            </div>
            <button class="sheet-close" onclick="closeEpisodes()" aria-label="Fechar"><?=icon('close')?></button>
        </div>
        <div class="episodes-title">Episódios</div>
        <div class="episode-grid" id="episodeGrid">
            <div class="skeleton" style="aspect-ratio:1;border-radius:14px;"></div>
            <div class="skeleton" style="aspect-ratio:1;border-radius:14px;"></div>
            <div class="skeleton" style="aspect-ratio:1;border-radius:14px;"></div>
            <div class="skeleton" style="aspect-ratio:1;border-radius:14px;"></div>
            <div class="skeleton" style="aspect-ratio:1;border-radius:14px;"></div>
            <div class="skeleton" style="aspect-ratio:1;border-radius:14px;"></div>
        </div>
    </div>
</div>

<?php else: ?>
<div class="app">
<header class="topbar">
    <div class="brand"><?=icon('cinema')?> Short <span>Play</span></div>
    <?php if (!$is_vip): ?>
        <button class="top-action" onclick="openVIP()" style="color:var(--gold);background:rgba(255,184,0,.1);border:1px solid rgba(255,184,0,.3);"><?=icon('vip')?></button>
    <?php else: ?>
        <a class="top-action" href="?page=search" aria-label="Pesquisar"><?=icon('search')?></a>
    <?php endif; ?>
</header>
<?php if ($page === 'history'): ?>
<main>
    <div class="welcome"><div class="eyebrow">A tua biblioteca</div><h1>Histórico</h1><p>Continua de onde paraste.</p></div>
    <div class="search-grid" id="historyGrid"></div>
    <div class="empty" id="historyEmpty" style="display:none">Ainda não tens reels no histórico.</div>
</main>
<?php elseif ($page === 'search'): ?>
<main>
    <div class="welcome"><div class="eyebrow">Descobrir</div><h1>Pesquisa</h1><p>Encontra a tua próxima história.</p></div>
    <label class="searchbox"><?=icon('search')?><input id="searchInput" type="search" placeholder="Pesquisar reels…" autocomplete="off"></label>
    <div class="search-grid" id="searchGrid">
    <?php foreach ($items as $item) echo card_html($item); ?>
    </div>
    <div class="empty" id="searchEmpty" style="display:none">Nenhum resultado encontrado.</div>
</main>
<?php else: ?>
<main>
    <section class="section" style="margin-top:0;">
        <div class="hero-carousel skeleton" id="heroCarousel">
            <?php foreach ($carouselItems as $i => $item): ?>
            <a class="hero-slide <?=$i===0?'active':''?>" href="?page=player&id=<?=h($item['series_id'] ?? '')?>">
                <img src="<?=h(cover_url($item['cover'] ?? ''))?>" alt="" loading="<?=$i===0?'eager':'lazy'?>" onload="this.parentElement.parentElement.classList.remove('skeleton')">
                <div class="hero-copy"><div class="hero-kicker">Destaque Short Play</div><h2><?=h($item['name'] ?? 'História')?></h2><span class="hero-cta"><?=icon('play')?> Assistir agora</span></div>
            </a>
            <?php endforeach; ?>
            <div class="hero-dots"><?php foreach ($carouselItems as $i => $_): ?><span class="hero-dot <?=$i===0?'active':''?>"></span><?php endforeach; ?></div>
        </div>
    </section>
    <?php foreach ($categories as $categoryId => $label):
        $filtered = array_values(array_filter($items, static fn($item) => (string)($item['category_id'] ?? '') === (string)$categoryId));
        if (!$filtered) continue;
    ?>
    <section class="section">
        <div class="section-head"><h2><?=h($label)?></h2><a href="?page=search&category=<?=h($categoryId)?>">Ver todos　›</a></div>
        <div class="reel-rail">
        <?php foreach (array_slice($filtered, 0, 30) as $item) echo card_html($item); ?>
        </div>
    </section>
    <?php endforeach; ?>
</main>
<?php endif; ?>
<nav class="floating-nav" aria-label="Navegação principal">
    <a class="nav-item <?=$page==='home'?'active':''?>" href="?page=home"><?=icon('home')?></a>
    <a class="nav-item <?=$page==='history'?'active':''?>" href="?page=history"><?=icon('history')?></a>
    <a class="nav-item <?=$page==='search'?'active':''?>" href="?page=search"><?=icon('search')?></a>
    <a class="nav-item <?=$page==='account'?'active':''?>" href="?page=account"><?=icon('account')?></a>
</nav>
</div>
<?php endif; ?>

<div class="modal-overlay" id="vipModal">
    <div class="modal-content">
        <div class="modal-header">
            <button class="modal-close" onclick="closeVIP()"><?=icon('close')?></button>
            <h2><?=icon('vip')?> Torna-te VIP</h2>
            <p>Desbloqueia todos os episódios e séries sem limites.</p>
        </div>
        <div class="plans">
            <div class="plan" onclick="buyVIP('7 dias - 40 MT / 4 R$')"><div class="plan-info"><h3>7 Dias</h3><p>Acesso semanal</p></div><div class="plan-price"><div class="mt">40 MT</div><div class="brl">4 R$</div></div></div>
            <div class="plan" onclick="buyVIP('15 dias - 70 MT / 7 R$')"><div class="plan-info"><h3>15 Dias</h3><p>Acesso quinzenal</p></div><div class="plan-price"><div class="mt">70 MT</div><div class="brl">7 R$</div></div></div>
            <div class="plan plan-popular" onclick="buyVIP('30 dias - 130 MT / 11 R$')"><div class="plan-info"><h3>30 Dias</h3><p>Acesso mensal completo</p></div><div class="plan-price"><div class="mt">130 MT</div><div class="brl">11 R$</div></div></div>
        </div>
        <a href="#" id="whatsappBtn" target="_blank" class="btn-buy">
            <svg viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
            Comprar via WhatsApp
        </a>
    </div>
</div>

<div class="toast" id="toast"></div>
<script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js"></script>
<script>
const SERIES_ID = <?=json_encode($id)?>;
const PAGE = <?=json_encode($page)?>;
const CSRF_TOKEN = <?=json_encode($csrf_token)?>;
const USER_PHONE = <?=json_encode($user_phone)?>;
const USER_HISTORY = <?=json_encode($user_history_ids)?>;
const IS_VIP = <?=$is_vip ? 'true' : 'false'?>;
const CATALOG = <?=json_encode(array_map(fn($i) => ['id'=>(string)($i['series_id']??''),'name'=>(string)($i['name']??''),'cover'=>cover_url($i['cover']??''),'genre'=>(string)($i['genre']??''),'category'=>(string)($i['category_id']??'')], $items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>;
const $ = s => document.querySelector(s);
function esc(v) { return String(v||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function toast(m) { const e = $('#toast'); if (!e) return; e.textContent = m; e.style.display = 'block'; clearTimeout(window.__t); window.__t = setTimeout(() => e.style.display='none', 2400); }
function switchTab(tab) {
    document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.login-form').forEach(f => f.classList.remove('active'));
    if (tab === 'login') { document.querySelectorAll('.auth-tab')[0].classList.add('active'); $('#formLogin').classList.add('active'); }
    else { document.querySelectorAll('.auth-tab')[1].classList.add('active'); $('#formRegister').classList.add('active'); }
}
function updateFlag(select) {
    const flag = select.options[select.selectedIndex].getAttribute('data-flag');
    $('#selectedFlag').src = `https://flagcdn.com/w20/${flag}.png`;
}
const formLogin = $('#formLogin');
if (formLogin) formLogin.addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(formLogin);
    try {
        const res = await fetch('', { method: 'POST', body: fd, headers: {'X-CineVS-Token': CSRF_TOKEN} });
        const data = await res.json();
        if (data.success) location.reload(); else toast(data.error || 'Erro');
    } catch { toast('Erro de conexão.'); }
});
const formRegister = $('#formRegister');
if (formRegister) formRegister.addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(formRegister);
    const country = $('#countrySelect').value;
    fd.append('phone', country + fd.get('phone_raw'));
    const name = fd.get('name');
    fd.append('avatar', `https://api.dicebear.com/7.x/avataaars/svg?seed=${encodeURIComponent(name)}`);
    try {
        const res = await fetch('', { method: 'POST', body: fd, headers: {'X-CineVS-Token': CSRF_TOKEN} });
        const data = await res.json();
        if (data.success) location.reload(); else toast(data.error || 'Erro');
    } catch { toast('Erro de conexão.'); }
});
const editProfileForm = $('#editProfileForm');
if (editProfileForm) editProfileForm.addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(editProfileForm);
    try {
        const res = await fetch('', { method: 'POST', body: fd, headers: {'X-CineVS-Token': CSRF_TOKEN} });
        const data = await res.json();
        if (data.success) location.reload(); else toast(data.error || 'Erro');
    } catch { toast('Erro de conexão.'); }
});
function openVIP() { $('#vipModal').classList.add('open'); }
function closeVIP() { $('#vipModal').classList.remove('open'); }
function buyVIP(plan) {
    const msg = encodeURIComponent(`Olá! Quero ativar o VIP *${plan}* na minha conta Short Play.\n\nMeu número é: ${USER_PHONE}`);
    document.getElementById('whatsappBtn').href = `https://wa.me/258855641710?text=${msg}`;
}
function renderHistory() {
    const grid = $('#historyGrid'); if (!grid) return;
    if (!USER_HISTORY || USER_HISTORY.length === 0) { $('#historyEmpty').style.display = 'block'; return; }
    grid.innerHTML = USER_HISTORY.map(h => {
        const item = CATALOG.find(c => c.id === h.id); if (!item) return '';
        return `<a class="reel-card" href="?page=player&id=${encodeURIComponent(item.id)}"><div class="poster skeleton"><img loading="lazy" src="${esc(item.cover)}" alt="" onload="this.parentElement.classList.remove('skeleton');this.style.opacity=1"><span class="poster-play"><svg viewBox="0 0 24 24"><path d="m8 5 11 7-11 7z" fill="white"/></svg></span></div><div class="reel-name">${esc(item.name)}</div><div class="reel-meta">Visto recentemente</div></a>`;
    }).join('');
    $('#historyEmpty').style.display = 'none';
}
if (PAGE === 'history') renderHistory();
const searchInput = $('#searchInput');
if (searchInput) {
    const categoryFilter = new URLSearchParams(location.search).get('category') || '';
    const grid = $('#searchGrid');
    const cards = [...grid.querySelectorAll('.reel-card')];
    let visibleCount = 0;
    const filterCards = () => {
        const q = searchInput.value.trim().toLocaleLowerCase('pt');
        visibleCount = 0;
        cards.forEach(card => {
            const cm = !categoryFilter || card.dataset.category === categoryFilter;
            const tm = (card.dataset.name || '').toLocaleLowerCase('pt').includes(q);
            const v = cm && tm;
            card.style.display = v ? '' : 'none';
            if (v) visibleCount++;
        });
        $('#searchEmpty').style.display = visibleCount ? 'none' : 'block';
    };
    filterCards();
    searchInput.addEventListener('input', filterCards);
}
const heroSlides = [...document.querySelectorAll('.hero-slide')];
const heroDots = [...document.querySelectorAll('.hero-dot')];
if (heroSlides.length > 1) {
    let hi = 0;
    setInterval(() => {
        heroSlides[hi].classList.remove('active'); heroDots[hi]?.classList.remove('active');
        hi = (hi + 1) % heroSlides.length;
        heroSlides[hi].classList.add('active'); heroDots[hi]?.classList.add('active');
    }, 4000);
}
let episodeData = [], currentIndex = 0, hlsInstance = null;
async function loadSeries() {
    const grid = $('#episodeGrid'); if (!grid) return;
    try {
        const apiUrl = new URL(window.location.href);
        apiUrl.search = ''; apiUrl.searchParams.set('api','1'); apiUrl.searchParams.set('id', SERIES_ID);
        const response = await fetch(apiUrl.toString(), { method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json', 'X-CineVS-Token': CSRF_TOKEN } });
        const raw = await response.text();
        let data; try { data = JSON.parse(raw); } catch { throw new Error('Erro no servidor.'); }
        if (!response.ok || data.error) throw new Error(data.error || `Erro ${response.status}.`);
        const tn = $('#detailTitle'), pn = $('#detailPlot'), cn = $('#detailCover');
        if (tn) { tn.textContent = data.name || 'Série'; tn.classList.remove('skeleton','sk-text'); tn.style.cssText=''; }
        if (pn) { pn.textContent = data.plot || 'Sem descrição.'; pn.classList.remove('skeleton','sk-text'); pn.style.cssText=''; }
        if (cn && data.cover) cn.src = data.cover;
        episodeData = Array.isArray(data.episodes) ? data.episodes : [];
        if (!episodeData.length) { grid.innerHTML = '<div class="empty">Sem episódios.</div>'; return; }
        grid.innerHTML = episodeData.map((ep, i) => {
            const locked = ep.is_locked;
            return `<button class="episode ${i===0?'active':''} ${locked?'locked':''}" onclick="playEpisode(${i})">${locked ? iconLock() : (ep.number || i+1)}</button>`;
        }).join('');
        fetch('', { method: 'POST', body: new URLSearchParams({ action: 'sync_history', id: SERIES_ID }), headers: {'X-CineVS-Token': CSRF_TOKEN} });
        playEpisode(0);
    } catch (e) { grid.innerHTML = `<div class="empty">${esc(e.message)}</div>`; toast(e.message); }
}
function iconLock() { return `<svg viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>`; }
function openEpisodes(e) { if (e) e.stopPropagation(); $('#episodeSheet')?.classList.add('open'); }
function closeEpisodes() { $('#episodeSheet')?.classList.remove('open'); }
function playEpisode(index) {
    const ep = episodeData[index]; if (!ep) return;
    if (ep.is_locked) { openVIP(); return; }
    if (!ep.stream) { toast('Fonte indisponível.'); return; }
    currentIndex = index;
    document.querySelectorAll('.episode').forEach((b, n) => b.classList.toggle('active', n === index));
    $('#epLabel').textContent = 'Ep ' + (ep.number || index + 1);
    closeEpisodes();
    const video = $('#video');
    if (hlsInstance) { hlsInstance.destroy(); hlsInstance = null; }
    video.pause(); video.removeAttribute('src'); video.load();
    $('#playerLoader').classList.add('active'); $('#videoOverlay').style.display = 'none';
    const url = ep.stream;
    if (video.canPlayType('application/vnd.apple.mpegurl')) video.src = url;
    else if (window.Hls && Hls.isSupported()) { hlsInstance = new Hls({enableWorker:true, lowLatencyMode:false, maxBufferLength:30}); hlsInstance.loadSource(url); hlsInstance.attachMedia(video); }
    else video.src = url;
    video.muted = false;
    video.play().then(() => { $('#playerLoader').classList.remove('active'); $('#videoOverlay').style.display = 'none'; })
        .catch(() => { $('#playerLoader').classList.remove('active'); video.muted = true; video.play().then(() => { $('#videoOverlay').style.display = 'none'; }).catch(() => { $('#videoOverlay').style.display = 'grid'; }); });
    history.replaceState(null, '', `?page=player&id=${SERIES_ID}&ep=${index+1}`);
}
function startPlayback(e) { if (e) e.stopPropagation(); const v = $('#video'); v.muted = false; v.play().then(() => $('#videoOverlay').style.display = 'none').catch(() => toast('Toque novamente.')); }
function formatTime(s) { if (!isFinite(s)) return '0:00'; return Math.floor(s/60) + ':' + String(Math.floor(s%60)).padStart(2,'0'); }
const videoElement = $('#video');
if (videoElement) {
    const pageElement = $('#playerPage');
    videoElement.addEventListener('click', () => { if (videoElement.paused) return; pageElement.classList.toggle('hide-ui'); });
    videoElement.addEventListener('waiting', () => $('#playerLoader').classList.add('active'));
    videoElement.addEventListener('playing', () => $('#playerLoader').classList.remove('active'));
    videoElement.addEventListener('canplay', () => $('#playerLoader').classList.remove('active'));
    videoElement.addEventListener('timeupdate', () => {
        if (!videoElement.duration) return;
        $('#seek').value = (videoElement.currentTime / videoElement.duration) * 100;
        $('#currentTime').textContent = formatTime(videoElement.currentTime);
        $('#duration').textContent = formatTime(videoElement.duration);
    });
    $('#seek').addEventListener('input', ev => { if (videoElement.duration) videoElement.currentTime = Number(ev.target.value)/100 * videoElement.duration; });
    videoElement.addEventListener('play', () => $('#videoOverlay').style.display = 'none');
    videoElement.addEventListener('pause', () => { if (!videoElement.ended) { $('#videoOverlay').style.display = 'grid'; pageElement.classList.remove('hide-ui'); } });
    videoElement.addEventListener('ended', () => {
        if (currentIndex + 1 < episodeData.length) {
            if (episodeData[currentIndex+1].is_locked) openVIP();
            else playEpisode(currentIndex + 1);
        }
    });
    loadSeries();
}
</script>
</body>
</html>
<?php
/**
 * PlayMoz — Site completo de filmes num único arquivo PHP
 * Baseado na API Xtream do mgeb.space (com paginação para evitar timeout)
 */

/* ============================================================
 *  CONFIGURAÇÃO — credenciais (só PHP conhece)
 * ============================================================ */
define('MGEB_DOMAIN', 'https://mgeb.space');
define('MGEB_USER',   'Feljo');
define('MGEB_PASS',   '22109f3c5de3aa6c');
define('PAGE_SIZE',   50);          // paginação (evita timeout)
define('CACHE_TTL',   7200);        // 2h
define('CACHE_DIR',   sys_get_temp_dir() . '/playmoz_cache');

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@set_time_limit(120);

/* ============================================================
 *  HELPERS — API + CACHE
 * ============================================================ */
function mgeb_api(string $action = '', array $params = []): ?array {
    if (!function_exists('curl_init')) return null;
    $q = http_build_query(array_merge([
        'username' => MGEB_USER,
        'password' => MGEB_PASS,
    ], $params));
    if ($action !== '') $q = 'action=' . rawurlencode($action) . '&' . $q;
    $url = MGEB_DOMAIN . '/player_api.php?' . $q;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
        CURLOPT_REFERER        => MGEB_DOMAIN . '/',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING       => '',
    ]);
    $raw    = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($raw) || $status < 200 || $status >= 300 || $raw === '') return null;
    $arr = json_decode($raw, true);
    return is_array($arr) ? $arr : null;
}

function cache_get(string $key, int $ttl = CACHE_TTL): ?array {
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0700, true);
    $f = CACHE_DIR . '/' . preg_replace('/[^a-z0-9_]/', '', $key) . '.json';
    if (is_file($f) && (filemtime($f) + $ttl) > time()) {
        $d = json_decode((string)@file_get_contents($f), true);
        if (is_array($d)) return $d;
    }
    return null;
}
function cache_put(string $key, array $data): void {
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0700, true);
    $f = CACHE_DIR . '/' . preg_replace('/[^a-z0-9_]/', '', $key) . '.json';
    @file_put_contents($f, json_encode($data), LOCK_EX);
}

/** Baixa 1 página de filmes (limit/offset) com cache. */
function filmes_pagina(int $pagina): array {
    $key = 'filmes_p' . $pagina;
    $cached = cache_get($key);
    if ($cached !== null) return $cached;

    $lote = mgeb_api('get_vod_streams', [
        'limit'  => PAGE_SIZE,
        'offset' => ($pagina - 1) * PAGE_SIZE,
    ]);
    if (!is_array($lote)) $lote = [];
    if ($lote !== []) cache_put($key, $lote);
    return $lote;
}

/** Categorias de filmes. */
function categorias_filmes(): array {
    $cached = cache_get('categorias_vod');
    if ($cached !== null) return $cached;
    $cats = mgeb_api('get_vod_categories');
    if (!is_array($cats)) $cats = [];
    if ($cats !== []) cache_put('categorias_vod', $cats);
    return $cats;
}

/** Info detalhada de 1 filme (get_vod_info). */
function info_filme(string $streamId): ?array {
    if (!preg_match('/^\d{1,12}$/', $streamId)) return null;
    $key = 'vodinfo_' . $streamId;
    $cached = cache_get($key);
    if ($cached !== null) return $cached;
    $info = mgeb_api('get_vod_info', ['vod_id' => $streamId]);
    if (!is_array($info)) return null;
    cache_put($key, $info);
    return $info;
}

/** Token do player (sessão). */
function player_token(string $streamId, string $direct, string $container): string {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $t = bin2hex(random_bytes(16));
    $_SESSION['pl_' . $t] = [
        'stream_id' => $streamId,
        'direct'    => $direct,
        'container' => $container,
        'expires'   => time() + 1800,
    ];
    return $t;
}

/** Rota interna: ?go=<token> → 302 para mgeb (stream direto). */
if (isset($_GET['go'])) {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $t = (string)$_GET['go'];
    $k = 'pl_' . preg_replace('/[^a-f0-9]/', '', $t);
    $e = $_SESSION[$k] ?? null;
    if (!is_array($e) || ($e['expires'] ?? 0) < time() || empty($e['direct'])) {
        http_response_code(404); exit('Fonte indisponível');
    }
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Location: ' . $e['direct'], true, 302);
    exit;
}

/** Rota interna: ?embed=<token> → HTML do player (iframe). */
if (isset($_GET['embed'])) {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $t = (string)$_GET['embed'];
    $k = 'pl_' . preg_replace('/[^a-f0-9]/', '', $t);
    $e = $_SESSION[$k] ?? null;
    if (!is_array($e) || ($e['expires'] ?? 0) < time()) {
        http_response_code(404); exit('Fonte indisponível');
    }
    $container = strtolower((string)($e['container'] ?? 'm3u8'));
    $isHls = in_array($container, ['m3u8','ts','txt'], true)
          || stripos((string)$e['direct'], '.m3u8') !== false
          || stripos((string)$e['direct'], '.ts')   !== false;

    $self = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $go   = $self . '?go=' . rawurlencode($t);
    header('Content-Type: text/html; charset=utf-8');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    ?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Player</title>
<script src="https://ssl.p.jwpcdn.com/player/v/8.6.2/jwplayer.js"></script>
<script>jwplayer.key="64HPbvSQorQcd52B8XFuhMtEoitbvY/EXJmMBfKcXZQU2Rnn";</script>
<style>html,body{margin:0;padding:0;height:100%;background:#000}#p{width:100%!important;height:100%!important}</style>
</head><body><div id="p"></div>
<script>
jwplayer("p").setup({
  sources:[{ file: <?= json_encode($go, JSON_UNESCAPED_SLASHES) ?>, type: <?= json_encode($isHls ? 'hls' : 'mp4') ?>, default:"true" }],
  aspectratio:"16:9", autostart:false, primary:"html5", preload:"auto",
  playbackRateControls:[0.5,0.75,1,1.25,1.5,2], cast:{}
});
</script></body></html>
    <?php
    exit;
}

/* ============================================================
 *  ROTEAMENTO
 * ============================================================ */
$pagina   = max(1, (int)($_GET['p'] ?? 1));
$categoria = (string)($_GET['cat'] ?? '');
$busca    = trim((string)($_GET['q'] ?? ''));
$acao     = (string)($_GET['acao'] ?? 'home');

/* JSON: 1 filme (para o modal) */
if ($acao === 'filme') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (string)($_GET['id'] ?? '');
    if (!preg_match('/^\d{1,12}$/', $id)) { echo json_encode(['ok'=>false,'error'=>'id_invalido']); exit; }
    $info = info_filme($id);
    if (!$info || empty($info['movie_data'])) { echo json_encode(['ok'=>false,'error'=>'nao_encontrado']); exit; }
    $md = $info['movie_data'];
    $direct    = (string)($md['direct_source'] ?? '');
    $container = (string)($md['container_extension'] ?? 'm3u8');
    if ($direct === '') { echo json_encode(['ok'=>false,'error'=>'sem_stream']); exit; }
    $tok = player_token($id, $direct, $container);
    echo json_encode([
        'ok'        => true,
        'stream_id' => $id,
        'nome'      => (string)($info['info']['name']        ?? 'Sem título'),
        'sinopse'   => (string)($info['info']['plot']        ?? ($info['info']['description'] ?? '')),
        'poster'    => (string)($info['info']['movie_image'] ?? ($info['info']['cover_big'] ?? '')),
        'ano'       => (string)($info['info']['releasedate'] ?? ($info['info']['year'] ?? '')),
        'genero'    => (string)($info['info']['genre']       ?? ''),
        'rating'    => (string)($info['info']['rating']      ?? ''),
        'container' => $container,
        'embed'     => 'index.php?embed=' . $tok,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* JSON: lista de filmes (busca/categoria/paginação) */
if ($acao === 'lista') {
    header('Content-Type: application/json; charset=utf-8');
    $lote = filmes_pagina($pagina);

    // Filtros
    if ($categoria !== '') {
        $lote = array_filter($lote, fn($f) => (string)($f['category_id'] ?? '') === $categoria);
        $lote = array_values($lote);
    }
    if ($busca !== '') {
        $q = mb_strtolower($busca, 'UTF-8');
        $lote = array_filter($lote, function ($f) use ($q) {
            $n = mb_strtolower((string)($f['name'] ?? ''), 'UTF-8');
            return mb_strpos($n, $q) !== false;
        });
        $lote = array_values($lote);
    }

    $out = [];
    foreach ($lote as $f) {
        $out[] = [
            'stream_id' => (string)($f['stream_id'] ?? ''),
            'nome'      => (string)($f['name']      ?? ''),
            'poster'    => (string)($f['stream_icon'] ?? ''),
            'rating'    => (string)($f['rating']    ?? ''),
            'ano'       => (string)($f['year']      ?? ''),
            'cat'       => (string)($f['category_id'] ?? ''),
        ];
    }
    echo json_encode([
        'ok'     => true,
        'filmes' => $out,
        'pagina' => $pagina,
        'tem_mais' => count($lote) >= PAGE_SIZE,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* JSON: categorias */
if ($acao === 'categorias') {
    header('Content-Type: application/json; charset=utf-8');
    $cats = categorias_filmes();
    $out = [];
    foreach ($cats as $c) {
        $out[] = [
            'id'   => (string)($c['category_id']   ?? ''),
            'nome' => (string)($c['category_name'] ?? ''),
        ];
    }
    echo json_encode(['ok'=>true,'categorias'=>$out], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
 *  PÁGINA PRINCIPAL (HTML)
 * ============================================================ */
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="referrer" content="strict-origin-when-cross-origin">
<title>PlayMoz · Filmes</title>
<style>
:root{
  color-scheme:dark;
  --bg:#050609; --bg-2:#0b0e15; --card:#12151f; --card-h:#1a1e2b;
  --red:#f43f5e; --red-2:#ff3355; --text:#f1f3f7; --muted:#8b93a7;
  --line:#1e2230; --radius:14px;
}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{margin:0;padding:0;background:var(--bg);color:var(--text);
  font:15px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;overflow-x:hidden}
a{color:inherit;text-decoration:none}
button{font-family:inherit}

/* ---------- HEADER ---------- */
header{
  position:sticky;top:0;z-index:100;
  background:linear-gradient(180deg,rgba(5,6,9,.98),rgba(5,6,9,.85) 80%,transparent);
  backdrop-filter:blur(10px);
  padding:14px 22px;
  display:flex;align-items:center;gap:16px;flex-wrap:wrap;
}
.logo{display:flex;align-items:center;gap:10px;font-weight:800;font-size:20px;color:var(--red);letter-spacing:-.02em}
.logo svg{width:28px;height:28px}
.search{flex:1;min-width:220px;max-width:520px;position:relative}
.search input{
  width:100%;padding:11px 14px 11px 42px;
  background:var(--card);border:1px solid var(--line);border-radius:11px;
  color:var(--text);font-size:14px;outline:none;transition:border .2s;
}
.search input:focus{border-color:var(--red)}
.search svg{
  position:absolute;left:13px;top:50%;transform:translateY(-50%);
  width:18px;height:18px;color:var(--muted);pointer-events:none;
}

/* ---------- MAIN / CONTAINER ---------- */
main{max-width:1500px;margin:0 auto;padding:8px 22px 80px}
.section-title{
  display:flex;align-items:center;gap:10px;
  font-size:20px;font-weight:700;margin:24px 0 16px;
}
.section-title svg{width:22px;height:22px;color:var(--red)}

/* ---------- CATEGORIAS ---------- */
.cats{
  display:flex;gap:8px;overflow-x:auto;padding-bottom:6px;
  scrollbar-width:none;-ms-overflow-style:none;
}
.cats::-webkit-scrollbar{display:none}
.cat-btn{
  flex:0 0 auto;padding:8px 16px;border-radius:999px;
  background:var(--card);border:1px solid var(--line);
  color:var(--muted);font-size:13px;font-weight:600;
  cursor:pointer;transition:all .2s;white-space:nowrap;
}
.cat-btn:hover{background:var(--card-h);color:var(--text)}
.cat-btn.ativo{
  background:linear-gradient(145deg,var(--red),#d63050);
  border-color:transparent;color:#fff;
  box-shadow:0 6px 18px rgba(244,63,94,.35);
}

/* ---------- GRID ---------- */
.grid{
  display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));
  gap:16px;
}
.card{
  background:var(--card);border-radius:var(--radius);overflow:hidden;
  cursor:pointer;transition:transform .22s,box-shadow .22s;
  border:1px solid var(--line);position:relative;
}
.card:hover{transform:translateY(-4px);box-shadow:0 12px 30px rgba(0,0,0,.5);border-color:var(--red)}
.card img{width:100%;aspect-ratio:2/3;object-fit:cover;display:block;background:var(--bg-2)}
.card .info{padding:10px 12px 12px}
.card .info strong{
  display:block;font-size:13px;line-height:1.35;margin-bottom:4px;
  overflow:hidden;text-overflow:ellipsis;display:-webkit-box;
  -webkit-line-clamp:2;-webkit-box-orient:vertical;
}
.card .info small{color:var(--muted);font-size:11px;display:flex;align-items:center;gap:4px}
.card .info small svg{width:12px;height:12px;color:#f5b83d}

/* ---------- LOADING / EMPTY ---------- */
.msg{text-align:center;padding:60px 20px;color:var(--muted)}
.spin{
  width:38px;height:38px;margin:0 auto 14px;
  border:3px solid rgba(255,255,255,.1);border-top-color:var(--red);
  border-radius:50%;animation:spin .8s linear infinite;
}
@keyframes spin{to{transform:rotate(360deg)}}

/* ---------- LOAD MORE ---------- */
#mais{
  display:block;margin:32px auto 0;padding:13px 36px;
  background:linear-gradient(145deg,var(--red),#d63050);
  color:#fff;border:0;border-radius:11px;
  font-size:14px;font-weight:700;cursor:pointer;
  box-shadow:0 8px 24px rgba(244,63,94,.35);transition:transform .15s;
}
#mais:hover{transform:scale(1.03)}
#mais:disabled{opacity:.5;cursor:not-allowed;transform:none}

/* ---------- MODAL ---------- */
#modal{
  position:fixed;inset:0;z-index:999;
  background:rgba(2,3,7,.92);backdrop-filter:blur(8px);
  display:none;align-items:center;justify-content:center;
  padding:20px;overflow-y:auto;
}
#modal.on{display:flex}
.modal-box{
  width:100%;max-width:920px;
  background:linear-gradient(160deg,#141826,#0a0d15);
  border:1px solid var(--line);border-radius:20px;
  box-shadow:0 40px 100px rgba(0,0,0,.8);
  overflow:hidden;position:relative;
  animation:modalIn .32s cubic-bezier(.2,.9,.3,1.1);
  margin:auto;
}
@keyframes modalIn{from{opacity:0;transform:scale(.95) translateY(12px)}to{opacity:1;transform:none}}
.close{
  position:absolute;top:14px;right:14px;z-index:10;
  width:38px;height:38px;border-radius:50%;
  background:rgba(0,0,0,.6);border:1px solid rgba(255,255,255,.15);
  color:#fff;cursor:pointer;display:grid;place-items:center;
  transition:background .2s;
}
.close:hover{background:var(--red)}
.close svg{width:18px;height:18px}
.player-wrap{
  position:relative;width:100%;aspect-ratio:16/9;background:#000;
}
.player-wrap iframe{width:100%;height:100%;border:0;display:block}
.modal-info{padding:24px 26px 28px}
.modal-info h2{margin:0 0 10px;font-size:22px;font-weight:800;letter-spacing:-.02em}
.modal-meta{color:var(--muted);font-size:13px;margin-bottom:16px;display:flex;flex-wrap:wrap;gap:10px}
.modal-meta span{display:inline-flex;align-items:center;gap:5px}
.modal-meta svg{width:14px;height:14px}
.modal-info p{margin:0;color:#c4c8d0;font-size:14px;line-height:1.7;max-width:720px}

@media(max-width:520px){
  header{padding:12px 16px;gap:10px}
  .logo{font-size:17px}
  .logo svg{width:24px;height:24px}
  main{padding:8px 14px 60px}
  .grid{grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:12px}
  .card .info{padding:8px 10px 10px}
  .card .info strong{font-size:12px}
  .modal-info{padding:18px 18px 22px}
  .modal-info h2{font-size:18px}
}
</style>
</head>
<body>

<!-- ================= HEADER ================= -->
<header>
  <a class="logo" href="index.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <rect x="3" y="3" width="18" height="18" rx="5"/>
      <path d="m10 8 6 4-6 4V8Z" fill="currentColor" stroke="none"/>
    </svg>
    PlayMoz
  </a>
  <div class="search">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="11" cy="11" r="7"/>
      <path d="m20 20-3.5-3.5"/>
    </svg>
    <input id="busca" type="search" placeholder="Pesquisar filmes..." autocomplete="off">
  </div>
</header>

<!-- ================= MAIN ================= -->
<main>
  <div class="section-title">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M4 6h16M4 12h16M4 18h10"/>
    </svg>
    <span>Categorias</span>
  </div>
  <div class="cats" id="cats">
    <button class="cat-btn ativo" data-cat="">Todos</button>
  </div>

  <div class="section-title" style="margin-top:32px">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <rect x="3" y="4" width="18" height="16" rx="3"/>
      <path d="M3 9h18M9 4v5"/>
    </svg>
    <span id="titulo-secao">Filmes</span>
  </div>

  <div class="grid" id="grid"></div>
  <div class="msg" id="msg">
    <div class="spin"></div>
    <div>Carregando filmes...</div>
  </div>
  <button id="mais" style="display:none">Carregar mais</button>
</main>

<!-- ================= MODAL ================= -->
<div id="modal">
  <div class="modal-box">
    <button class="close" onclick="fecharModal()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
        <path d="M6 6l12 12M18 6 6 18"/>
      </svg>
    </button>
    <div class="player-wrap">
      <iframe id="frame" allow="autoplay; encrypted-media; fullscreen; picture-in-picture"
              referrerpolicy="strict-origin-when-cross-origin"></iframe>
    </div>
    <div class="modal-info">
      <h2 id="m-titulo"></h2>
      <div class="modal-meta" id="m-meta"></div>
      <p id="m-sinopse"></p>
    </div>
  </div>
</div>

<script>
'use strict';
const $ = id => document.getElementById(id);
let paginaAtual = 1;
let catAtual = '';
let buscaAtual = '';
let carregando = false;
let temMais = true;

/* ============ HELPERS ============ */
const iconeEstrela = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.9L12 17.8 5.8 21.1 7 14.2 2 9.3l6.9-1L12 2z"/></svg>';
const iconeCalendario = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>';
const iconeTag = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82Z"/><circle cx="7" cy="7" r="1.5" fill="currentColor"/></svg>';
const fallbackPoster = 'data:image/svg+xml;utf8,' + encodeURIComponent(
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 450"><rect fill="#161922" width="300" height="450"/><g fill="none" stroke="#2a2f3e" stroke-width="3"><rect x="90" y="180" width="120" height="90" rx="12"/><path d="m130 200 40 25-40 25z" fill="#2a2f3e"/></g></svg>'
);

function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

/* ============ CARREGAR CATEGORIAS ============ */
async function carregarCategorias() {
  try {
    const r = await fetch('?acao=categorias');
    const d = await r.json();
    if (!d.ok) return;
    const box = $('cats');
    for (const c of d.categorias) {
      const b = document.createElement('button');
      b.className = 'cat-btn';
      b.dataset.cat = c.id;
      b.textContent = c.nome;
      box.appendChild(b);
    }
  } catch (e) { /* silencioso */ }
}

/* ============ CARREGAR FILMES ============ */
async function carregarFilmes(reset = false) {
  if (carregando) return;
  if (!reset && !temMais) return;
  carregando = true;

  if (reset) {
    paginaAtual = 1;
    temMais = true;
    $('grid').innerHTML = '';
    $('msg').style.display = 'block';
    $('msg').innerHTML = '<div class="spin"></div><div>Carregando filmes...</div>';
    $('mais').style.display = 'none';
  }

  try {
    const params = new URLSearchParams({
      acao: 'lista',
      p: paginaAtual,
    });
    if (catAtual) params.set('cat', catAtual);
    if (buscaAtual) params.set('q', buscaAtual);

    const r = await fetch('?' + params.toString());
    const d = await r.json();
    if (!d.ok) throw new Error(d.error || 'Erro');

    $('msg').style.display = 'none';

    if (d.filmes.length === 0 && paginaAtual === 1) {
      $('msg').style.display = 'block';
      $('msg').innerHTML = '<div style="padding:30px 0">Nenhum filme encontrado.</div>';
    }

    const grid = $('grid');
    for (const f of d.filmes) {
      const card = document.createElement('div');
      card.className = 'card';
      card.onclick = () => abrirFilme(f.stream_id);
      card.innerHTML =
        '<img src="' + escapeHtml(f.poster || fallbackPoster) + '" alt="' + escapeHtml(f.nome) + '" loading="lazy" onerror="this.src=\'' + fallbackPoster + '\'">' +
        '<div class="info">' +
          '<strong>' + escapeHtml(f.nome) + '</strong>' +
          '<small>' + (f.rating ? iconeEstrela + ' ' + escapeHtml(f.rating) : '') +
          (f.ano ? (f.rating ? ' · ' : '') + escapeHtml(f.ano) : '') + '</small>' +
        '</div>';
      grid.appendChild(card);
    }

    temMais = !!d.tem_mais;
    $('mais').style.display = temMais ? 'block' : 'none';
  } catch (e) {
    $('msg').style.display = 'block';
    $('msg').innerHTML = '<div>Erro: ' + escapeHtml(e.message) + '</div>';
  } finally {
    carregando = false;
  }
}

/* ============ ABRIR FILME (modal) ============ */
async function abrirFilme(id) {
  const modal = $('modal');
  modal.classList.add('on');
  document.body.style.overflow = 'hidden';

  $('m-titulo').textContent = 'Carregando...';
  $('m-meta').innerHTML = '';
  $('m-sinopse').textContent = '';
  $('frame').src = 'about:blank';

  try {
    const r = await fetch('?acao=filme&id=' + encodeURIComponent(id));
    const d = await r.json();
    if (!d.ok) throw new Error(d.error || 'Erro');

    $('m-titulo').textContent = d.nome || 'Sem título';
    $('m-meta').innerHTML =
      (d.ano    ? '<span>' + iconeCalendario + escapeHtml(d.ano) + '</span>' : '') +
      (d.genero ? '<span>' + iconeTag + escapeHtml(d.genero) + '</span>' : '') +
      (d.rating ? '<span>' + iconeEstrela + escapeHtml(d.rating) + '</span>' : '');
    $('m-sinopse').textContent = d.sinopse || '';

    // Abre player
    $('frame').src = d.embed;
  } catch (e) {
    $('m-titulo').textContent = 'Erro';
    $('m-sinopse').textContent = e.message;
  }
}

function fecharModal() {
  $('modal').classList.remove('on');
  document.body.style.overflow = '';
  $('frame').src = 'about:blank';
}

/* ============ EVENTOS ============ */
$('cats').addEventListener('click', (e) => {
  const btn = e.target.closest('.cat-btn');
  if (!btn) return;
  document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('ativo'));
  btn.classList.add('ativo');
  catAtual = btn.dataset.cat || '';
  carregarFilmes(true);
});

let timerBusca = null;
$('busca').addEventListener('input', (e) => {
  clearTimeout(timerBusca);
  timerBusca = setTimeout(() => {
    buscaAtual = e.target.value.trim();
    carregarFilmes(true);
  }, 350);
});

$('mais').addEventListener('click', () => {
  paginaAtual++;
  carregarFilmes(false);
});

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') fecharModal();
});

$('modal').addEventListener('click', (e) => {
  if (e.target.id === 'modal') fecharModal();
});

/* ============ INIT ============ */
carregarCategorias();
carregarFilmes(true);
</script>
</body>
</html>
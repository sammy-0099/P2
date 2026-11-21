'use strict';

/**
 * PlayMoz Hyper Network Scanner
 * Observa respostas de rede do Chromium via CDP.
 * Aceita apenas páginas públicas de reprodução do Hyper.
 * Node.js 20+ e Chromium/Chrome instalados no servidor.
 */

const http = require('node:http');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const fsSync = require('node:fs');
const fs = require('node:fs/promises');
const { spawn } = require('node:child_process');

const HOST = process.env.SCANNER_HOST || '127.0.0.1';
const PORT = Number(process.env.SCANNER_PORT || 8787);
const TOKEN = process.env.PM_SCANNER_TOKEN || '';
const SCAN_TIMEOUT_MS = Math.min(
  Math.max(Number(process.env.SCAN_TIMEOUT_MS || 35000), 10000),
  55000
);
const MAX_CONCURRENT = Math.max(1, Math.min(Number(process.env.MAX_CONCURRENT_SCANS || 2), 4));
const HYPER_HOST = 'hyper.hyperapps.site';

let activeScans = 0;

function json(res, status, payload) {
  const body = Buffer.from(JSON.stringify(payload));
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': body.length,
    'Cache-Control': 'no-store',
    'X-Content-Type-Options': 'nosniff'
  });
  res.end(body);
}

function safeEqual(a, b) {
  const aa = Buffer.from(String(a || ''));
  const bb = Buffer.from(String(b || ''));
  return aa.length === bb.length && require('node:crypto').timingSafeEqual(aa, bb);
}

function isAllowedPage(raw) {
  let u;
  try { u = new URL(raw); } catch { return false; }
  if (u.protocol !== 'https:' || u.hostname.toLowerCase() !== HYPER_HOST) return false;
  if (u.username || u.password || u.port) return false;
  const p = u.pathname;
  const movie = /^\/filmes\/[a-z0-9]+(?:-[a-z0-9]+)*\/assistir\/?$/i.test(p);
  const episode = /^\/series\/[a-z0-9]+(?:-[a-z0-9]+)*\/temporada\/[1-9][0-9]{0,2}\/episodio\/[1-9][0-9]{0,3}\/?$/i.test(p);
  return movie || episode;
}

function chromiumPath() {
  if (process.env.CHROMIUM_PATH) return process.env.CHROMIUM_PATH;
  return [
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
    '/usr/bin/google-chrome',
    '/opt/google/chrome/chrome',
    '/snap/bin/chromium'
  ].find(candidate => fsSync.existsSync(candidate)) || 'chromium';
}

async function getFreePort() {
  return await new Promise((resolve, reject) => {
    const srv = net.createServer();
    srv.once('error', reject);
    srv.listen(0, '127.0.0.1', () => {
      const address = srv.address();
      const port = address && address.port;
      srv.close(err => err ? reject(err) : resolve(port));
    });
  });
}

async function waitForPage(port, child, timeoutMs = 10000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (child.spawnError) throw new Error(`Não foi possível iniciar Chromium: ${child.spawnError.message}`);
    if (child.exitCode !== null) throw new Error('Chromium terminou antes de iniciar.');
    try {
      const response = await fetch(`http://127.0.0.1:${port}/json/list`, {
        signal: AbortSignal.timeout(1200)
      });
      if (response.ok) {
        const targets = await response.json();
        const page = targets.find(x => x.type === 'page' && x.webSocketDebuggerUrl);
        if (page) return page;
      }
    } catch (_) {}
    await new Promise(r => setTimeout(r, 250));
  }
  throw new Error('Chromium não disponibilizou o DevTools a tempo.');
}

function mediaKind(url, mime) {
  let pathname = '';
  try { pathname = new URL(url).pathname.toLowerCase(); } catch {}
  const m = String(mime || '').toLowerCase();
  if (/\.mp4$/i.test(pathname) || m === 'video/mp4') return 'mp4';
  if (/\.m3u8$/i.test(pathname) || m.includes('mpegurl') || m.includes('m3u8')) return 'hls';
  if (/\.mpd$/i.test(pathname) || m.includes('dash+xml')) return 'dash';
  return null;
}

function isPublicHttps(raw) {
  try {
    const u = new URL(raw);
    const host = u.hostname.toLowerCase();
    if (u.protocol !== 'https:' || !host || u.username || u.password) return false;
    if (host === 'localhost' || host.endsWith('.local') || host.endsWith('.internal')) return false;
    const ipType = net.isIP(host);
    if (ipType === 4) {
      const octets = host.split('.').map(Number);
      const [a, b] = octets;
      if (a === 0 || a === 10 || a === 127 || a >= 224 ||
          (a === 169 && b === 254) || (a === 172 && b >= 16 && b <= 31) ||
          (a === 192 && b === 168)) return false;
    }
    if (ipType === 6 && (host === '::1' || host.startsWith('fe80:') ||
        host.startsWith('fc') || host.startsWith('fd'))) return false;
    return true;
  } catch { return false; }
}

async function scanPage(targetUrl) {
  if (!isAllowedPage(targetUrl)) throw new Error('A rota enviada não é uma página Assistir permitida do Hyper.');

  const executable = chromiumPath();
  const userDataDir = await fs.mkdtemp(path.join(os.tmpdir(), 'playmoz-hyper-'));
  const port = await getFreePort();
  const child = spawn(executable, [
    '--headless=new',
    '--no-sandbox',
    '--disable-gpu',
    '--disable-dev-shm-usage',
    '--disable-background-networking',
    '--disable-extensions',
    '--disable-sync',
    '--no-first-run',
    '--no-default-browser-check',
    '--disable-features=Translate,MediaRouter',
    '--remote-debugging-address=127.0.0.1',
    `--remote-debugging-port=${port}`,
    '--remote-allow-origins=*',
    `--user-data-dir=${userDataDir}`,
    'about:blank'
  ], { stdio: ['ignore', 'ignore', 'pipe'] });

  let socket = null;
  let stderr = '';
  child.on('error', err => { child.spawnError = err; });
  child.stderr.on('data', chunk => {
    stderr = (stderr + chunk.toString()).slice(-3000);
  });

  try {
    const page = await waitForPage(port, child);
    socket = new WebSocket(page.webSocketDebuggerUrl);
    socket.addEventListener('error', () => {});

    await new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error('Ligação CDP expirou.')), 8000);
      socket.addEventListener('open', () => { clearTimeout(timer); resolve(); }, { once: true });
      socket.addEventListener('error', () => {
        clearTimeout(timer);
        reject(new Error('Não foi possível abrir a ligação WebSocket do CDP.'));
      }, { once: true });
    });

    let commandId = 0;
    const pending = new Map();
    const requests = new Map();
    const candidates = new Map();

    function command(method, params = {}) {
      const id = ++commandId;
      return new Promise((resolve, reject) => {
        const timer = setTimeout(() => {
          pending.delete(id);
          reject(new Error(`CDP timeout: ${method}`));
        }, 8000);
        pending.set(id, { resolve, reject, timer });
        socket.send(JSON.stringify({ id, method, params }));
      });
    }

    let firstCandidateResolve;
    const firstCandidate = new Promise(resolve => { firstCandidateResolve = resolve; });

    socket.addEventListener('message', event => {
      let msg;
      try { msg = JSON.parse(String(event.data)); } catch { return; }

      if (msg.id && pending.has(msg.id)) {
        const p = pending.get(msg.id);
        pending.delete(msg.id);
        clearTimeout(p.timer);
        if (msg.error) p.reject(new Error(msg.error.message || 'Erro CDP'));
        else p.resolve(msg.result || {});
        return;
      }

      if (msg.method === 'Network.requestWillBeSent') {
        const p = msg.params;
        requests.set(p.requestId, {
          url: p.request && p.request.url,
          headers: p.request && p.request.headers || {}
        });
        return;
      }

      if (msg.method !== 'Network.responseReceived') return;
      const p = msg.params;
      const r = p.response || {};
      const url = String(r.url || '');
      const mime = String(r.mimeType || (r.headers && (r.headers['content-type'] || r.headers['Content-Type'])) || '');
      const kind = mediaKind(url, mime);
      if (!kind || !isPublicHttps(url)) return;
      if (![200, 206].includes(Number(r.status))) return;

      const request = requests.get(p.requestId) || {};
      const requestHeaders = request.headers || {};
      const candidate = {
        url,
        type: kind,
        mimeType: mime,
        status: Number(r.status),
        resourceType: p.type || '',
        referer: String(requestHeaders.Referer || requestHeaders.referer || targetUrl)
      };
      candidates.set(url, candidate);
      firstCandidateResolve(candidate);
    });

    await command('Network.enable', {
      maxTotalBufferSize: 4 * 1024 * 1024,
      maxResourceBufferSize: 1024 * 1024
    });
    await command('Page.enable');
    await command('Network.setCacheDisabled', { cacheDisabled: true });
    await command('Page.navigate', { url: targetUrl });

    const first = await Promise.race([
      firstCandidate,
      new Promise(resolve => setTimeout(() => resolve(null), SCAN_TIMEOUT_MS))
    ]);

    if (!first) {
      throw new Error('O navegador não observou uma resposta MP4, HLS ou DASH dentro do tempo limite.');
    }

    // Dá uma pequena janela para captar outras qualidades/servidores do mesmo player.
    await new Promise(r => setTimeout(r, 1200));

    const ranked = [...candidates.values()].sort((a, b) => {
      const rank = { mp4: 0, hls: 1, dash: 2 };
      return (rank[a.type] ?? 9) - (rank[b.type] ?? 9);
    });

    return {
      ok: true,
      page: targetUrl,
      media: ranked[0],
      alternatives: ranked.slice(1, 4).map(x => ({
        type: x.type,
        mimeType: x.mimeType,
        status: x.status
      }))
    };
  } catch (error) {
    if (stderr && error.message.includes('Chromium')) {
      error.message += ' ' + stderr.slice(-700);
    }
    throw error;
  } finally {
    if (socket && socket.readyState === 1) {
      try { socket.close(); } catch {}
    }
    if (child && child.exitCode === null) {
      child.kill('SIGTERM');
      await new Promise(resolve => {
        const timer = setTimeout(() => {
          if (child.exitCode === null) child.kill('SIGKILL');
          resolve();
        }, 1800);
        child.once('exit', () => { clearTimeout(timer); resolve(); });
      });
    }
    await fs.rm(userDataDir, { recursive: true, force: true }).catch(() => {});
  }
}

async function readBody(req, maxBytes = 8192) {
  const chunks = [];
  let size = 0;
  for await (const chunk of req) {
    size += chunk.length;
    if (size > maxBytes) throw new Error('Pedido demasiado grande.');
    chunks.push(chunk);
  }
  return Buffer.concat(chunks).toString('utf8');
}

const server = http.createServer(async (req, res) => {
  if (req.method === 'GET' && req.url === '/health') {
    return json(res, 200, {
      ok: true,
      service: 'playmoz-hyper-scanner',
      node: process.version,
      chromium: chromiumPath(),
      activeScans
    });
  }

  if (req.method !== 'POST' || req.url !== '/scan') {
    return json(res, 404, { ok: false, error: 'not_found' });
  }

  if (!TOKEN) return json(res, 503, { ok: false, error: 'scanner_token_not_configured' });
  const auth = String(req.headers.authorization || '');
  if (!safeEqual(auth, `Bearer ${TOKEN}`)) {
    return json(res, 401, { ok: false, error: 'unauthorized' });
  }
  if (activeScans >= MAX_CONCURRENT) {
    return json(res, 429, { ok: false, error: 'scanner_busy' });
  }

  activeScans++;
  try {
    const body = JSON.parse(await readBody(req));
    const targetUrl = String(body.url || '');
    if (!isAllowedPage(targetUrl)) {
      return json(res, 400, { ok: false, error: 'invalid_hyper_assist_page' });
    }

    const result = await scanPage(targetUrl);
    return json(res, 200, result);
  } catch (error) {
    const message = String(error && error.message || 'scanner_error').slice(0, 500);
    return json(res, 502, { ok: false, error: message });
  } finally {
    activeScans--;
  }
});

if (!TOKEN) {
  console.error('[PlayMoz Scanner] Define PM_SCANNER_TOKEN antes de iniciar.');
  process.exit(1);
}

server.listen(PORT, HOST, () => {
  console.log(`[PlayMoz Scanner] activo em http://${HOST}:${PORT}`);
  console.log(`[PlayMoz Scanner] Chromium: ${chromiumPath()}`);
});

<?php
/**
 * PlayMoz V12 — associação TMDB -> slug Hyper -> episódio Hyper -> media URL.
 * Leitura somente de metadados e links explicitamente publicados nas páginas.
 * Não tenta contornar autenticação, paywalls ou DRM.
 * Requer PHP 8+ e cURL.
 */

if (!defined('PM_HYPER_ORIGIN')) define('PM_HYPER_ORIGIN', 'https://hyper.hyperapps.site');

function pm_hyper_http(string $url, int $timeout = 12): ?string {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PlayMoz/1.0)',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/json;q=0.9,*/*;q=0.5'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200 || !is_string($body) || strlen($body) > 4 * 1024 * 1024) return null;
    return $body;
}

function pm_hyper_slug(string $s): string {
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($ascii !== false) $s = $ascii;
    $s = strtolower($s);
    return trim((string)preg_replace('/[^a-z0-9]+/', '-', $s), '-');
}

function pm_hyper_cache_get(string $key): ?array {
    $file = sys_get_temp_dir() . '/pm_hyper_v12_' . hash('sha256', $key) . '.json';
    if (!is_file($file)) return null;
    $obj = json_decode((string)@file_get_contents($file), true);
    if (!is_array($obj) || ($obj['valid_until'] ?? 0) < time() || !isset($obj['value']) || !is_array($obj['value'])) return null;
    return $obj['value'];
}
function pm_hyper_cache_put(string $key, array $value, int $ttl): void {
    $file = sys_get_temp_dir() . '/pm_hyper_v12_' . hash('sha256', $key) . '.json';
    @file_put_contents($file, json_encode(['valid_until' => time() + $ttl, 'value' => $value]), LOCK_EX);
}

function pm_hyper_tmdb(int $tmdbId): ?array {
    $key = 'tmdb:' . $tmdbId;
    if ($cached = pm_hyper_cache_get($key)) return $cached;
    $apiKey = getenv('TMDB_API_KEY') ?: (defined('TMDB_API_KEY') ? TMDB_API_KEY : '');
    if ($apiKey === '') return null;
    $url = 'https://api.themoviedb.org/3/tv/' . $tmdbId . '?api_key=' . rawurlencode($apiKey)
         . '&language=pt-BR&append_to_response=external_ids';
    $raw = pm_hyper_http($url);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || (int)($data['id'] ?? 0) !== $tmdbId) return null;
    pm_hyper_cache_put($key, $data, 12 * 3600);
    return $data;
}

/** Título da ficha Hyper; não requer a extensão PHP DOM. */
function pm_hyper_page_title(string $html): string {
    if (preg_match('~<article\b[\s\S]*?<h1\b[^>]*>(.*?)</h1>~i', $html, $m)
        || preg_match('~<meta\b[^>]*property=["\\\']og:title["\\\'][^>]*content=["\\\']([^"\\\']+)["\\\']~i', $html, $m)
        || preg_match('~<h1\b[^>]*>(.*?)</h1>~i', $html, $m)) {
        return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return '';
}

function pm_hyper_matches_tmdb(string $html, array $tmdb): bool {
    $hyperTitle = pm_hyper_slug(pm_hyper_page_title($html));
    if ($hyperTitle === '') return false;
    $names = array_filter([$tmdb['name'] ?? '', $tmdb['original_name'] ?? '']);
    foreach ($names as $name) {
        if (pm_hyper_slug((string)$name) === $hyperTitle) return true;
    }
    // Sem identificação inequívoca não associar uma série diferente.
    return false;
}

function pm_hyper_search_slugs(string $name): array {
    $html = pm_hyper_http(PM_HYPER_ORIGIN . '/busca?q=' . rawurlencode($name));
    if (!$html) return [];
    preg_match_all('~href=["\\\']/series/([a-z0-9-]+)/?["\\\']~i', $html, $matches);
    return array_slice(array_values(array_unique($matches[1])), 0, 12);
}

function pm_hyper_find_slug(int $tmdbId, array $tmdb): ?string {
    $key = 'slug:' . $tmdbId;
    if ($cached = pm_hyper_cache_get($key)) return $cached['slug'] ?? null;
    $names = array_values(array_unique(array_filter([
        (string)($tmdb['name'] ?? ''),
        (string)($tmdb['original_name'] ?? ''),
    ])));
    $slugs = [];
    foreach ($names as $name) {
        $s = pm_hyper_slug($name);
        if ($s !== '') $slugs[] = $s;
    }
    // Primeiro tenta os slugs gerados; só depois tenta a pesquisa do Hyper.
    $slugs = array_unique($slugs);
    $tested = [];
    foreach (range(1, 2) as $pass) {
        foreach ($slugs as $slug) {
            if (isset($tested[$slug])) continue;
            $tested[$slug] = true;
            $html = pm_hyper_http(PM_HYPER_ORIGIN . '/series/' . rawurlencode($slug));
            if ($html !== null && pm_hyper_matches_tmdb($html, $tmdb)) {
                pm_hyper_cache_put($key, ['slug'=>$slug], 6 * 3600);
                return $slug;
            }
        }
        if ($pass === 1) {
            foreach ($names as $name) {
                foreach (pm_hyper_search_slugs($name) as $slug) {
                    if (!isset($tested[$slug])) $slugs[] = $slug;
                }
            }
            $slugs = array_unique($slugs);
        }
    }
    return null;
}

/** Retorna somente um ID da temporada e episódio solicitados, não um ID calculado. */
function pm_hyper_episode_from_html(string $html, int $season, int $episode): ?int {
    // Cada linha ep-row contém os links Assistir e Baixar correspondentes.
    preg_match_all('~<li\b[^>]*class=["\\\'][^"\\\']*\bep-row\b[^"\\\']*["\\\'][^>]*>(.*?)</li>~si', $html, $rows);
    foreach ($rows[1] as $row) {
        preg_match_all('~href=["\\\']([^"\\\']+)["\\\']~i', $row, $links);
        $matchEpisode = false;
        $matchDownload = null;
        foreach ($links[1] as $href) {
            $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('~/series/[^/?#]+/temporada/(\d+)/episodio/(\d+)(?:[/?#]|$)~', $href, $m)) {
                if ((int)$m[1] === $season && (int)$m[2] === $episode) $matchEpisode = true;
            }
            if (preg_match('~^/api/download/episode/([1-9][0-9]*)$~', $href, $m)) $matchDownload = (int)$m[1];
        }
        if ($matchEpisode && $matchDownload) return $matchDownload;
    }
    return null;
}

/** Associa TMDB + S + E ao endpoint publicado pelo Hyper. */
function pm_hyper_episode_endpoint(int $tmdbId, int $season, int $episode): ?string {
    if ($tmdbId <= 0 || $season <= 0 || $episode <= 0 || $season > 80 || $episode > 200) return null;
    $key = 'episode:' . $tmdbId . ':' . $season . ':' . $episode;
    if ($cached = pm_hyper_cache_get($key)) return $cached['endpoint'] ?? null;
    $tmdb = pm_hyper_tmdb($tmdbId);
    if (!$tmdb) return null;
    $slug = pm_hyper_find_slug($tmdbId, $tmdb);
    if (!$slug) return null;

    // A rota de temporada confirmada no HTML público é ?temporada=N.
    $url = PM_HYPER_ORIGIN . '/series/' . rawurlencode($slug)
         . ($season === 1 ? '' : '?temporada=' . $season);
    $html = pm_hyper_http($url);
    if (!$html || !pm_hyper_matches_tmdb($html, $tmdb)) return null;
    $episodeId = pm_hyper_episode_from_html($html, $season, $episode);
    if (!$episodeId) return null;
    $endpoint = PM_HYPER_ORIGIN . '/api/download/episode/' . $episodeId;
    pm_hyper_cache_put($key, ['endpoint' => $endpoint], 2 * 3600);
    return $endpoint;
}

/** Rejeita endereços locais/internos; o player deve apontar para HTTPS público. */
function pm_hyper_public_url(string $url): bool {
    $p = parse_url($url);
    if (!is_array($p) || !in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true)) return false;
    if (!empty($p['user']) || !empty($p['pass']) || empty($p['host'])) return false;
    $host = strtolower((string)$p['host']);
    if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) return false;
    if (filter_var($host, FILTER_VALIDATE_IP)) return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    if (!preg_match('/^[a-z0-9.-]+$/', $host) || strpos($host, '.') === false) return false;
    return true;
}

/** Apenas cabeçalhos/range mínimo. Nunca descarrega o episódio para fazer a detecção. */
function pm_hyper_media_headers(string $url, bool $range = false): array {
    if (!function_exists('curl_init')) return ['status'=>0, 'location'=>'', 'type'=>''];
    $location = '';
    $ch = curl_init($url);
    $opts = [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_REFERER => PM_HYPER_ORIGIN . '/',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$location) {
            if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
            return strlen($line);
        },
    ];
    if (!$range) {
        $opts[CURLOPT_NOBODY] = true;
        $opts[CURLOPT_RETURNTRANSFER] = true;
    } else {
        // Se HEAD for recusado, faz GET com Range bytes=0-0; descarta os dados.
        $opts[CURLOPT_RANGE] = '0-0';
        $consumed = 0;
        $opts[CURLOPT_WRITEFUNCTION] = static function ($ch, $data) use (&$consumed) {
            $consumed += strlen($data);
            return $consumed > 131072 ? 0 : strlen($data);
        };
    }
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return ['status'=>$status, 'location'=>$location, 'type'=>strtolower($ctype)];
}

function pm_hyper_resolve_media(string $endpoint): ?string {
    // Primeiro salto sempre tem de ser o endpoint exacto do Hyper.
    if (!preg_match('~^https://hyper\.hyperapps\.site/api/download/episode/[1-9][0-9]*$~', $endpoint)) return null;
    $url = $endpoint;
    $visited = [];
    for ($i = 0; $i < 5; $i++) {
        if (!pm_hyper_public_url($url) || isset($visited[$url])) return null;
        $visited[$url] = true;
        $r = pm_hyper_media_headers($url);
        if (in_array($r['status'], [0, 400, 403, 405, 406, 501], true) ||
            ($i === 0 && $r['status'] === 200 && stripos($r['type'], 'text/html') !== false)) {
            $r = pm_hyper_media_headers($url, true);
        }
        if ($r['status'] >= 300 && $r['status'] <= 399 && $r['location'] !== '') {
            // Redirects podem ser absolutos ou relativos.
            $next = $r['location'];
            if (str_starts_with($next, '//')) $next = 'https:' . $next;
            elseif (str_starts_with($next, '/')) {
                $p = parse_url($url);
                $next = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . $next;
            } elseif (!preg_match('~^https?://~i', $next)) {
                $p = parse_url($url);
                $folder = rtrim(str_replace('\\', '/', dirname((string)($p['path'] ?? '/'))), '/');
                $next = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . $folder . '/' . ltrim($next, '/');
            }
            if (!pm_hyper_public_url($next)) return null;
            $url = $next;
            continue;
        }
        if (in_array($r['status'], [200, 206], true)) {
            $path = (string)(parse_url($url, PHP_URL_PATH) ?: '');
            $isMp4 = (bool)preg_match('~\.mp4$~i', $path);
            $isVideo = strpos($r['type'], 'video/') === 0;
            if (($isMp4 || $isVideo) && stripos($r['type'], 'text/html') === false) return $url;
        }
        return null;
    }
    return null;
}

function pm_hyper_resolve(int $tmdbId, int $season, int $episode): ?string {
    $endpoint = pm_hyper_episode_endpoint($tmdbId, $season, $episode);
    if (!$endpoint) return null;
    // Muitos endpoints GET de download recusam HEAD; nesse caso a URL pública
    // exacta do HTML continua válida e o proxy MP4 existente segue o 302.
    return pm_hyper_resolve_media($endpoint) ?: $endpoint;
}

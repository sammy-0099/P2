<?php
/**
 * PlayMoz — resolução Hyper por captura de rede (Chromium/CDP).
 *
 * Filmes: /filmes/{slug}/assistir
 * Séries: consulta /series/{slug}, encontra o link exacto do episódio
 *         /series/{slug}/temporada/{season}/episodio/{episode}
 *
 * A captura é feita pelo serviço Node local em /scanner. Não usa endpoints
 * /api/download e não tenta contornar DRM, autenticação ou outras protecções.
 * Requer PHP 8+, cURL, Node.js 20+ e Chromium no servidor.
 */

if (!defined('PM_HYPER_ORIGIN')) {
    define('PM_HYPER_ORIGIN', 'https://hyper.hyperapps.site');
}

function pm_hyper_http(string $url, int $timeout = 14): ?string {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PlayMoz/2.0)',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.7',
            'Accept-Language: pt-PT,pt;q=0.9,en;q=0.7'
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300 || !is_string($body) || strlen($body) > 4 * 1024 * 1024) {
        return null;
    }
    return $body;
}

function pm_hyper_slug(string $value): string {
    $value = trim($value);
    if (function_exists('transliterator_transliterate')) {
        $converted = transliterator_transliterate('Any-Latin; Latin-ASCII', $value);
        if (is_string($converted)) $value = $converted;
    } elseif (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) $value = $converted;
    }
    $value = strtolower($value);
    return trim((string)preg_replace('/[^a-z0-9]+/', '-', $value), '-');
}

function pm_hyper_cache_file(string $key): string {
    return sys_get_temp_dir() . '/pm_hyper_cdp_' . hash('sha256', $key) . '.json';
}

function pm_hyper_cache_get(string $key): ?array {
    $file = pm_hyper_cache_file($key);
    if (!is_file($file)) return null;
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data) || (int)($data['valid_until'] ?? 0) < time()) return null;
    return isset($data['value']) && is_array($data['value']) ? $data['value'] : null;
}

function pm_hyper_cache_put(string $key, array $value, int $ttl): void {
    $file = pm_hyper_cache_file($key);
    @file_put_contents($file, json_encode([
        'valid_until' => time() + max(30, $ttl),
        'value' => $value
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($file, 0600);
}

function pm_hyper_tmdb_request(string $path, array $query = []): ?array {
    $apiKey = getenv('TMDB_API_KEY') ?: (defined('TMDB_API_KEY') ? TMDB_API_KEY : '');
    if ($apiKey === '') return null;
    $query = array_merge(['api_key' => $apiKey], $query);
    $url = 'https://api.themoviedb.org/3/' . ltrim($path, '/') . '?' . http_build_query($query);
    $raw = pm_hyper_http($url, 12);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : null;
}

function pm_hyper_tmdb(int $tmdbId): ?array {
    if ($tmdbId <= 0) return null;
    $key = 'tv-details:' . $tmdbId;
    if ($cached = pm_hyper_cache_get($key)) return $cached;
    $data = pm_hyper_tmdb_request('tv/' . $tmdbId, [
        'language' => 'pt-BR',
        'append_to_response' => 'external_ids'
    ]);
    if (!is_array($data) || (int)($data['id'] ?? 0) !== $tmdbId) return null;
    pm_hyper_cache_put($key, $data, 12 * 3600);
    return $data;
}

function pm_hyper_movie_tmdb(int $tmdbId): ?array {
    if ($tmdbId <= 0) return null;
    $key = 'movie-details:' . $tmdbId;
    if ($cached = pm_hyper_cache_get($key)) return $cached;
    $data = pm_hyper_tmdb_request('movie/' . $tmdbId, ['language' => 'pt-BR']);
    if (!is_array($data) || (int)($data['id'] ?? 0) !== $tmdbId) {
        $data = pm_hyper_tmdb_request('movie/' . $tmdbId, ['language' => 'en-US']);
    }
    if (!is_array($data) || (int)($data['id'] ?? 0) !== $tmdbId) return null;
    pm_hyper_cache_put($key, $data, 12 * 3600);
    return $data;
}

function pm_hyper_page_title(string $html): string {
    $patterns = [
        '~<article\b[\s\S]*?<h1\b[^>]*>(.*?)</h1>~i',
        '~<h1\b[^>]*>(.*?)</h1>~i',
        '~<meta\b[^>]*property=["\']og:title["\'][^>]*content=["\']([^"\']+)["\']~i'
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $m)) {
            $title = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $title = preg_replace('/\s+/u', ' ', trim($title));
            if ($title !== '') return $title;
        }
    }
    return '';
}

function pm_hyper_title_matches(string $pageTitle, array $names, bool $stripAssistir = false): bool {
    if ($stripAssistir) {
        $pageTitle = preg_replace('/^\s*(assistir|ver|filme)\s+/iu', '', $pageTitle);
    }
    $pageKey = pm_hyper_slug($pageTitle);
    if ($pageKey === '') return false;
    foreach ($names as $name) {
        if (is_string($name) && $name !== '' && pm_hyper_slug($name) === $pageKey) return true;
    }
    return false;
}

function pm_hyper_matches_tmdb(string $html, array $tmdb): bool {
    return pm_hyper_title_matches(pm_hyper_page_title($html), [
        (string)($tmdb['name'] ?? ''),
        (string)($tmdb['original_name'] ?? '')
    ]);
}

function pm_hyper_matches_movie(string $html, array $tmdb): bool {
    return pm_hyper_title_matches(pm_hyper_page_title($html), [
        (string)($tmdb['title'] ?? ''),
        (string)($tmdb['original_title'] ?? '')
    ], true);
}

function pm_hyper_search_slugs(string $name, string $kind): array {
    $html = pm_hyper_http(PM_HYPER_ORIGIN . '/busca?q=' . rawurlencode($name));
    if (!$html) return [];
    $prefix = $kind === 'movie' ? 'filmes' : 'series';
    preg_match_all('~href=["\'](?:https://hyper\.hyperapps\.site)?/' . $prefix . '/([a-z0-9-]+)(?:/[^"\']*)?["\']~i', $html, $matches);
    return array_slice(array_values(array_unique($matches[1] ?? [])), 0, 20);
}

function pm_hyper_find_slug(int $tmdbId, array $tmdb): ?string {
    $key = 'tv-slug:' . $tmdbId;
    if ($cached = pm_hyper_cache_get($key)) return $cached['slug'] ?? null;

    $names = array_values(array_unique(array_filter([
        (string)($tmdb['name'] ?? ''),
        (string)($tmdb['original_name'] ?? '')
    ])));
    $generated = [];
    foreach ($names as $name) {
        $slug = pm_hyper_slug($name);
        if ($slug !== '') $generated[] = $slug;
    }
    $tested = [];
    foreach (array_values(array_unique($generated)) as $slug) {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) continue;
        $tested[$slug] = true;
        $html = pm_hyper_http(PM_HYPER_ORIGIN . '/series/' . rawurlencode($slug));
        if ($html !== null && pm_hyper_matches_tmdb($html, $tmdb)) {
            pm_hyper_cache_put($key, ['slug' => $slug], 6 * 3600);
            return $slug;
        }
    }
    $searched = [];
    foreach ($names as $name) {
        $searched = array_merge($searched, pm_hyper_search_slugs($name, 'series'));
    }
    foreach (array_slice(array_values(array_unique($searched)), 0, 20) as $slug) {
        if (isset($tested[$slug]) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) continue;
        $html = pm_hyper_http(PM_HYPER_ORIGIN . '/series/' . rawurlencode($slug));
        if ($html !== null && pm_hyper_matches_tmdb($html, $tmdb)) {
            pm_hyper_cache_put($key, ['slug' => $slug], 6 * 3600);
            return $slug;
        }
    }
    return null;
}

function pm_hyper_find_movie_slug(int $tmdbId, array $tmdb): ?string {
    $key = 'movie-slug:' . $tmdbId;
    if ($cached = pm_hyper_cache_get($key)) return $cached['slug'] ?? null;

    $names = array_values(array_unique(array_filter([
        (string)($tmdb['title'] ?? ''),
        (string)($tmdb['original_title'] ?? '')
    ])));
    $generated = [];
    foreach ($names as $name) {
        $slug = pm_hyper_slug($name);
        if ($slug !== '') $generated[] = $slug;
    }
    $tested = [];
    $testSlugs = static function (array $slugs) use ($tmdb, $key, &$tested): ?string {
        foreach (array_values(array_unique($slugs)) as $slug) {
            if (isset($tested[$slug]) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) continue;
            $tested[$slug] = true;
            foreach ([
                PM_HYPER_ORIGIN . '/filmes/' . rawurlencode($slug),
                PM_HYPER_ORIGIN . '/filmes/' . rawurlencode($slug) . '/assistir'
            ] as $candidateUrl) {
                $html = pm_hyper_http($candidateUrl);
                if ($html !== null && pm_hyper_matches_movie($html, $tmdb)) {
                    pm_hyper_cache_put($key, ['slug' => $slug], 6 * 3600);
                    return $slug;
                }
            }
        }
        return null;
    };

    if ($found = $testSlugs($generated)) return $found;

    $searched = [];
    foreach ($names as $name) {
        $searched = array_merge($searched, pm_hyper_search_slugs($name, 'movie'));
    }
    return $testSlugs(array_slice(array_values(array_unique($searched)), 0, 20));
}

/**
 * Lê o HTML público da ficha da série (equivalente ao view-source),
 * mas selecciona apenas o href Assistir correspondente a S/E.
 */
function pm_hyper_episode_from_html(string $html, string $slug, int $season, int $episode): ?string {
    preg_match_all('~href=["\']([^"\']+)["\']~i', $html, $matches);
    foreach ($matches[1] ?? [] as $href) {
        $href = html_entity_decode(str_replace(['&amp;', '\\/'], ['&', '/'], $href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $path = (string)(parse_url($href, PHP_URL_PATH) ?: '');
        if ($path === '') continue;
        if (preg_match('~^/series/([^/]+)/temporada/([0-9]+)/episodio/([0-9]+)/?$~i', $path, $m)) {
            if (strtolower($m[1]) === strtolower($slug)
                && (int)$m[2] === $season
                && (int)$m[3] === $episode) {
                return PM_HYPER_ORIGIN . $path;
            }
        }
    }
    return null;
}

function pm_hyper_episode_page_url(int $tmdbId, int $season, int $episode): ?string {
    if ($tmdbId <= 0 || $season <= 0 || $episode <= 0 || $season > 80 || $episode > 300) return null;
    $key = 'episode-page:' . $tmdbId . ':' . $season . ':' . $episode;
    if ($cached = pm_hyper_cache_get($key)) return $cached['url'] ?? null;

    $tmdb = pm_hyper_tmdb($tmdbId);
    if (!$tmdb) return null;
    $slug = pm_hyper_find_slug($tmdbId, $tmdb);
    if (!$slug) return null;

    $listingUrl = PM_HYPER_ORIGIN . '/series/' . rawurlencode($slug);
    if ($season > 1) $listingUrl .= '?temporada=' . $season;
    $html = pm_hyper_http($listingUrl);
    if (!$html || !pm_hyper_matches_tmdb($html, $tmdb)) return null;

    $episodeUrl = pm_hyper_episode_from_html($html, $slug, $season, $episode);
    if (!$episodeUrl) return null;
    pm_hyper_cache_put($key, ['url' => $episodeUrl], 2 * 3600);
    return $episodeUrl;
}

function pm_hyper_public_url(string $url): bool {
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') return false;
    if (!empty($parts['user']) || !empty($parts['pass']) || empty($parts['host'])) return false;
    $host = strtolower((string)$parts['host']);
    if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) return false;
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return (bool)filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
    return preg_match('/^[a-z0-9.-]+$/', $host) === 1 && strpos($host, '.') !== false;
}

/** Chama o serviço CDP local; o token nunca é enviado ao browser do utilizador. */
function pm_hyper_scan_page(string $pageUrl): ?array {
    if (!preg_match('~^https://hyper\.hyperapps\.site/(?:filmes/[a-z0-9-]+/assistir|series/[a-z0-9-]+/temporada/[0-9]+/episodio/[0-9]+)/?$~i', $pageUrl)) {
        return null;
    }
    if (!function_exists('curl_init')) return null;

    $scannerUrl = getenv('PM_HYPER_SCANNER_URL') ?: 'http://127.0.0.1:8787/scan';
    $token = getenv('PM_SCANNER_TOKEN') ?: '';
    if ($token === '') {
        error_log('PlayMoz Hyper scanner: PM_SCANNER_TOKEN não está configurado no PHP.');
        return null;
    }

    $payload = json_encode(['url' => $pageUrl], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) return null;

    $ch = curl_init($scannerUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token
        ],
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 55,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($raw === false) error_log('PlayMoz Hyper scanner cURL: ' . curl_error($ch));
    curl_close($ch);

    $data = is_string($raw) ? json_decode($raw, true) : null;
    $media = is_array($data) ? ($data['media'] ?? null) : null;
    if ($status !== 200 || !is_array($data) || empty($data['ok']) || !is_array($media)) {
        if (is_array($data) && !empty($data['error'])) {
            error_log('PlayMoz Hyper scanner: ' . substr((string)$data['error'], 0, 300));
        }
        return null;
    }

    $url = (string)($media['url'] ?? '');
    $type = strtolower((string)($media['type'] ?? ''));
    if (!pm_hyper_public_url($url) || !in_array($type, ['mp4', 'hls', 'dash'], true)) return null;

    return [
        'url' => $url,
        'type' => $type,
        'mime' => (string)($media['mimeType'] ?? ''),
        'status' => (int)($media['status'] ?? 0),
        'referer' => $pageUrl
    ];
}

function pm_hyper_resolve(int $tmdbId, int $season, int $episode): ?string {
    $pageUrl = pm_hyper_episode_page_url($tmdbId, $season, $episode);
    if (!$pageUrl) return null;

    $cacheKey = 'episode-media:' . $tmdbId . ':' . $season . ':' . $episode;
    if ($cached = pm_hyper_cache_get($cacheKey)) {
        $url = (string)($cached['url'] ?? '');
        if ($url !== '' && pm_hyper_public_url($url)) return $url;
    }

    $media = pm_hyper_scan_page($pageUrl);
    if (!$media) return null;
    pm_hyper_cache_put($cacheKey, ['url' => $media['url'], 'type' => $media['type']], 240);
    return $media['url'];
}

function pm_hyper_movie_resolve(int $tmdbId): ?string {
    if ($tmdbId <= 0) return null;
    $cacheKey = 'movie-media:' . $tmdbId;
    if ($cached = pm_hyper_cache_get($cacheKey)) {
        $url = (string)($cached['url'] ?? '');
        if ($url !== '' && pm_hyper_public_url($url)) return $url;
    }

    $tmdb = pm_hyper_movie_tmdb($tmdbId);
    if (!$tmdb) return null;
    $slug = pm_hyper_find_movie_slug($tmdbId, $tmdb);
    if (!$slug) return null;

    $assistUrl = PM_HYPER_ORIGIN . '/filmes/' . rawurlencode($slug) . '/assistir';
    $media = pm_hyper_scan_page($assistUrl);
    if (!$media) return null;

    pm_hyper_cache_put($cacheKey, ['url' => $media['url'], 'type' => $media['type']], 240);
    return $media['url'];
}

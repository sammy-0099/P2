<?php
/** Resolve uma fonte RedeFlix por TMDB; usa apenas tickets da página pública e a sessão da própria requisição. */
function pm_redeflix_resolve($id, $season = null, $episode = null) {
    if (!function_exists('curl_init') || !filter_var($id, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]])) return null;
    $id = (int)$id;
    if ($season !== null && $episode !== null) {
        if (!filter_var($season,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) || !filter_var($episode,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])) return null;
        $page = 'https://redeflixapi.bar/serie/'.$id.'/'.(int)$season.'/'.(int)$episode;
    } else {
        $page = 'https://redeflixapi.bar/filme/'.$id;
    }
    $cookie = tempnam(sys_get_temp_dir(), 'pm_rf_');
    if (!$cookie) return null;
    try {
        $headers=['User-Agent: Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36','Accept: text/html,application/json,*/*'];
        $get = curl_init($page);
        curl_setopt_array($get,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>18,CURLOPT_ENCODING=>'',CURLOPT_HTTPHEADER=>$headers,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie]);
        $html = curl_exec($get); $status=curl_getinfo($get,CURLINFO_HTTP_CODE); curl_close($get);
        if ($status !== 200 || !is_string($html) || !preg_match('/var\s+fastLaunchTickets\s*=\s*(\{.*?\})\s*;/s',$html,$m)) return null;
        $tickets=json_decode($m[1],true);
        if (!is_array($tickets) || empty($tickets['default']) || !is_string($tickets['default'])) return null;
        $post=curl_init('https://redeflixapi.bar/playerkys/source.php');
        curl_setopt_array($post,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_ENCODING=>'',CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['ticket'=>$tickets['default']]),CURLOPT_REFERER=>$page,CURLOPT_HTTPHEADER=>array_merge($headers,['Content-Type: application/json','X-Requested-With: RedeFlixPlayer','Origin: https://redeflixapi.bar'])]);
        $response=curl_exec($post); $http=curl_getinfo($post,CURLINFO_HTTP_CODE);curl_close($post);
        if ($http !== 200 || !is_string($response)) return null;
        $data=json_decode($response,true);
        $url=is_array($data)?($data['url']??null):null;
        if (!is_string($url) || !filter_var($url,FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true)) return null;
        return $url;
    } finally { @unlink($cookie); }
}

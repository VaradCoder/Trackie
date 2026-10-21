<?php
/**
 * Server-side JSON fetch for the free public APIs the hobby modules use
 * (TheMealDB, Open-Meteo). Calls go out from PHP, so the browser's CSP and
 * the user's IP stay out of it. Responses are cached on disk for $ttl
 * seconds, so a busy page doesn't hammer a free API and a flaky one
 * degrades to "no suggestions" instead of an error.
 */
function httpJson(string $url, int $ttl = 3600, int $timeout = 5): ?array {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'trackie-http';
    $file = $dir . DIRECTORY_SEPARATOR . sha1($url) . '.json';
    if ($ttl > 0 && is_file($file) && time() - filemtime($file) < $ttl) {
        $hit = json_decode((string)@file_get_contents($file), true);
        if (is_array($hit)) return $hit;
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true,
        'header' => "Accept: application/json\r\nUser-Agent: Trackie/1.0\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    $status = isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $m) ? (int)$m[1] : 0;
    if ($raw === false || $status < 200 || $status >= 300) return null;
    $data = json_decode($raw, true);
    if (!is_array($data)) return null;
    if ($ttl > 0) {
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        @file_put_contents($file, $raw, LOCK_EX);
    }
    return $data;
}

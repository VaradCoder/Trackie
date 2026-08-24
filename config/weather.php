<?php
/**
 * Trackie — Weather data helper
 * Uses Open-Meteo (open-meteo.com) — free, no API key required, CC BY 4.0
 * attributed data. Returns null (never fake data) when unavailable.
 *
 * Attribution (per Open-Meteo's license): weather data by open-meteo.com.
 * The credit line lives in dash_weather.php next to the data it renders.
 */

/**
 * Map a WMO weather code (the numeric code Open-Meteo returns) to a
 * Font Awesome icon + human description. Matches this app's icon-first
 * design language instead of fetching a remote icon image per request.
 */
function wmoWeatherMeta(int $code): array {
    return match (true) {
        $code === 0             => ['icon' => 'fa-sun',                 'desc' => 'Clear sky'],
        $code === 1              => ['icon' => 'fa-cloud-sun',           'desc' => 'Mainly clear'],
        $code === 2              => ['icon' => 'fa-cloud-sun',           'desc' => 'Partly cloudy'],
        $code === 3              => ['icon' => 'fa-cloud',               'desc' => 'Overcast'],
        in_array($code, [45,48]) => ['icon' => 'fa-smog',                'desc' => 'Fog'],
        in_array($code, [51,53,55,56,57]) => ['icon' => 'fa-cloud-rain', 'desc' => 'Drizzle'],
        in_array($code, [61,63,80,81])    => ['icon' => 'fa-cloud-rain', 'desc' => 'Rain'],
        in_array($code, [65,82])          => ['icon' => 'fa-cloud-showers-heavy', 'desc' => 'Heavy rain'],
        in_array($code, [66,67])          => ['icon' => 'fa-icicles',    'desc' => 'Freezing rain'],
        in_array($code, [71,73,77,85])    => ['icon' => 'fa-snowflake',  'desc' => 'Snow'],
        in_array($code, [75,86])          => ['icon' => 'fa-snowflake',  'desc' => 'Heavy snow'],
        in_array($code, [95,96,99])       => ['icon' => 'fa-cloud-bolt', 'desc' => 'Thunderstorm'],
        default                            => ['icon' => 'fa-cloud',     'desc' => 'Cloudy'],
    };
}

function fetchWeatherData(): ?array {
    // Try to get city from session cache (1-hour TTL)
    $cached = $_SESSION['weather_cache'] ?? null;
    if ($cached && (time() - ($cached['ts'] ?? 0)) < 3600) {
        return $cached['data'];
    }

    // Determine location via IP geolocation (skip on localhost). ip-api.com
    // returns lat/lon directly, so no separate geocoding call is needed —
    // Open-Meteo's forecast endpoint takes coordinates, not a city name.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!$ip || in_array($ip, ['127.0.0.1', '::1', ''], true)) return null;

    $geo = @file_get_contents(
        "http://ip-api.com/json/{$ip}?fields=status,city,country,countryCode,lat,lon",
        false,
        stream_context_create(['http' => ['timeout' => 3]])
    );
    if (!$geo) return null;
    $geoData = json_decode($geo, true);
    if (($geoData['status'] ?? '') !== 'success') return null;

    $lat = $geoData['lat'] ?? null;
    $lon = $geoData['lon'] ?? null;
    if ($lat === null || $lon === null) return null;

    // Fetch weather
    $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
        'latitude'  => $lat,
        'longitude' => $lon,
        'current'   => 'temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m,surface_pressure',
        'timezone'  => 'auto',
    ]);
    $resp = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5]]));
    if (!$resp) return null;

    $w = json_decode($resp, true);
    $cur = $w['current'] ?? null;
    if (empty($cur)) return null;

    $meta = wmoWeatherMeta((int)($cur['weather_code'] ?? -1));

    $data = [
        'city'     => $geoData['city'] ?? '',
        'country'  => $geoData['countryCode'] ?? ($geoData['country'] ?? ''),
        'temp'     => (int)round($cur['temperature_2m']),
        'feels'    => (int)round($cur['apparent_temperature']),
        'humidity' => (int)round($cur['relative_humidity_2m']),
        'pressure' => (int)round($cur['surface_pressure']),
        'wind'     => round($cur['wind_speed_10m'] / 3.6, 1), // km/h → m/s, matching the existing unit shown in the UI
        'desc'     => $meta['desc'],
        'icon'     => $meta['icon'], // Font Awesome class, e.g. "fa-cloud-sun"
    ];

    $_SESSION['weather_cache'] = ['ts' => time(), 'data' => $data];
    return $data;
}

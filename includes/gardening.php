<?php
/**
 * Rain-aware watering. Outdoor plants that are due get a hint from the
 * Open-Meteo forecast (free, no key): skip watering when it rained enough
 * yesterday or rain is coming today. Advice only — nothing is logged for
 * the user; they still tap "Watered" (or don't).
 */
require_once __DIR__ . '/http.php';

const RAIN_SOAKED_MM = 5.0;    // yesterday's rain that counts as a watering
const RAIN_SKIP_MM   = 3.0;    // forecast rain today that makes watering pointless
const RAIN_SKIP_PROB = 70;     // …or this chance of rain

/** Free-text location → outdoors? ("Balcony", "back garden", "patio pots"…) */
function plantIsOutdoor(?string $location): bool {
    return (bool)preg_match('/\b(out(door|side)?s?|garden|balcon(y|ies)|patio|yard|terrace|porch|deck|roof|allotment|veg(etable)? (bed|patch)|raised bed)\b/i', (string)$location);
}

/**
 * The 3-day rain picture for the visitor's location, or null when unknown
 * (localhost, geolocation or forecast unreachable). Cached: location a day,
 * forecast three hours.
 * @return ?array{yesterday: float, today: float, tomorrow: float, prob_today: int, prob_tomorrow: int, city: string}
 */
function rainOutlook(): ?array {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '' || in_array($ip, ['127.0.0.1', '::1'], true) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
    $geo = httpJson('http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,city,lat,lon', 86400, 3);
    if (($geo['status'] ?? '') !== 'success' || !isset($geo['lat'], $geo['lon'])) return null;
    $w = httpJson('https://api.open-meteo.com/v1/forecast?' . http_build_query([
        'latitude' => round((float)$geo['lat'], 2), 'longitude' => round((float)$geo['lon'], 2),
        'daily' => 'precipitation_sum,precipitation_probability_max', 'past_days' => 1, 'forecast_days' => 2, 'timezone' => 'auto',
    ]), 3 * 3600);
    $mm = $w['daily']['precipitation_sum'] ?? null; $pr = $w['daily']['precipitation_probability_max'] ?? null;
    if (!is_array($mm) || count($mm) < 3) return null;
    return ['yesterday' => (float)$mm[0], 'today' => (float)$mm[1], 'tomorrow' => (float)$mm[2],
            'prob_today' => (int)($pr[1] ?? 0), 'prob_tomorrow' => (int)($pr[2] ?? 0), 'city' => (string)($geo['city'] ?? '')];
}

/**
 * One line of advice for a plant that is due, or null.
 * $plant needs: location, status, due (bool).
 */
function rainAdvice(array $plant, ?array $rain): ?string {
    if (!$rain || empty($plant['due']) || ($plant['status'] ?? '') === 'dormant' || !plantIsOutdoor($plant['location'] ?? null)) return null;
    $fmt = static fn(float $v) => rtrim(rtrim(number_format($v, 1), '0'), '.');
    if ($rain['yesterday'] >= RAIN_SOAKED_MM) return "It rained {$fmt($rain['yesterday'])} mm yesterday — check the soil before watering.";
    if ($rain['today'] >= RAIN_SKIP_MM) return "Rain expected today ({$fmt($rain['today'])} mm) — you can skip watering.";
    if ($rain['prob_today'] >= RAIN_SKIP_PROB) return "{$rain['prob_today']}% chance of rain today — maybe hold off.";
    if ($rain['tomorrow'] >= RAIN_SOAKED_MM) return "Heavy rain tomorrow ({$fmt($rain['tomorrow'])} mm) — a light watering will do.";
    return null;
}

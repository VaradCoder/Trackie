<?php
/**
 * Per-user preferences (user_settings). Every setting here changes real
 * behaviour somewhere — don't add toggles that do nothing.
 *
 *   currency             Finance amounts (money())
 *   week_start           Calendar grid, weekly stats (0 = Sunday, 1 = Monday)
 *   notify_reminders     reminder pop-ups + push
 *   notify_achievements  achievement / streak / level notifications
 *   timezone             IANA zone; '' = server default. applyUserTimezone()
 *                        sets it for PHP + the MySQL session on every request.
 *   quiet_start/_end     'HH:MM' local; reminders in this window go to the bell
 *                        only (no push / pop-up). '' = off.
 */

const SETTINGS_DEFAULTS = [
    'currency'            => 'INR',
    'week_start'          => 0,
    'notify_reminders'    => 1,
    'notify_achievements' => 1,
    'timezone'            => '',
    'quiet_start'         => '',
    'quiet_end'           => '',
];

/** code => [symbol, label, decimals] */
const CURRENCIES = [
    'INR' => ['₹',   'Indian rupee',       2],
    'USD' => ['$',   'US dollar',          2],
    'EUR' => ['€',   'Euro',               2],
    'GBP' => ['£',   'British pound',      2],
    'AUD' => ['A$',  'Australian dollar',  2],
    'CAD' => ['C$',  'Canadian dollar',    2],
    'SGD' => ['S$',  'Singapore dollar',   2],
    'AED' => ['AED ', 'UAE dirham',        2],
    'JPY' => ['¥',   'Japanese yen',       0],
    'CNY' => ['CN¥', 'Chinese yuan',       2],
    'BRL' => ['R$',  'Brazilian real',     2],
    'ZAR' => ['R ',  'South African rand', 2],
    'NPR' => ['Rs ', 'Nepalese rupee',     2],
    'PKR' => ['Rs ', 'Pakistani rupee',    2],
    'BDT' => ['৳',   'Bangladeshi taka',   2],
    'LKR' => ['Rs ', 'Sri Lankan rupee',   2],
];

function userSettings(int $uid, bool $fresh = false): array {
    static $cache = [];
    if (!$fresh && isset($cache[$uid])) return $cache[$uid];
    $row = null;
    try {
        if (tableExists('user_settings')) {
            try {
                $row = fetchOne("SELECT currency, week_start, notify_reminders, notify_achievements,
                                        timezone, TIME_FORMAT(quiet_start, '%H:%i') quiet_start, TIME_FORMAT(quiet_end, '%H:%i') quiet_end
                                   FROM user_settings WHERE user_id=?", [$uid]);
            } catch (Throwable $e) {
                // Before the notifications migration: the original columns only.
                $row = fetchOne("SELECT currency, week_start, notify_reminders, notify_achievements FROM user_settings WHERE user_id=?", [$uid]);
            }
            if ($row) $row = array_map(fn($v) => $v ?? '', $row);
        }
    } catch (Throwable $e) {
        $row = null;   // pre-migration database → defaults
    }
    $s = array_merge(SETTINGS_DEFAULTS, $row ?: []);
    $s['week_start'] = (int)$s['week_start'];
    $s['notify_reminders'] = (int)$s['notify_reminders'];
    $s['notify_achievements'] = (int)$s['notify_achievements'];
    if (!isset(CURRENCIES[$s['currency']])) $s['currency'] = SETTINGS_DEFAULTS['currency'];
    if ($s['timezone'] !== '' && !validTimezone($s['timezone'])) $s['timezone'] = '';
    return $cache[$uid] = $s;
}

function validTimezone(string $tz): bool {
    static $ids = null;
    $ids ??= array_flip(timezone_identifiers_list());
    return isset($ids[$tz]);
}

/** The server's own zone (config/app.php), before any per-user override. */
function serverTimezone(): string {
    return defined('APP_TIMEZONE') && APP_TIMEZONE ? APP_TIMEZONE : (getenv('APP_TIMEZONE') ?: 'Asia/Kolkata');
}

/**
 * Switch PHP and the MySQL session to this user's zone. Called by
 * requireAuth() on every signed-in request, and by the reminder engine per
 * user. Cached in the session so it costs no query on most requests.
 */
function applyUserTimezone(int $uid, bool $useSession = true): string {
    $tz = null;
    if ($useSession && isset($_SESSION['tz_uid'], $_SESSION['tz']) && (int)$_SESSION['tz_uid'] === $uid) {
        $tz = (string)$_SESSION['tz'];
    }
    if ($tz === null) {
        $tz = (string)userSetting($uid, 'timezone');
        if ($useSession && session_status() === PHP_SESSION_ACTIVE) { $_SESSION['tz_uid'] = $uid; $_SESSION['tz'] = $tz; }
    }
    $zone = ($tz !== '' && validTimezone($tz)) ? $tz : serverTimezone();
    if (date_default_timezone_get() !== $zone) date_default_timezone_set($zone);
    try { db()->exec("SET time_zone = '" . date('P') . "'"); } catch (Throwable $e) { error_log('applyUserTimezone: ' . $e->getMessage()); }
    return $zone;
}

/** Is it currently quiet hours for this user (in their zone)? Handles windows across midnight. */
function inQuietHours(int $uid, ?int $ts = null): bool {
    $a = (string)userSetting($uid, 'quiet_start');
    $b = (string)userSetting($uid, 'quiet_end');
    if ($a === '' || $b === '' || $a === $b) return false;
    $now = date('H:i', $ts ?? time());
    return $a < $b ? ($now >= $a && $now < $b) : ($now >= $a || $now < $b);
}

function userSetting(int $uid, string $key) {
    return userSettings($uid)[$key] ?? SETTINGS_DEFAULTS[$key] ?? null;
}

/** Validate + save. Unknown keys are ignored; bad values throw InvalidArgumentException. */
function saveUserSettings(int $uid, array $in): array {
    $cur = userSettings($uid, true);
    if (array_key_exists('currency', $in)) {
        $c = strtoupper(trim((string)$in['currency']));
        if (!isset(CURRENCIES[$c])) throw new InvalidArgumentException('Unsupported currency.');
        $cur['currency'] = $c;
    }
    if (array_key_exists('week_start', $in)) {
        $w = (int)$in['week_start'];
        if (!in_array($w, [0, 1], true)) throw new InvalidArgumentException('Week must start on Sunday or Monday.');
        $cur['week_start'] = $w;
    }
    foreach (['notify_reminders', 'notify_achievements'] as $k) {
        if (array_key_exists($k, $in)) $cur[$k] = in_array((string)$in[$k], ['1', 'true', 'on'], true) ? 1 : 0;
    }
    if (array_key_exists('timezone', $in)) {
        $tz = trim((string)$in['timezone']);
        if ($tz !== '' && !validTimezone($tz)) throw new InvalidArgumentException('Unknown time zone.');
        $cur['timezone'] = $tz;
    }
    foreach (['quiet_start', 'quiet_end'] as $k) {
        if (array_key_exists($k, $in)) {
            $t = trim((string)$in[$k]);
            if ($t !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) throw new InvalidArgumentException('Quiet hours must be times like 22:00.');
            $cur[$k] = $t;
        }
    }
    if (($cur['quiet_start'] === '') !== ($cur['quiet_end'] === '')) {
        throw new InvalidArgumentException('Set both a start and an end for quiet hours (or clear both).');
    }
    $params = [$uid, $cur['currency'], $cur['week_start'], $cur['notify_reminders'], $cur['notify_achievements']];
    try {
        update(
            "INSERT INTO user_settings (user_id, currency, week_start, notify_reminders, notify_achievements, timezone, quiet_start, quiet_end)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE currency=VALUES(currency), week_start=VALUES(week_start),
               notify_reminders=VALUES(notify_reminders), notify_achievements=VALUES(notify_achievements),
               timezone=VALUES(timezone), quiet_start=VALUES(quiet_start), quiet_end=VALUES(quiet_end)",
            array_merge($params, [$cur['timezone'] ?: null, $cur['quiet_start'] ?: null, $cur['quiet_end'] ?: null])
        );
    } catch (PDOException $e) {
        // Pre-migration database: save what the old schema can hold.
        update(
            "INSERT INTO user_settings (user_id, currency, week_start, notify_reminders, notify_achievements) VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE currency=VALUES(currency), week_start=VALUES(week_start),
               notify_reminders=VALUES(notify_reminders), notify_achievements=VALUES(notify_achievements)",
            $params
        );
    }
    if (session_status() === PHP_SESSION_ACTIVE && (int)($_SESSION['tz_uid'] ?? 0) === $uid) unset($_SESSION['tz'], $_SESSION['tz_uid']);
    return userSettings($uid, true);
}

/** Format an amount in the user's currency: ₹1,250 / $12.50 / ¥980. */
function money(float $n, ?int $uid = null): string {
    $code = $uid ? userSetting($uid, 'currency') : SETTINGS_DEFAULTS['currency'];
    [$sym, , $dec] = CURRENCIES[$code] ?? CURRENCIES['INR'];
    $neg = $n < 0;
    $n = abs($n);
    // Drop ".00" on whole amounts, like the old rupee() did.
    $digits = ($dec > 0 && (int)$n != $n) ? $dec : 0;
    return ($neg ? '-' : '') . $sym . number_format($n, $digits);
}

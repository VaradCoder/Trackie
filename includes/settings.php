<?php
/**
 * Per-user preferences (user_settings). Every setting here changes real
 * behaviour somewhere — don't add toggles that do nothing.
 *
 *   currency             Finance amounts (money())
 *   week_start           Calendar grid, weekly stats (0 = Sunday, 1 = Monday)
 *   notify_reminders     reminder pop-ups + push
 *   notify_achievements  achievement / streak / level notifications
 */

const SETTINGS_DEFAULTS = [
    'currency'            => 'INR',
    'week_start'          => 0,
    'notify_reminders'    => 1,
    'notify_achievements' => 1,
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
            $row = fetchOne("SELECT currency, week_start, notify_reminders, notify_achievements FROM user_settings WHERE user_id=?", [$uid]);
        }
    } catch (Throwable $e) {
        $row = null;   // pre-migration database → defaults
    }
    $s = array_merge(SETTINGS_DEFAULTS, $row ?: []);
    $s['week_start'] = (int)$s['week_start'];
    $s['notify_reminders'] = (int)$s['notify_reminders'];
    $s['notify_achievements'] = (int)$s['notify_achievements'];
    if (!isset(CURRENCIES[$s['currency']])) $s['currency'] = SETTINGS_DEFAULTS['currency'];
    return $cache[$uid] = $s;
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
    update(
        "INSERT INTO user_settings (user_id, currency, week_start, notify_reminders, notify_achievements) VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE currency=VALUES(currency), week_start=VALUES(week_start),
           notify_reminders=VALUES(notify_reminders), notify_achievements=VALUES(notify_achievements)",
        [$uid, $cur['currency'], $cur['week_start'], $cur['notify_reminders'], $cur['notify_achievements']]
    );
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

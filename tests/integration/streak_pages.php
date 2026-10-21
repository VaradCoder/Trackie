<?php
/**
 * Streaks as the pages actually render them — LOCAL dev database only.
 *   php tests/integration/streak_pages.php [base-url]
 *
 * Creates a throwaway user (random e-mail and password, never printed), gives
 * it a Mon/Wed/Fri habit and a Mon/Wed/Fri workout plan, both done every time
 * for three weeks, signs in over HTTP and reads the streak each page shows.
 * The user is deleted afterwards (ON DELETE CASCADE removes its rows).
 * Refuses to run against anything but a local host.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never over HTTP: it writes to the DB
$base = rtrim($argv[1] ?? 'http://localhost/Trackie', '/');
$host = parse_url($base, PHP_URL_HOST);
if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true) && !preg_match('/\.(localhost|test)$/', (string)$host)) {
    fwrite(STDERR, "Refusing: $base is not a local development host.\n"); exit(2);
}
chdir(__DIR__ . '/../../pages');
require '../config/app.php';
require '../config/database.php';
require '../includes/functions.php';
require __DIR__ . '/_harness.inc';

$pass = $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; } }

// Three weeks of Mon/Wed/Fri before today, in the server's zone (the new user
// has no timezone setting, so pages use the same one).
$dates = [];
for ($i = 21; $i >= 1; $i--) { $t = strtotime("-$i day"); if (in_array((int)date('w', $t), [1, 3, 5], true)) $dates[] = date('Y-m-d', $t); }
$want = count($dates);

$email = 'streak-it-' . bin2hex(random_bytes(4)) . '@trackie.local';
$pw    = bin2hex(random_bytes(12));
$uid   = (int)insert("INSERT INTO users (name,email,password) VALUES (?,?,?)", ['Streak Test', $email, password_hash($pw, PASSWORD_DEFAULT)]);
$jar   = tempnam(sys_get_temp_dir(), 'trk');
try {
    $hid = (int)insert("INSERT INTO habits (user_id,name,frequency,schedule_days,created_at) VALUES (?,?,?,?,?)", [$uid, 'Stretch', 'daily', '1,3,5', '2026-01-01 08:00:00']);
    foreach (['Monday', 'Wednesday', 'Friday'] as $d)
        insert("INSERT INTO workout_plans (user_id,name,day_of_week,created_at) VALUES (?,?,?,?)", [$uid, "$d lift", $d, '2026-01-01 08:00:00']);
    foreach ($dates as $d) {
        insert("INSERT INTO logs (user_id,habit_id,date_completed) VALUES (?,?,?)", [$uid, $hid, $d]);
        insert("INSERT INTO workout_logs (user_id,exercise_name,sets,reps,log_date) VALUES (?,?,3,10,?)", [$uid, 'Squat', $d]);
    }

    $get = function (string $path, ?array $post = null) use ($base, $jar) {
        $c = curl_init($base . $path);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest']] + ($post ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post] : []));
        $r = (string)curl_exec($c); curl_close($c); return $r;
    };
    preg_match('/csrf-token" content="([^"]+)"/', $get('/pages/auth.php'), $m);
    $login = $get('/api/auth.php', ['action' => 'login', 'email' => $email, 'password' => $pw, 'csrf_token' => $m[1] ?? '']);
    releaseTestLogin($email);
    ok(str_contains($login, '"success":true'), 'throwaway user signs in');

    $num = static fn(string $re, string $html) => preg_match($re, $html, $x) ? (int)$x[1] : null;
    $s = '\s*';
    // Overview shows the overall activity streak (any module), not the habit streak.
    $ovHtml = $get('/pages/dashboard.php');
    $dash = $num('~Streak</span>\s*<div class="ov-big[^"]*"><span class="stat-val">(\d+)</span>~', $ovHtml);
    $hab  = $num('~id="habitsStatsWrap".*?<strong>(\d+)</strong>-day streak~s', $get('/pages/habits.php'));
    $ana  = $num("~Current streak</span>.*?stat-val[^>]*>(\d+) d<~s", $get('/pages/analytics.php'));
    $gymH = $get('/pages/gym.php');
    $gym  = $num('~<strong>(\d+)</strong> Workout Streak~', $gymH);

    require_once '../includes/activity.php';
    ok($dash !== null && $dash === (int)activityStreak($uid)['current'] && str_contains($ovHtml, 'id="page-main"'),
       'Overview streak tile = activityStreak() (got ' . var_export($dash, true) . ')');
    ok($hab  === $want, "Habits header streak = $want (got " . var_export($hab, true) . ')');
    ok($ana  === $want, "Analytics current streak = $want (got " . var_export($ana, true) . ')');
    ok($gym  === $want, "Fitness badge = $want, labelled Workout Streak (got " . var_export($gym, true) . ')');
    ok(habitStreaks($uid)['current'] === $want && workoutStreak($uid)['current'] === $want, 'habitStreaks() / workoutStreak() agree with the pages');
    ok(calculateStreaks($dates)['current'] === 0, '(control) the old calculation on the same data gives 0');

    // A missed planned day still breaks both.
    $gap = $dates[count($dates) - 2];
    update("DELETE FROM logs WHERE user_id=? AND date_completed=?", [$uid, $gap]);
    update("DELETE FROM workout_logs WHERE user_id=? AND log_date=?", [$uid, $gap]);
    ok(habitStreaks($uid)['current'] === 1 && workoutStreak($uid)['current'] === 1, 'removing one planned day → both streaks restart at 1');
} finally {
    update("DELETE FROM users WHERE id=?", [$uid]);
    @unlink($jar);
    ok(!fetchOne("SELECT id FROM habits WHERE user_id=?", [$uid]) && !fetchOne("SELECT id FROM users WHERE id=?", [$uid]), 'throwaway user and its rows removed');
}
echo "== $pass passed, $fail failed\n";
exit($fail ? 1 : 0);

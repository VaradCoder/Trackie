<?php
/**
 * The Today board as the server renders it — LOCAL dev DB only.
 *   php tests/integration/today_board.php [base-url]
 * Throwaway user (random credentials, never printed), deleted afterwards.
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

$email = 'today-it-' . bin2hex(random_bytes(4)) . '@trackie.local';
$pw    = bin2hex(random_bytes(12));
$uid   = (int)insert("INSERT INTO users (name,email,password) VALUES (?,?,?)", ['Today Tester', $email, password_hash($pw, PASSWORD_DEFAULT)]);
$jar   = tempnam(sys_get_temp_dir(), 'trk');
$today = date('Y-m-d'); $yest = date('Y-m-d', strtotime('-1 day')); $tomorrow = date('Y-m-d', strtotime('+1 day'));
try {
    $todo = static fn(string $t, ?string $due, string $rec = 'none', int $done = 0, ?string $at = null, string $pri = 'medium') =>
        (int)insert("INSERT INTO todos (user_id,title,due_date,recurring,completed,completed_at,priority) VALUES (?,?,?,?,?,?,?)", [$uid, $t, $due, $rec, $done, $at, $pri]);
    $tDue   = $todo('Due today <b>bold</b>', $today, 'none', 0, null, 'high');
    $tLate  = $todo('Late open', $yest);
    $tLateD = $todo('Late, finished today', $yest, 'none', 1, date('Y-m-d H:i:s'));
    $tOld   = $todo('Late, finished yesterday', date('Y-m-d', strtotime('-3 day')), 'none', 1, "$yest 10:00:00");
    $tFut   = $todo('Tomorrow thing', $tomorrow);
    $tRec   = $todo('Daily recurring', null, 'daily');
    $h1 = (int)insert("INSERT INTO habits (user_id,name,frequency,schedule_days) VALUES (?,?,?,NULL)", [$uid, 'Every-day habit', 'daily']);
    $offDay = (string)((((int)date('w')) + 1) % 7);
    $h2 = (int)insert("INSERT INTO habits (user_id,name,frequency,schedule_days) VALUES (?,?,?,?)", [$uid, 'Not today habit', 'daily', $offDay]);
    insert("INSERT INTO logs (user_id,habit_id,date_completed) VALUES (?,?,?)", [$uid, $h1, $today]);
    $r1 = (int)insert("INSERT INTO routines (user_id,title,time_slot,schedule_days) VALUES (?,?,?,NULL)", [$uid, 'Stretch', '07:30:00']);
    $wp = (int)insert("INSERT INTO workout_plans (user_id,name,day_of_week) VALUES (?,?,?)", [$uid, 'Leg day', date('l')]);

    $http = function (string $path, ?array $post = null) use ($base, $jar) {
        $c = curl_init($base . $path);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest']] + ($post ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post] : []));
        $r = (string)curl_exec($c); curl_close($c); return $r;
    };
    $csrf = static fn(string $h) => preg_match('/csrf-token" content="([^"]+)"/', $h, $m) ? $m[1] : '';
    ok(str_contains($http('/api/auth.php', ['action' => 'login', 'email' => $email, 'password' => $pw, 'csrf_token' => $csrf($http('/pages/auth.php'))]), '"success":true'), 'throwaway user signs in');
    releaseTestLogin($email);

    $html = $http('/pages/today.php');
    $list = static function (string $id) use ($html) {
        if (!preg_match('~<ul class="td-list" id="' . $id . '">(.*?)</ul>~s', $html, $m)) return null;
        preg_match_all('~data-key="([^"]+)"~', $m[1], $k);
        return $k[1];
    };
    $over = $list('tdOverdue'); $open = $list('tdOpen'); $done = $list('tdDone');
    ok($over === ["todo-$tLate"], 'Overdue: the open late todo only');
    ok(in_array("todo-$tDue", $open, true) && in_array("todo-$tRec", $open, true) && in_array("routine-$r1", $open, true) && in_array("workout-$wp", $open, true),
       'Up next: due-today todo, daily recurring todo, routine and today\'s workout');
    ok(in_array("habit-$h1", $done, true) && in_array("todo-$tLateD", $done, true), 'Done: habit logged today, late todo finished today');
    $all = array_merge($over ?? [], $open ?? [], $done ?? []);
    ok(!in_array("todo-$tOld", $all, true) && !in_array("todo-$tFut", $all, true) && !in_array("habit-$h2", $all, true),
       'Not shown: late todo finished yesterday, tomorrow\'s todo, habit not scheduled today');
    ok(str_contains($html, 'Not due today: Not today habit'), 'unscheduled habit listed as "not due today"');
    ok(preg_match('~aria-label="(\d+) of (\d+) done today"~', $html, $m) && $m[1] === '2' && $m[2] === (string)count($all), "ring counts $m[1] of $m[2] (2 done of every item on the board)");
    ok(array_search("routine-$r1", $open, true) < array_search("todo-$tDue", $open, true), 'timed routine sorts before todos');
    ok(str_contains($html, 'Due today &lt;b&gt;bold&lt;/b&gt;') && !str_contains($html, 'Due today <b>bold</b>'), 'titles are escaped');
    ok(str_contains($html, 'td-pri high'), 'high priority is labelled');
    ok(str_contains($html, 'aria-label="Start Leg day"') && str_contains($html, '/pages/gym.php'), 'workout row links to the Fitness page');
    ok(preg_match_all('~role="checkbox" aria-checked="(true|false)"~', $html) === count($all) - 1, 'every row except the workout has a real checkbox');
    ok(str_contains($html, 'id="tdAddInput"') && is_file(__DIR__ . '/../../assets/vendor/chrono.min.js'), 'quick add present; its parser is self-hosted');

    // The dashboard (home): Today's Todos = the board's non-habit items, Should Do = its habits.
    $dash = $http('/pages/dashboard.php');
    $keysIn = static function (string $id) use ($dash) {
        if (!preg_match('~id="' . $id . '".*?</section>~s', $dash, $m)) return [];
        preg_match_all('~data-key="([^"]+)"~', $m[0], $k); return $k[1];
    };
    $todoKeys = $keysIn('bdTodos'); sort($todoKeys);
    $expect = array_values(array_filter($all, static fn($k) => !str_starts_with($k, 'habit-'))); sort($expect);
    ok($todoKeys === $expect, "dashboard Today's Todos = Today's non-habit items (" . count($todoKeys) . ')');
    preg_match('~id="bdShould".*?</section>~s', $dash, $sm);
    preg_match_all('~bd-should-item[^"]*" style="[^"]*" data-id="(\d+)"~', $sm[0] ?? '', $hm);
    ok(array_map('intval', $hm[1]) === [$h1], 'dashboard Should Do = habits due today (not the unscheduled one)');
    ok(str_contains($dash, 'Habits Wrapped') && str_contains($dash, 'id="bdFavBars"') && !preg_match('/fatal error|parse error|undefined (variable|array key|index)/i', $dash), 'dashboard analytics render without PHP errors');

    // Your space: sections follow the user's picks and focus.
    $space = static function (string $html) {
        preg_match_all('~<section class="bd-card ys-sec[^"]*" data-sec="(\w+)".*?</section>~s', $html, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $s) { preg_match_all('~data-page="([\w]+)"~', $s[0], $p); $out[$s[1]] = $p[1]; }
        return $out;
    };
    $ys = $space($dash);
    ok(array_keys($ys) === ['plan', 'life', 'hobbies'] && !in_array('today', $ys['plan'], true) && in_array('todos', $ys['plan'], true),
       'Your space: no focus → Plan, Life, Hobbies; Today stays in the bento');
    ok($ys['life'] === ['finance'] && $ys['hobbies'] === ['music'] && str_contains($dash, 'Pick your hobbies'), 'no hobbies picked → Finance + Music only, with a "pick" card');
    ok(preg_match('~data-page="todos".*?<strong>(\d+)</strong> <small>open</small>.*?<span class="ys-sub">(\d+) overdue~s', $dash, $tm) && $tm[1] === '4' && $tm[2] === '1',
       "Todos tile counts real rows ({$tm[1]} open, {$tm[2]} overdue)");
    update("UPDATE users SET hobbies='Reading,Sports', primary_focus='creativity' WHERE id=?", [$uid]);
    $sid = (int)insert("INSERT INTO sports_sessions (user_id,sport,duration_min,session_date) VALUES (?,?,?,?)", [$uid, 'Tennis', 45, $today]);
    $dash2 = $http('/pages/dashboard.php'); $ys = $space($dash2);
    ok(array_keys($ys) === ['hobbies', 'plan', 'life'], 'creativity focus → Hobbies section first');
    ok($ys['hobbies'] === ['library', 'music'] && $ys['life'] === ['finance', 'sports'] && !str_contains($dash2, 'Pick your hobbies'),
       'picked Reading → Hobbies tile, picked Sports → Life tile, unpicked modules absent');
    ok(preg_match('~data-page="sports".*?<strong>1</strong> <small>sessions</small>.*?45m this week~s', $dash2), 'Sports tile shows the logged session (1, 45m)');

    // Habit history API (the year heatmap): unscheduled days come back as "off".
    $hist = json_decode($http('/api/habits.php', ['action' => 'history', 'habit_id' => $h2, 'days' => 14, 'csrf_token' => $csrf($html)]), true) ?: [];
    $offWd = array_unique(array_map(static fn($d) => (int)date('w', strtotime($d)), $hist['off'] ?? []));
    ok(($hist['today'] ?? '') === $today && count($hist['off'] ?? []) === 12 && !in_array((int)$offDay, $offWd, true),
       'history: 12 of 14 days are "off" for a one-weekday habit, never its own weekday');
    // Habit log dates: no future days (each date earns its own XP), real dates only.
    $hl = fn(array $p) => json_decode($http('/api/habits.php', $p + ['habit_id' => $h2, 'csrf_token' => $csrf($html)]), true) ?: [];
    ok(($hl(['action' => 'log', 'date' => $tomorrow, 'status' => 'done'])['success'] ?? true) === false
       && !fetchOne("SELECT id FROM logs WHERE habit_id=? AND date_completed=?", [$h2, $tomorrow]), 'habit log for tomorrow refused, nothing stored');
    ok(($hl(['action' => 'log', 'date' => '2026-02-30', 'status' => 'done'])['success'] ?? true) === false, 'habit log for an impossible date refused');
    ok(($hl(['action' => 'log', 'date' => $yest, 'status' => 'done'])['success'] ?? false)
       && fetchOne("SELECT id FROM logs WHERE habit_id=? AND date_completed=?", [$h2, $yest]), 'habit log for yesterday accepted (fixing a missed tick)');
    ok(($hl(['action' => 'unlog', 'date' => $yest])['success'] ?? false) && !fetchOne("SELECT id FROM logs WHERE habit_id=? AND date_completed=?", [$h2, $yest]), 'and un-logged again');
    $hist1 = json_decode($http('/api/habits.php', ['action' => 'history', 'habit_id' => $h1, 'days' => 7, 'csrf_token' => $csrf($html)]), true) ?: [];
    ok(($hist1['dates'] ?? null) === [$today] && ($hist1['off'] ?? null) === [] && ($hist1['skipped'] ?? null) === [], 'history: every-day habit → no off days, today logged');
} finally {
    update("DELETE FROM users WHERE id=?", [$uid]);
    @unlink($jar);
    ok(!fetchOne("SELECT id FROM users WHERE id=?", [$uid]) && !fetchOne("SELECT id FROM todos WHERE user_id=?", [$uid]), 'throwaway user and its rows removed');
}
echo "== $pass passed, $fail failed\n";
exit($fail ? 1 : 0);

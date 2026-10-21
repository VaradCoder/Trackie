<?php
/**
 * Routine skips and rest days through the real API and pages — LOCAL dev DB only.
 *   php tests/integration/routine_exceptions.php [base-url]
 * Needs migration 2026-10-11_routine_exceptions.sql. Throwaway user, deleted after.
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
require_once '../includes/routines.php';
require_once '../includes/calendar.php';

$pass = $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; } }
if (!routineSkipsReady() || !routineRestReady()) { echo "FAIL  migration 2026-10-11_routine_exceptions.sql not applied to the local DB\n== 0 passed, 1 failed\n"; exit(1); }

$email = 'routine-it-' . bin2hex(random_bytes(4)) . '@trackie.local';
$pw    = bin2hex(random_bytes(12));
$uid   = (int)insert("INSERT INTO users (name,email,password) VALUES (?,?,?)", ['Routine Test', $email, password_hash($pw, PASSWORD_DEFAULT)]);
$jar   = tempnam(sys_get_temp_dir(), 'trk');
$today = date('Y-m-d'); $yest = date('Y-m-d', strtotime('-1 day')); $tomorrow = date('Y-m-d', strtotime('+1 day'));
$wdY   = (string)date('w', strtotime($yest));
try {
    $http = function (string $path, ?array $post = null) use ($base, $jar) {
        $c = curl_init($base . $path);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest']] + ($post ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post] : []));
        $r = (string)curl_exec($c); curl_close($c); return $r;
    };
    $csrf = static fn(string $h) => preg_match('/csrf-token" content="([^"]+)"/', $h, $m) ? $m[1] : '';
    ok(str_contains($http('/api/auth.php', ['action' => 'login', 'email' => $email, 'password' => $pw, 'csrf_token' => $csrf($http('/pages/auth.php'))]), '"success":true'), 'throwaway user signs in');
    releaseTestLogin($email);
    $tok = $csrf($http('/pages/routines.php'));
    $api = fn(array $p) => json_decode($http('/api/routines.php', $p + ['csrf_token' => $tok]), true) ?: [];

    $A = (int)insert("INSERT INTO routines (user_id,title,time_slot,schedule_days) VALUES (?,?,?,NULL)", [$uid, 'Morning stretch', '07:00:00']);
    $B = (int)insert("INSERT INTO routines (user_id,title,time_slot,schedule_days) VALUES (?,?,?,NULL)", [$uid, 'Evening read', '21:00:00']);
    $C = (int)insert("INSERT INTO routines (user_id,title,time_slot,schedule_days) VALUES (?,?,?,?)", [$uid, 'Long run', '06:00:00', $wdY]);
    $st   = static fn(int $id, string $d) => fetchOne("SELECT status FROM routine_logs WHERE user_id=? AND routine_id=? AND log_date=?", [$uid, $id, $d])['status'] ?? null;
    $n    = static fn(int $id) => (int)fetchOne("SELECT COUNT(*) c FROM routine_logs WHERE routine_id=?", [$id])['c'];
    $act  = static fn(int $id, string $d) => (bool)fetchOne("SELECT id FROM activity_log WHERE user_id=? AND action='routine' AND ref_id=? AND occurred_on=?", [$uid, $id, $d]);
    $xp   = static fn() => (int)(fetchOne("SELECT SUM(xp) s FROM xp_events WHERE user_id=? AND action='routine'", [$uid])['s'] ?? 0);

    // ── Complete: idempotent
    $api(['action' => 'complete', 'routine_id' => $A]); $api(['action' => 'complete', 'routine_id' => $A]);
    ok($st($A, $today) === 'done' && $n($A) === 1 && $act($A, $today) && $xp() === 12, 'complete ×2 → one done row, one activity, +12 XP once');

    // ── Skip after done → completion withdrawn; redo → done, not re-paid
    $r = $api(['action' => 'skip', 'routine_id' => $A]);
    ok(($r['success'] ?? false) && $st($A, $today) === 'skipped' && $n($A) === 1 && !$act($A, $today), 'skip a done routine → same row becomes skipped, activity removed');
    $api(['action' => 'uncomplete', 'routine_id' => $A]);
    ok($st($A, $today) === 'skipped', '"uncomplete" does not clear a skip');
    $api(['action' => 'complete', 'routine_id' => $A]);
    ok($st($A, $today) === 'done' && $n($A) === 1 && $act($A, $today) && $xp() === 12, 'complete a skipped routine → done again, XP not paid twice');
    $api(['action' => 'unskip', 'routine_id' => $A]);
    ok($st($A, $today) === 'done', '"unskip" does not clear a completion');

    // ── One-day skip on a future day; weekly plan untouched
    $r = $api(['action' => 'skip', 'routine_id' => $C, 'date' => $tomorrow]);
    $c = fetchOne("SELECT schedule_days FROM routines WHERE id=?", [$C]);
    ok(($r['success'] ?? false) && $st($C, $tomorrow) === 'skipped' && $c['schedule_days'] === $wdY, 'skip a future day → recorded for that date only; schedule_days unchanged');
    $nextWeek = date('Y-m-d', strtotime('+6 day'));   // same weekday as yesterday, next week
    ok(routineDayState(['id' => $C, 'schedule_days' => $wdY], $nextWeek, routineLogStates($uid, $nextWeek, $nextWeek), []) === 'due', 'the same weekday next week is still due');
    $api(['action' => 'unskip', 'routine_id' => $C, 'date' => $tomorrow]);
    ok($st($C, $tomorrow) === null, 'unskip removes only the skip');

    // ── Skip B today → progress counts it as not expected
    $api(['action' => 'skip', 'routine_id' => $B]);
    $p = routineProgress(array_column(array_filter(routinesOnDay($uid, $today), static fn($r) => $r['state'] !== 'off'), 'state'));
    ok($p['done'] === 1 && $p['expected'] === 1, 'A done + B skipped → 1 of 1 expected');
    $html = $http('/pages/routines.php');
    ok(preg_match('~<strong class="u-c-text">1</strong> of 1 done~', $html) && str_contains($html, 'Skipped today'), 'Routines page: "1 of 1 done" and B shown as skipped');
    ok(str_contains($http('/pages/today.php'), 'skipped today'), 'Today page labels the skipped routine');

    // ── Rest day: nothing expected; an extra completion still counts
    $r = $api(['action' => 'rest_day', 'on' => 1]); $api(['action' => 'rest_day', 'on' => 1]);
    ok(($r['success'] ?? false) && (int)fetchOne("SELECT COUNT(*) c FROM routine_rest_days WHERE user_id=?", [$uid])['c'] === 1, 'rest day ×2 → one rest-day row');
    $api(['action' => 'unskip', 'routine_id' => $B]);
    $states = array_column(routinesOnDay($uid, $today), 'state', 'id');
    ok($states[$B] === 'rest' && $states[$A] === 'done', 'on a rest day: B (nothing recorded) is rest, A stays done');
    $html = $http('/pages/routines.php');
    ok(str_contains($html, 'Rest day') && str_contains($html, 'Back to normal'), 'Routines page shows the rest day with an undo');
    ok(str_contains($http('/pages/today.php'), 'Rest day — nothing here is expected today'), 'Today page shows the rest day');
    $cal = array_values(array_filter(calendarEvents($uid, $today, $today), static fn($e) => $e['source'] === 'routine' && $e['id'] === $B));
    ok(($cal[0]['state'] ?? '') === 'rest' && str_ends_with($cal[0]['title'], '(rest day)') && !$cal[0]['done'], 'Calendar: B labelled rest day, not shown as an unfinished task');
    $api(['action' => 'rest_day', 'on' => 0]);
    ok(!fetchOne("SELECT 1 FROM routine_rest_days WHERE user_id=?", [$uid]), 'rest day undone');

    // ── History survives a schedule change
    $api(['action' => 'complete', 'routine_id' => $C, 'date' => $yest]);
    update("UPDATE routines SET schedule_days=? WHERE id=?", [(string)((((int)$wdY) + 3) % 7), $C]);   // move it to another weekday
    $cal = array_values(array_filter(calendarEvents($uid, $yest, $yest), static fn($e) => $e['source'] === 'routine' && $e['id'] === $C));
    ok(($cal[0]['done'] ?? false) === true, 'Calendar: yesterday\'s completion still shows after the routine moved to another weekday');
    ok($act($C, $yest), '…and its activity is kept');

    // ── Backfill copies completions only
    update("DELETE FROM activity_log WHERE user_id=?", [$uid]);
    update("UPDATE user_settings SET activity_backfilled_at=NULL WHERE user_id=?", [$uid]);
    $api(['action' => 'skip', 'routine_id' => $B]);
    require_once '../includes/activity.php';
    ensureActivityBackfill($uid);
    $rows = fetchAll("SELECT ref_id FROM activity_log WHERE user_id=? AND action='routine' ORDER BY ref_id", [$uid]);
    ok(array_map('intval', array_column($rows, 'ref_id')) === [$A, $C], 'activity backfill copies done rows only (A today, C yesterday), not B\'s skip');

    // ── Validation
    ok(($api(['action' => 'skip', 'routine_id' => 999999999])['success'] ?? true) === false, 'skip someone else\'s / unknown routine → refused');
    ok(($api(['action' => 'skip', 'routine_id' => $A, 'date' => 'nope'])['success'] ?? true) === false, 'malformed date → refused');
    ok(($api(['action' => 'complete', 'routine_id' => $A, 'date' => $tomorrow])['success'] ?? true) === false, 'completing a future day is still refused');

    // Times go into a TIME column: only real clock times are accepted, normalised.
    ok(($api(['action' => 'add', 'title' => 'x', 'time_slot' => 'abc'])['success'] ?? true) === false
       && ($api(['action' => 'add', 'title' => 'x', 'time_slot' => '25:00'])['success'] ?? true) === false, 'routine time "abc" / "25:00" refused');
    $okAdd = $api(['action' => 'add', 'title' => 'Early', 'time_slot' => '7:05', 'schedule_days' => '1,3,5']);
    ok(($okAdd['success'] ?? false) && fetchOne("SELECT time_slot, schedule_days FROM routines WHERE id=?", [(int)$okAdd['id']]) == ['time_slot' => '07:05:00', 'schedule_days' => '1,3,5'],
       'routine time "7:05" stored as 07:05:00 with its days');
} finally {
    update("DELETE FROM users WHERE id=?", [$uid]);
    @unlink($jar);
    ok(!fetchOne("SELECT id FROM routine_logs WHERE user_id=?", [$uid]) && !fetchOne("SELECT 1 FROM routine_rest_days WHERE user_id=?", [$uid]), 'throwaway user and its rows removed');
}
echo "== $pass passed, $fail failed\n";
exit($fail ? 1 : 0);

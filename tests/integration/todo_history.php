<?php
/**
 * Recurring-todo completion history through the real API — LOCAL dev DB only.
 *   php tests/integration/todo_history.php [base-url]
 *
 * Throwaway user (random credentials, never printed), deleted afterwards.
 * "Yesterday / last week / last month" are simulated by moving that user's
 * own rows back in time, since the server clock can't be changed.
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

$email = 'todo-it-' . bin2hex(random_bytes(4)) . '@trackie.local';
$pw    = bin2hex(random_bytes(12));
$uid   = (int)insert("INSERT INTO users (name,email,password) VALUES (?,?,?)", ['Todo Test', $email, password_hash($pw, PASSWORD_DEFAULT)]);
$jar   = tempnam(sys_get_temp_dir(), 'trk');
$today = date('Y-m-d');
try {
    $http = function (string $path, ?array $post = null) use ($base, $jar) {
        $c = curl_init($base . $path);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest']] + ($post ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post] : []));
        $r = (string)curl_exec($c); curl_close($c); return $r;
    };
    $csrf = static fn(string $html) => preg_match('/csrf-token" content="([^"]+)"/', $html, $m) ? $m[1] : '';
    $login = $http('/api/auth.php', ['action' => 'login', 'email' => $email, 'password' => $pw, 'csrf_token' => $csrf($http('/pages/auth.php'))]);
    releaseTestLogin($email);
    ok(str_contains($login, '"success":true'), 'throwaway user signs in');
    $tok = $csrf($http('/pages/todos.php'));
    $api = function (array $p) use ($http, $tok) { return json_decode($http('/api/todos.php', $p + ['csrf_token' => $tok]), true) ?: []; };
    $toggle = fn(int $id, int $done) => $api(['action' => 'toggle', 'todo_id' => $id, 'completed' => $done]);

    // Todo XP only — achievements unlocked along the way pay their own 50 XP.
    $xp    = static fn() => (int)(fetchOne("SELECT SUM(xp) s FROM xp_events WHERE user_id=? AND action='todo'", [$uid])['s'] ?? 0);
    $rows  = static fn(int $id) => array_column(fetchAll("SELECT occurred_on FROM activity_log WHERE user_id=? AND action='todo' AND ref_id=? ORDER BY occurred_on", [$uid, $id]), 'occurred_on');
    $mk    = static fn(string $title, string $rec) => (int)insert("INSERT INTO todos (user_id,title,recurring) VALUES (?,?,?)", [$uid, $title, $rec]);
    // Move one todo's current completion (row, activity, XP event) back by $mod.
    $shift = function (int $id, string $mod) use ($uid) {
        $t = fetchOne("SELECT recurring, completed_at FROM todos WHERE id=?", [$id]);
        $old = substr($t['completed_at'], 0, 10);
        $new = date('Y-m-d', strtotime("$old 12:00 $mod"));
        update("UPDATE todos SET completed_at=DATE_SUB(completed_at, INTERVAL " . (int)(new DateTime($new))->diff(new DateTime($old))->days . " DAY) WHERE id=?", [$id]);
        update("UPDATE activity_log SET occurred_on=?, ref_type=? WHERE user_id=? AND action='todo' AND ref_id=? AND occurred_on=?", [$new, todoOccurrenceKey($t['recurring'], $new), $uid, $id, $old]);
        update("UPDATE xp_events SET created_at=?, ref_type=? WHERE user_id=? AND action='todo' AND ref_id=? AND ref_type=?", ["$new 12:00:00", todoOccurrenceKey($t['recurring'], $new), $uid, $id, todoOccurrenceKey($t['recurring'], $old)]);
        return $new;
    };

    // ── Daily: retries, undo/redo, a new day
    $d = $mk('Water plants', 'daily');
    $x0 = $xp(); $r = $toggle($d, 1);
    ok(($r['success'] ?? false) && $rows($d) === [$today] && $xp() === $x0 + 10, 'daily: complete → 1 activity today, +10 XP');
    $at = fetchOne("SELECT completed_at FROM todos WHERE id=?", [$d])['completed_at'];
    $toggle($d, 1); $toggle($d, 1);
    ok($rows($d) === [$today] && $xp() === $x0 + 10, 'daily: retried complete ×2 → still 1 activity, no extra XP');
    ok(fetchOne("SELECT completed_at FROM todos WHERE id=?", [$d])['completed_at'] === $at, 'daily: a retry keeps the original completion time');
    $toggle($d, 0); $r = $toggle($d, 0);
    ok(($r['success'] ?? false) && $rows($d) === [], 'daily: undo (and a retried undo) → today\'s activity removed');
    $toggle($d, 1);
    ok($rows($d) === [$today] && $xp() === $x0 + 10, 'daily: redo → activity back, XP not paid twice');
    $y = $shift($d, '-1 day');                                   // "it was done yesterday"
    $r = $toggle($d, 1);                                         // today, before any page load reset it
    ok($rows($d) === [$y, $today] && $xp() === $x0 + 20, 'daily: next day → yesterday kept, today added, +10 XP (new period)');
    $toggle($d, 0);
    ok($rows($d) === [$y], 'daily: undoing today leaves yesterday\'s history intact');
    $toggle($d, 1);

    // ── Weekly / monthly: one completion per period, history across periods
    $w = $mk('Weekly review', 'weekly');
    $toggle($w, 1); $lw = $shift($w, '-7 day'); $toggle($w, 1);
    ok($rows($w) === [$lw, $today], 'weekly: last week + this week = 2 completions');
    $m = $mk('Pay rent', 'monthly');
    $toggle($m, 1); $lm = $shift($m, '-1 month'); $toggle($m, 1);
    ok($rows($m) === [$lm, $today], 'monthly: last month + this month = 2 completions');
    ok(fetchOne("SELECT COUNT(*) c FROM xp_events WHERE user_id=? AND action='todo' AND ref_id IN (?,?)", [$uid, $w, $m])['c'] == 4, 'weekly/monthly: one XP award per period');

    // ── Completions recorded before this change (ref_type 'todo')
    $l = $mk('Old habit-ish todo', 'daily');
    $d3 = date('Y-m-d', strtotime('-3 day'));
    update("UPDATE todos SET completed=1, completed_at=NOW() WHERE id=?", [$l]);       // done today…
    insert("INSERT INTO activity_log (user_id,module,action,ref_type,ref_id,occurred_on,xp) VALUES (?,?,?,?,?,?,10)", [$uid, 'todos', 'todo', 'todo', $l, $d3]);  // …first done 3 days ago (old code kept only that)
    insert("INSERT INTO xp_events (user_id,action,xp,ref_type,ref_id,created_at) VALUES (?,?,?,?,?,?)", [$uid, 'todo', 10, 'todo', $l, "$d3 09:00:00"]);
    $x1 = $xp(); $toggle($l, 0);
    ok($rows($l) === [$d3], 'legacy: undoing today keeps the old row from 3 days ago (old code deleted it)');
    $toggle($l, 1);
    ok($rows($l) === [$d3, $today] && $xp() === $x1 + 10, 'legacy: redo today → new period row, paid once');
    $l2 = $mk('Old todo, paid today', 'daily');
    update("UPDATE todos SET completed=1, completed_at=NOW() WHERE id=?", [$l2]);
    insert("INSERT INTO activity_log (user_id,module,action,ref_type,ref_id,occurred_on,xp) VALUES (?,?,?,?,?,?,10)", [$uid, 'todos', 'todo', 'todo', $l2, $today]);
    insert("INSERT INTO xp_events (user_id,action,xp,ref_type,ref_id) VALUES (?,?,?,?,?)", [$uid, 'todo', 10, 'todo', $l2]);
    $x2 = $xp(); $toggle($l2, 0); $toggle($l2, 1);
    ok($rows($l2) === [$today] && $xp() === $x2, 'legacy: paid today under the old key → undo+redo pays nothing more, no duplicate row');

    // ── One-off todo: unchanged
    $n = $mk('Book dentist', 'none');
    $x3 = $xp(); $toggle($n, 1); $toggle($n, 0); $toggle($n, 1);
    ok($rows($n) === [$today] && fetchOne("SELECT ref_type FROM activity_log WHERE ref_id=? AND action='todo' AND user_id=?", [$n, $uid])['ref_type'] === 'todo' && $xp() === $x3 + 10, 'one-off: same key as before, paid once across undo/redo');

    // ── Board (update_status) now records like the checkbox
    $b = $mk('Board card', 'none');
    $x4 = $xp(); $r = $api(['action' => 'update_status', 'todo_id' => $b, 'status' => 'done']);
    ok(($r['success'] ?? false) && $rows($b) === [$today] && $xp() === $x4 + 10, 'board: moving to Done records the completion and XP');
    $api(['action' => 'update_status', 'todo_id' => $b, 'status' => 'today']);
    ok($rows($b) === [] && fetchOne("SELECT status, completed FROM todos WHERE id=?", [$b]) == ['status' => 'today', 'completed' => 0], 'board: moving back re-opens it and removes the activity');

    // ── Ownership, history views, achievements
    $r = $toggle(999999999, 1);
    ok(($r['success'] ?? true) === false && !fetchOne("SELECT id FROM activity_log WHERE ref_id=999999999 AND user_id=?", [$uid]), 'unknown / foreign todo → refused, nothing recorded');
    require_once '../includes/activity.php';
    $days = array_column(activityDays($uid, 8, 'todos'), 'count', 'date');
    ok(($days[$y] ?? 0) >= 1 && ($days[$today] ?? 0) >= 5, 'activity history shows yesterday and today');
    ok((moduleStreaks($uid)['todos']['current'] ?? 0) === 2, 'todos module streak = 2 (yesterday + today)');
    ok((int)(fetchOne("SELECT total_xp FROM user_xp WHERE user_id=?", [$uid])['total_xp'] ?? 0) === (int)fetchOne("SELECT SUM(xp) s FROM xp_events WHERE user_id=?", [$uid])['s'] - 20, 'user_xp total = sum of XP events (minus the 2×10 legacy events this test inserted directly)');
    ok((bool)fetchOne("SELECT id FROM achievements WHERE user_id=? AND key_name='first_todo'", [$uid]), 'first_todo achievement unlocked once');
    ok(fetchOne("SELECT COUNT(*) c FROM achievements WHERE user_id=? AND key_name='first_todo'", [$uid])['c'] == 1, '…and not duplicated');
    $html = $http('/pages/todos.php');
    // PHP's own error text only — page JS may legitimately say `undefined`.
    ok(str_contains($html, 'id="page-main"') && !preg_match('/fatal error|parse error|undefined (variable|array key|index|offset|constant|function|method)|call to undefined/i', $html), 'Todos page renders for this user');
} finally {
    update("DELETE FROM users WHERE id=?", [$uid]);
    @unlink($jar);
    ok(!fetchOne("SELECT id FROM users WHERE id=?", [$uid]) && !fetchOne("SELECT id FROM activity_log WHERE user_id=?", [$uid]), 'throwaway user and its rows removed');
}
echo "== $pass passed, $fail failed\n";
exit($fail ? 1 : 0);

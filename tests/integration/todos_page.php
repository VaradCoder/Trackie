<?php
/**
 * Todos page grouping + the one-field `patch` API — LOCAL dev DB only.
 *   php tests/integration/todos_page.php [base-url]
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

$email = 'todos-it-' . bin2hex(random_bytes(4)) . '@trackie.local';
$pw    = bin2hex(random_bytes(12));
$uid   = (int)insert("INSERT INTO users (name,email,password) VALUES (?,?,?)", ['Todos Tester', $email, password_hash($pw, PASSWORD_DEFAULT)]);
$other = (int)insert("INSERT INTO users (name,email,password) VALUES (?,?,?)", ['Other', 'todos-other-' . bin2hex(random_bytes(4)) . '@trackie.local', 'x']);
$jar   = tempnam(sys_get_temp_dir(), 'trk');
$d = static fn(int $n) => date('Y-m-d', strtotime("$n day"));
try {
    $mk = static fn(int $u, string $t, ?string $due, array $x = []) => (int)insert(
        "INSERT INTO todos (user_id,title,due_date,priority,recurring,completed,completed_at,description,tags,location) VALUES (?,?,?,?,?,?,?,?,?,?)",
        [$u, $t, $due, $x['pri'] ?? 'medium', $x['rec'] ?? 'none', $x['done'] ?? 0, isset($x['done']) ? date('Y-m-d H:i:s') : null, $x['desc'] ?? '', $x['tags'] ?? null, $x['loc'] ?? '']);
    $late = $mk($uid, 'Late', $d(-2), ['pri' => 'high']);
    $tod  = $mk($uid, 'Today task', $d(0));
    $tom  = $mk($uid, 'Tomorrow task', $d(1));
    $wk   = $mk($uid, 'Week task', $d(4));
    $lat  = $mk($uid, 'Later task', $d(30));
    $nod  = $mk($uid, 'No date task', null, ['tags' => 'home,errand', 'desc' => 'keep me', 'loc' => 'Shop']);
    $rec  = $mk($uid, 'Daily thing', null, ['rec' => 'daily']);
    $dn   = $mk($uid, 'Finished', $d(0), ['done' => 1]);
    $sub  = (int)insert("INSERT INTO todos (user_id,parent_id,title,completed) VALUES (?,?,?,1)", [$uid, $nod, 'step one']);
    insert("INSERT INTO todos (user_id,parent_id,title,completed) VALUES (?,?,?,0)", [$uid, $nod, 'step two']);
    $foreign = $mk($other, 'Not yours', $d(0));

    $http = function (string $path, ?array $post = null) use ($base, $jar) {
        $c = curl_init($base . $path);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest']] + ($post ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post] : []));
        $r = (string)curl_exec($c); curl_close($c); return $r;
    };
    $csrf = static fn(string $h) => preg_match('/csrf-token" content="([^"]+)"/', $h, $m) ? $m[1] : '';
    ok(str_contains($http('/api/auth.php', ['action' => 'login', 'email' => $email, 'password' => $pw, 'csrf_token' => $csrf($http('/pages/auth.php'))]), '"success":true'), 'throwaway user signs in');
    releaseTestLogin($email);

    $html = $http('/pages/todos.php');
    $in = static function (string $g) use ($html) {
        if (!preg_match('~data-list="' . $g . '">(.*?)</ul>~s', $html, $m)) return [];
        preg_match_all('~id="todo-(\d+)"~', $m[1], $k); return array_map('intval', $k[1]);
    };
    ok($in('overdue') === [$late], 'Overdue group');
    ok(in_array($tod, $in('today'), true) && in_array($rec, $in('today'), true), 'Today: due today + recurring daily');
    ok($in('tomorrow') === [$tom] && $in('week') === [$wk] && $in('later') === [$lat] && $in('nodate') === [$nod], 'Tomorrow / This week / Later / No date');
    ok($in('done') === [$dn], 'Done group');
    ok(!str_contains($html, 'Not yours') && !in_array($sub, array_merge(...array_map($in, ['today', 'nodate', 'done'])), true), 'no other user\'s todos, no subtasks as rows');
    ok((bool)preg_match('~id="todo-' . $nod . '".*?tdo-subcount[^>]*>.*?<b>1</b>/2~s', $html), 'subtask count 1/2 on the parent row');
    ok(str_contains($html, 'data-filter="tag:home"') && str_contains($html, 'data-filter="tag:errand"'), 'tags become filter chips');
    ok((bool)preg_match('~id="todo-' . $late . '".*?data-act="snooze"~s', $html) && !preg_match('~id="todo-' . $tom . '"[^\n]*data-act="snooze"~', $html), 'snooze offered on overdue, not on tomorrow');

    $tok = $csrf($html);
    $api = fn(array $p) => json_decode($http('/api/todos.php', $p + ['csrf_token' => $tok]), true) ?: [];
    ok(($api(['action' => 'patch', 'todo_id' => $nod, 'due_date' => $d(1)])['success'] ?? false), 'patch due date');
    $row = fetchOne("SELECT due_date, description, tags, location, priority FROM todos WHERE id=?", [$nod]);
    ok($row['due_date'] === $d(1) && $row['description'] === 'keep me' && $row['tags'] === 'home,errand' && $row['location'] === 'Shop' && $row['priority'] === 'medium',
       'patch changes only the date — description, tags, location, priority untouched');
    ok(($api(['action' => 'patch', 'todo_id' => $nod, 'priority' => 'high'])['success'] ?? false) && fetchOne("SELECT priority, due_date FROM todos WHERE id=?", [$nod]) == ['priority' => 'high', 'due_date' => $d(1)], 'patch priority only');
    ok(($api(['action' => 'patch', 'todo_id' => $nod, 'due_date' => ''])['success'] ?? false) && fetchOne("SELECT due_date FROM todos WHERE id=?", [$nod])['due_date'] === null, 'patch empty date clears it');
    ok(($api(['action' => 'patch', 'todo_id' => $nod, 'due_date' => '2026-13-45'])['success'] ?? true) === false, 'bad date refused');
    ok(($api(['action' => 'patch', 'todo_id' => $nod, 'priority' => 'urgent'])['success'] ?? true) === false, 'unknown priority refused');
    ok(($api(['action' => 'patch', 'todo_id' => $nod])['success'] ?? true) === false, 'nothing to change refused');
    ok(($api(['action' => 'patch', 'todo_id' => $foreign, 'due_date' => $d(5)])['success'] ?? true) === false
       && fetchOne("SELECT due_date FROM todos WHERE id=?", [$foreign])['due_date'] === $d(0), "another user's todo can't be patched");
} finally {
    update("DELETE FROM users WHERE id IN (?, ?)", [$uid, $other]);
    @unlink($jar);
    ok(!fetchOne("SELECT id FROM todos WHERE user_id IN (?, ?)", [$uid, $other]), 'throwaway users and their rows removed');
}
echo "== $pass passed, $fail failed\n";
exit($fail ? 1 : 0);

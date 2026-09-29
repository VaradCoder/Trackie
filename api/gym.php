<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();
verify_csrf();

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');

/**
 * Demo-video URL for an exercise name, or null. Plan items store free-text
 * names ("chest press") while the library has canonical ones ("Chest Press
 * Machine"), so an exact match wins, then a whole-word prefix match either
 * way. Among equals: the user's own entry, then the closest name length.
 */
function exerciseVideoUrl(int $uid, string $name): ?string {
    $norm = fn(string $s): string => trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($s)));
    $want = $norm($name);
    if ($want === '') return null;

    $rows = fetchAll(
        "SELECT name, user_id, video_path FROM exercise_library
          WHERE video_path IS NOT NULL AND (user_id IS NULL OR user_id=?)",
        [$uid]
    );
    $bestPath = null;
    $bestScore = 0.0;
    foreach ($rows as $r) {
        $have = $norm($r['name']);
        if ($have === $want) {
            $score = 3.0;
        } elseif ((str_starts_with($have, $want . ' ') || str_starts_with($want, $have . ' '))
                  // A lone word ("chest") is too vague to pick a clip for.
                  && str_contains(strlen($have) < strlen($want) ? $have : $want, ' ')) {
            $score = 2.0;
        } else {
            continue;
        }
        $score += $r['user_id'] !== null ? 0.5 : 0.0;
        $score -= abs(strlen($have) - strlen($want)) / 1000;
        if ($score > $bestScore && is_file(ROOT_PATH . '/' . $r['video_path'])) {
            $bestPath = $r['video_path'];
            $bestScore = $score;
        }
    }
    if ($bestPath === null) return null;
    return APP_BASE . '/' . implode('/', array_map('rawurlencode', explode('/', $bestPath)));
}

/**
 * Keep a workout_logs row's summary columns in step with its sets: `sets` =
 * how many are logged, reps/weight = the last set by number (the top-line
 * figure the older history views show).
 */
function fitnessRefreshLogAggregate(int $logId): void {
    update(
        "UPDATE workout_logs wl
            LEFT JOIN (SELECT reps, weight_kg FROM workout_sets WHERE log_id=? ORDER BY set_number DESC, id DESC LIMIT 1) last ON 1=1
            SET wl.sets = (SELECT COUNT(*) FROM workout_sets WHERE log_id=?),
                wl.reps = last.reps, wl.weight_kg = last.weight_kg
          WHERE wl.id=?",
        [$logId, $logId, $logId]
    );
}

switch ($action) {

    case 'add_plan':
        $name = sanitizeInput($_POST['name'] ?? '');
        $day  = sanitizeInput($_POST['day_of_week'] ?? 'Any');
        $validDays = ['Any','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
        if (!in_array($day, $validDays, true)) $day = 'Any';
        if (!$name) json_out(['success' => false, 'error' => 'Plan name is required.'], 422);

        $items = $_POST['items'] ?? [];
        if (!is_array($items)) $items = [];

        $planId = insert("INSERT INTO workout_plans (user_id,name,day_of_week) VALUES (?,?,?)", [$uid, $name, $day]);
        $order  = 0;
        foreach ($items as $it) {
            $exName = sanitizeInput($it['name'] ?? '');
            if (!$exName) continue;
            $sets = max(1, min(50, (int)($it['sets'] ?? 3)));
            $reps = max(1, min(100, (int)($it['reps'] ?? 10)));
            // Optional targets — stored as NULL when left blank so "not set"
            // stays distinguishable from a deliberate 0.
            $weight = ($it['weight'] ?? '') !== '' ? max(0, min(999, (float)$it['weight'])) : null;
            $rest   = ($it['rest']   ?? '') !== '' ? max(0, min(3600, (int)$it['rest']))   : null;
            $notes  = sanitizeInput($it['notes'] ?? '');
            insert(
                "INSERT INTO workout_plan_items
                   (plan_id,exercise_name,target_sets,target_reps,target_weight,rest_seconds,notes,sort_order)
                 VALUES (?,?,?,?,?,?,?,?)",
                [$planId, $exName, $sets, $reps, $weight, $rest, ($notes !== '' ? $notes : null), $order++]
            );
        }
        json_out(['success' => true, 'id' => $planId]);

    case 'edit_plan':
        $id = (int)($_POST['plan_id'] ?? 0);
        $plan = fetchOne("SELECT id FROM workout_plans WHERE id=? AND user_id=?", [$id, $uid]);
        if (!$plan) json_out(['success' => false, 'error' => 'Not found.'], 404);

        $name = sanitizeInput($_POST['name'] ?? '');
        $day  = sanitizeInput($_POST['day_of_week'] ?? 'Any');
        $validDays = ['Any','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
        if (!in_array($day, $validDays, true)) $day = 'Any';
        if (!$name) json_out(['success' => false, 'error' => 'Plan name is required.'], 422);

        $items = $_POST['items'] ?? [];
        if (!is_array($items)) $items = [];

        update("UPDATE workout_plans SET name=?, day_of_week=? WHERE id=? AND user_id=?", [$name, $day, $id, $uid]);

        // Simplest correct way to reconcile the exercise list (reordering,
        // additions, removals, edits all at once) without tracking per-row
        // diffs client-side: replace the set. Cascades from workout_plans's
        // FK aren't in play here since we're keeping the plan row itself.
        delete("DELETE FROM workout_plan_items WHERE plan_id=?", [$id]);
        $order = 0;
        foreach ($items as $it) {
            $exName = sanitizeInput($it['name'] ?? '');
            if (!$exName) continue;
            $sets = max(1, min(50, (int)($it['sets'] ?? 3)));
            $reps = max(1, min(100, (int)($it['reps'] ?? 10)));
            $weight = ($it['weight'] ?? '') !== '' ? max(0, min(999, (float)$it['weight'])) : null;
            $rest   = ($it['rest']   ?? '') !== '' ? max(0, min(3600, (int)$it['rest']))   : null;
            $notes  = sanitizeInput($it['notes'] ?? '');
            insert(
                "INSERT INTO workout_plan_items
                   (plan_id,exercise_name,target_sets,target_reps,target_weight,rest_seconds,notes,sort_order)
                 VALUES (?,?,?,?,?,?,?,?)",
                [$id, $exName, $sets, $reps, $weight, $rest, ($notes !== '' ? $notes : null), $order++]
            );
        }
        json_out(['success' => true]);

    case 'delete_plan':
        $id = (int)($_POST['plan_id'] ?? 0);
        if (!$id) json_out(['success' => false, 'error' => 'Invalid plan.'], 422);
        delete("DELETE FROM workout_plans WHERE id=? AND user_id=?", [$id, $uid]);
        json_out(['success' => true]);

    case 'plan_items':
        $id = (int)($_POST['plan_id'] ?? $_GET['plan_id'] ?? 0);
        $plan = fetchOne("SELECT id, name, day_of_week FROM workout_plans WHERE id=? AND user_id=?", [$id, $uid]);
        if (!$plan) json_out(['success' => false, 'error' => 'Not found.'], 404);
        $items = fetchAll(
            "SELECT * FROM workout_plan_items WHERE plan_id=? ORDER BY sort_order ASC",
            [$id]
        );
        json_out(['success' => true, 'plan' => $plan, 'items' => $items]);

    case 'log':
        $exercise = sanitizeInput($_POST['exercise_name'] ?? '');
        $date     = sanitizeInput($_POST['log_date'] ?? date('Y-m-d'));
        $planId   = (int)($_POST['plan_id'] ?? 0) ?: null;
        $sets     = $_POST['sets']   !== '' ? (int)$_POST['sets']   : null;
        $reps     = $_POST['reps']   !== '' ? (int)$_POST['reps']   : null;
        $weight   = $_POST['weight'] !== '' ? (float)$_POST['weight'] : null;
        $notes    = sanitizeInput($_POST['notes'] ?? '');

        if (!$exercise) json_out(['success' => false, 'error' => 'Exercise name is required.'], 422);

        $id = insert(
            "INSERT INTO workout_logs (user_id,plan_id,exercise_name,sets,reps,weight_kg,log_date,notes)
             VALUES (?,?,?,?,?,?,?,?)",
            [$uid, $planId, $exercise, $sets, $reps, $weight, $date, $notes ?: null]
        );

        require_once '../includes/gamification.php';
        $xp = function_exists('awardXpOnce') ? awardXpOnce($uid, 'workout', 15, 'log:' . $date, $id) : null;
        $newAch = function_exists('checkAchievements') ? checkAchievements($uid) : [];

        json_out(['success' => true, 'id' => $id, 'xp' => $xp, 'newAchievements' => $newAch]);

    case 'delete_log':
        $id = (int)($_POST['log_id'] ?? 0);
        if (!$id) json_out(['success' => false, 'error' => 'Invalid log.'], 422);
        delete("DELETE FROM workout_logs WHERE id=? AND user_id=?", [$id, $uid]);
        json_out(['success' => true]);

    case 'journal_add':
        $body = trim(sanitizeInput($_POST['body'] ?? ''));
        $date = sanitizeInput($_POST['entry_date'] ?? date('Y-m-d'));
        if (!$body) json_out(['success' => false, 'error' => 'Entry cannot be empty.'], 422);
        $id = insert(
            "INSERT INTO hobby_journal (user_id,hobby,entry_date,body) VALUES (?,'Fitness',?,?)",
            [$uid, $date, $body]
        );
        json_out(['success' => true, 'id' => $id]);

    case 'journal_list':
        $entries = fetchAll(
            "SELECT * FROM hobby_journal WHERE user_id=? AND hobby='Fitness' ORDER BY entry_date DESC, id DESC LIMIT 30",
            [$uid]
        );
        json_out(['success' => true, 'entries' => $entries]);

    case 'journal_delete':
        $id = (int)($_POST['entry_id'] ?? 0);
        delete("DELETE FROM hobby_journal WHERE id=? AND user_id=? AND hobby='Fitness'", [$id, $uid]);
        json_out(['success' => true]);

    /* ── Guided Active Workout sessions (real per-set data) ─────────── */

    case 'session_start':
        $planId   = (int)($_POST['plan_id'] ?? 0) ?: null;
        $planName = sanitizeInput($_POST['plan_name'] ?? '') ?: null;
        $sid = insert(
            "INSERT INTO workout_sessions (user_id,plan_id,plan_name,session_date,started_at) VALUES (?,?,?,CURDATE(),NOW())",
            [$uid, $planId, $planName]
        );
        json_out(['success' => true, 'session_id' => $sid]);

    case 'session_log_set':
        // Upsert: (session, exercise, set_number) identifies a set, so a
        // retry, an offline replay or an edit to a completed set UPDATES that
        // row instead of creating a duplicate. No unique index is relied on —
        // older databases may already hold duplicates from before this fix.
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $exercise  = sanitizeInput($_POST['exercise_name'] ?? '');
        $setNum    = max(1, min(99, (int)($_POST['set_number'] ?? 1)));
        $reps      = ($_POST['reps']   ?? '') !== '' ? max(0, min(999, (int)$_POST['reps']))          : null;
        $weight    = ($_POST['weight'] ?? '') !== '' ? max(0, min(9999, round((float)$_POST['weight'], 2))) : null;
        $planId    = (int)($_POST['plan_id'] ?? 0) ?: null;

        if (!$exercise) json_out(['success' => false, 'error' => 'Exercise name is required.'], 422);
        if ($reps === null && $weight === null) json_out(['success' => false, 'error' => 'Enter reps or weight for this set.'], 422);
        $session = fetchOne("SELECT id FROM workout_sessions WHERE id=? AND user_id=?", [$sessionId, $uid]);
        if (!$session) json_out(['success' => false, 'error' => 'Session not found.'], 404);

        // One workout_logs row per (session, exercise) — reused across sets.
        $log = fetchOne(
            "SELECT id FROM workout_logs WHERE session_id=? AND user_id=? AND exercise_name=?",
            [$sessionId, $uid, $exercise]
        );
        $logId = $log ? (int)$log['id'] : 0;
        $existing = $logId
            ? fetchOne("SELECT id FROM workout_sets WHERE log_id=? AND set_number=? ORDER BY id LIMIT 1", [$logId, $setNum])
            : null;
        $existingId = $existing ? (int)$existing['id'] : 0;

        // Best weight for this exercise across every OTHER set (so editing
        // this very set can't hide or fake a record). Combines granular
        // workout_sets with legacy aggregate-only workout_logs rows.
        $prevBest = fetchOne(
            "SELECT MAX(w) AS best FROM (
                SELECT ws.weight_kg AS w FROM workout_sets ws
                  JOIN workout_logs wl ON wl.id = ws.log_id
                 WHERE wl.user_id=? AND wl.exercise_name=? AND ws.id <> ?
                UNION ALL
                SELECT wl.weight_kg AS w FROM workout_logs wl
                 WHERE wl.user_id=? AND wl.exercise_name=?
                   AND wl.id NOT IN (SELECT DISTINCT log_id FROM workout_sets)
             ) x",
            [$uid, $exercise, $existingId, $uid, $exercise]
        )['best'] ?? null;
        $isPr = $weight !== null && $weight > 0 && ($prevBest === null || $weight > (float)$prevBest);

        if (!$logId) {
            $logId = (int)insert(
                "INSERT INTO workout_logs (user_id,plan_id,session_id,exercise_name,sets,reps,weight_kg,log_date)
                 VALUES (?,?,?,?,0,?,?,CURDATE())",
                [$uid, $planId, $sessionId, $exercise, $reps, $weight]
            );
        }
        if ($existingId) {
            update("UPDATE workout_sets SET reps=?, weight_kg=? WHERE id=?", [$reps, $weight, $existingId]);
            $setId = $existingId;
        } else {
            $setId = (int)insert(
                "INSERT INTO workout_sets (log_id,set_number,reps,weight_kg) VALUES (?,?,?,?)",
                [$logId, $setNum, $reps, $weight]
            );
        }
        fitnessRefreshLogAggregate($logId);

        // Only unlock on a genuine improvement over a real prior number —
        // $prevBest === null means this is the exercise's first-ever set,
        // which trivially satisfies $isPr but isn't a "record" yet.
        $prNewlyUnlocked = false;
        if ($isPr && $prevBest !== null) {
            require_once '../includes/gamification.php';
            $prNewlyUnlocked = function_exists('unlockAchievement') ? unlockAchievement($uid, 'gym_first_pr') : false;
        }

        json_out([
            'success' => true, 'log_id' => $logId, 'set_id' => $setId, 'updated' => (bool)$existingId,
            'is_pr' => $isPr, 'previous_best' => $prevBest,
            'newAchievements' => $prNewlyUnlocked ? ['gym_first_pr'] : [],
        ]);

    case 'session_delete_set':
        // Un-completing / removing a set during a workout.
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $exercise  = sanitizeInput($_POST['exercise_name'] ?? '');
        $setNum    = (int)($_POST['set_number'] ?? 0);
        $log = fetchOne(
            "SELECT wl.id FROM workout_logs wl JOIN workout_sessions s ON s.id = wl.session_id
              WHERE wl.session_id=? AND wl.user_id=? AND s.user_id=? AND wl.exercise_name=?",
            [$sessionId, $uid, $uid, $exercise]
        );
        if (!$log) json_out(['success' => true, 'deleted' => 0]); // already gone — idempotent
        $n = delete("DELETE FROM workout_sets WHERE log_id=? AND set_number=?", [$log['id'], $setNum]);
        // An exercise whose last set was removed no longer counts as logged.
        $left = (int)fetchOne("SELECT COUNT(*) c FROM workout_sets WHERE log_id=?", [$log['id']])['c'];
        if ($left === 0) delete("DELETE FROM workout_logs WHERE id=? AND user_id=?", [$log['id'], $uid]);
        else fitnessRefreshLogAggregate((int)$log['id']);
        json_out(['success' => true, 'deleted' => $n]);

    case 'history':
        // Workout history with every set. One entry per guided session, or per
        // day for quick-logged exercises. Fixed 4 queries regardless of page
        // size. Volume = Σ weight × reps over logged sets; legacy rows without
        // per-set data use sets × reps × weight (same rule as the dashboard).
        $limit  = max(1, min(30, (int)($_POST['limit'] ?? 10)));
        $offset = max(0, (int)($_POST['offset'] ?? 0));
        $groups = fetchAll(
            "SELECT IF(session_id IS NULL, CONCAT('d', log_date), CONCAT('s', session_id)) AS g,
                    MAX(session_id) AS session_id, MAX(log_date) AS d, MAX(created_at) AS c
               FROM workout_logs WHERE user_id=?
              GROUP BY g ORDER BY d DESC, c DESC
              LIMIT " . ($limit + 1) . " OFFSET " . $offset,
            [$uid]
        );
        $hasMore = count($groups) > $limit;
        $groups  = array_slice($groups, 0, $limit);
        if (!$groups) json_out(['success' => true, 'entries' => [], 'has_more' => false]);

        $sessionIds = array_values(array_filter(array_map(fn($g) => (int)$g['session_id'], $groups)));
        $quickDates = array_values(array_map(fn($g) => $g['d'], array_filter($groups, fn($g) => $g['g'][0] === 'd')));
        $where = []; $p = [$uid];
        if ($sessionIds) { $where[] = 'session_id IN (' . implode(',', array_fill(0, count($sessionIds), '?')) . ')'; array_push($p, ...$sessionIds); }
        if ($quickDates) { $where[] = '(session_id IS NULL AND log_date IN (' . implode(',', array_fill(0, count($quickDates), '?')) . '))'; array_push($p, ...$quickDates); }
        $logs = fetchAll(
            "SELECT id, session_id, exercise_name, sets, reps, weight_kg, log_date, notes
               FROM workout_logs WHERE user_id=? AND (" . implode(' OR ', $where) . ") ORDER BY id",
            $p
        );
        $logIds = array_column($logs, 'id');
        $sets = $logIds ? fetchAll(
            "SELECT log_id, set_number, reps, weight_kg FROM workout_sets
              WHERE log_id IN (" . implode(',', array_fill(0, count($logIds), '?')) . ") ORDER BY log_id, set_number, id",
            $logIds
        ) : [];
        $sessions = $sessionIds ? fetchAll(
            "SELECT id, plan_name, started_at, ended_at, duration_sec FROM workout_sessions
              WHERE user_id=? AND id IN (" . implode(',', array_fill(0, count($sessionIds), '?')) . ")",
            array_merge([$uid], $sessionIds)
        ) : [];
        $sessionById = array_column($sessions, null, 'id');
        $setsByLog = [];
        foreach ($sets as $s) $setsByLog[$s['log_id']][] = $s;

        $entries = [];
        foreach ($groups as $g) $entries[$g['g']] = null;
        foreach ($logs as $l) {
            $key = $l['session_id'] ? 's' . $l['session_id'] : 'd' . $l['log_date'];
            if (!array_key_exists($key, $entries)) continue;
            if ($entries[$key] === null) {
                $sess = $l['session_id'] ? ($sessionById[$l['session_id']] ?? null) : null;
                $entries[$key] = [
                    'key'          => $key,
                    'date'         => $l['log_date'],
                    'title'        => $sess ? ($sess['plan_name'] ?: 'Workout') : 'Quick log',
                    'guided'       => (bool)$sess,
                    'finished'     => $sess ? $sess['ended_at'] !== null : null,
                    'duration_sec' => $sess && $sess['duration_sec'] !== null ? (int)$sess['duration_sec'] : null,
                    'exercises'    => [], 'set_count' => 0, 'volume' => 0.0,
                ];
            }
            $rows = $setsByLog[$l['id']] ?? [];
            $legacy = !$rows;
            if ($legacy) {
                $vol = (float)$l['sets'] * (float)$l['reps'] * (float)$l['weight_kg'];
                $setCount = (int)$l['sets'];
                $setList = [];
            } else {
                $vol = 0.0;
                $setList = array_map(function ($s) use (&$vol) {
                    $vol += (float)$s['reps'] * (float)$s['weight_kg'];
                    return ['n' => (int)$s['set_number'], 'reps' => $s['reps'] !== null ? (int)$s['reps'] : null,
                            'weight' => $s['weight_kg'] !== null ? (float)$s['weight_kg'] : null];
                }, $rows);
                $setCount = count($rows);
            }
            $entries[$key]['exercises'][] = [
                'log_id' => (int)$l['id'], 'name' => $l['exercise_name'], 'legacy' => $legacy,
                'sets' => $setList,
                'summary' => $legacy ? ['sets' => $l['sets'] !== null ? (int)$l['sets'] : null,
                                        'reps' => $l['reps'] !== null ? (int)$l['reps'] : null,
                                        'weight' => $l['weight_kg'] !== null ? (float)$l['weight_kg'] : null] : null,
                'volume' => round($vol, 1), 'notes' => $l['notes'],
            ];
            $entries[$key]['set_count'] += $setCount;
            $entries[$key]['volume']    += $vol;
        }
        $entries = array_values(array_filter($entries));
        foreach ($entries as &$e) $e['volume'] = round($e['volume'], 1);
        unset($e);
        json_out(['success' => true, 'entries' => $entries, 'has_more' => $hasMore]);

    case 'session_complete':
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $session = fetchOne("SELECT * FROM workout_sessions WHERE id=? AND user_id=?", [$sessionId, $uid]);
        if (!$session) json_out(['success' => false, 'error' => 'Session not found.'], 404);

        update(
            "UPDATE workout_sessions
                SET ended_at=NOW(), duration_sec=TIMESTAMPDIFF(SECOND, started_at, NOW())
              WHERE id=? AND user_id=?",
            [$sessionId, $uid]
        );

        $exerciseCount = (int)fetchOne("SELECT COUNT(*) c FROM workout_logs WHERE session_id=?", [$sessionId])['c'];

        require_once '../includes/gamification.php';
        // Session-level XP (once per session) — distinct from the standalone
        // quick-log modal's per-exercise 'workout' XP, so a guided session
        // isn't worth dramatically more than logging the same work by hand.
        $xp = ($exerciseCount > 0 && function_exists('awardXpOnce'))
            ? awardXpOnce($uid, 'workout_session', 20, 'session', $sessionId)
            : null;
        $newAch = function_exists('checkAchievements') ? checkAchievements($uid) : [];

        // Recompute the streak fresh — today's session may have just extended
        // it, and the completion screen should show the real current number.
        $freshLogDates = array_column(
            fetchAll("SELECT DISTINCT log_date FROM workout_logs WHERE user_id=? ORDER BY log_date", [$uid]),
            'log_date'
        );
        $freshStreak = function_exists('calculateStreaks') ? calculateStreaks($freshLogDates)['current'] : null;

        $fresh = fetchOne("SELECT duration_sec FROM workout_sessions WHERE id=?", [$sessionId]);
        json_out([
            'success'        => true,
            'streak'         => $freshStreak,
            'exercise_count' => $exerciseCount,
            'duration_sec'   => (int)($fresh['duration_sec'] ?? 0),
            'xp'             => $xp,
            'newAchievements'=> $newAch,
        ]);

    case 'session_cancel':
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $session = fetchOne("SELECT id FROM workout_sessions WHERE id=? AND user_id=?", [$sessionId, $uid]);
        if (!$session) json_out(['success' => false, 'error' => 'Session not found.'], 404);

        $hasLogs = (int)fetchOne("SELECT COUNT(*) c FROM workout_logs WHERE session_id=?", [$sessionId])['c'];
        if ($hasLogs === 0) {
            // Nothing logged — quitting immediately shouldn't leave a phantom
            // 0-exercise session cluttering history/streak calculations.
            delete("DELETE FROM workout_sessions WHERE id=?", [$sessionId]);
        } else {
            update(
                "UPDATE workout_sessions SET ended_at=NOW(), duration_sec=TIMESTAMPDIFF(SECOND, started_at, NOW()) WHERE id=?",
                [$sessionId]
            );
        }
        json_out(['success' => true, 'kept' => $hasLogs > 0]);

    case 'previous_performance':
        $exercise = sanitizeInput($_POST['exercise_name'] ?? '');
        if (!$exercise) json_out(['success' => false, 'error' => 'Exercise name is required.'], 422);

        // Demo media is independent of log history — an exercise never logged
        // before can still have a clip. Your own library video wins; WorkoutDB
        // (cached, see includes/workoutdb.php) fills gaps and adds how-to steps.
        // Order: your own library video → ExerciseDB GIF / free-exercise-db
        // photos (app/Modules/Fitness/ExerciseMedia.php, cached) → WorkoutDB.
        require_once '../includes/workoutdb.php';
        require_once '../app/Modules/Fitness/ExerciseMedia.php';
        $localVideo = exerciseVideoUrl($uid, $exercise);
        $media = $localVideo ? null : ExerciseMedia::forName($exercise);
        $wdb   = (!$localVideo && !$media) ? workoutdbDemo($exercise) : null;
        $demo = [
            'video_url'    => $localVideo ?? ($wdb['video'] ?? null),
            'video_poster' => $localVideo ? null : ($wdb['poster'] ?? null),
            'gif_url'      => $media['gif_url'] ?? (($localVideo || !empty($wdb['video'])) ? null : ($wdb['gif'] ?? null)),
            'frames'       => $media['frames'] ?? [],
            'instructions' => $media['instructions'] ?? ($wdb['instructions'] ?? []),
            'demo_source'  => $localVideo ? 'library'
                : ($media['source'] ?? (($wdb && ($wdb['video'] || $wdb['gif'])) ? 'workoutdb' : null)),
            // Credit (and the exact exercise shown) only for third-party media.
            'demo_credit'  => $localVideo ? null : ($media['credit'] ?? ($wdb ? 'WorkoutDB' : null)),
            'demo_name'    => $media['name'] ?? null,
        ];

        $lastLog = fetchOne(
            "SELECT id, log_date, sets, reps, weight_kg FROM workout_logs
              WHERE user_id=? AND exercise_name=? ORDER BY created_at DESC LIMIT 1",
            [$uid, $exercise]
        );
        if (!$lastLog) json_out(['success' => true, 'found' => false] + $demo);

        $sets = fetchAll(
            "SELECT set_number, reps, weight_kg FROM workout_sets WHERE log_id=? ORDER BY set_number",
            [$lastLog['id']]
        );
        json_out([
            'success'  => true,
            'found'    => true,
            'log_date' => $lastLog['log_date'],
            // Granular sets when available (guided sessions); otherwise fall
            // back to the single aggregate row (quick-logged / legacy entry).
            'sets'     => $sets ?: [['set_number' => 1, 'reps' => $lastLog['reps'], 'weight_kg' => $lastLog['weight_kg']]],
        ] + $demo);

    case 'personal_records':
        // Combines granular workout_sets with legacy aggregate-only
        // workout_logs rows, then reduces to one best-weight row per
        // exercise in PHP (small per-user dataset — simpler and more
        // portable than a SQL window function).
        $rows = fetchAll(
            "SELECT exercise_name, weight_kg, reps, log_date FROM (
                SELECT wl.exercise_name, ws.weight_kg, ws.reps, wl.log_date
                  FROM workout_sets ws JOIN workout_logs wl ON wl.id = ws.log_id
                 WHERE wl.user_id=?
                UNION ALL
                SELECT wl.exercise_name, wl.weight_kg, wl.reps, wl.log_date
                  FROM workout_logs wl
                 WHERE wl.user_id=?
                   AND wl.id NOT IN (SELECT DISTINCT log_id FROM workout_sets)
             ) combined
             WHERE weight_kg IS NOT NULL",
            [$uid, $uid]
        );
        $prs = [];
        foreach ($rows as $r) {
            $name = $r['exercise_name'];
            if (!isset($prs[$name]) || (float)$r['weight_kg'] > (float)$prs[$name]['weight_kg']) {
                $prs[$name] = $r;
            }
        }
        usort($prs, fn($a, $b) => (float)$b['weight_kg'] <=> (float)$a['weight_kg']);
        json_out(['success' => true, 'records' => array_values($prs)]);

    /* ── Exercise library ────────────────────────────────────────── */

    case 'exercise_search':
        $q    = sanitizeInput($_POST['q'] ?? '');
        $mus  = sanitizeInput($_POST['muscle'] ?? '');
        $equ  = sanitizeInput($_POST['equipment'] ?? '');
        $sql  = "SELECT * FROM exercise_library WHERE (user_id IS NULL OR user_id=?)";
        $p    = [$uid];
        if ($q !== '')   { $sql .= " AND name LIKE ?"; $p[] = "%{$q}%"; }
        if ($mus !== '') { $sql .= " AND muscle_group = ?"; $p[] = $mus; }
        if ($equ !== '') { $sql .= " AND equipment = ?"; $p[] = $equ; }
        $sql .= " ORDER BY name LIMIT 100";
        json_out(['success' => true, 'exercises' => fetchAll($sql, $p)]);

    /* ── Exercise catalogue (Fitness V2 — provider layer) ──────────
       app/Modules/Fitness: the library plus any remote provider, one
       normalized shape. exercise_search above stays for older callers. */

    case 'exercise_catalog':
        require_once '../app/Modules/Fitness/ExerciseCatalog.php';
        $q = trim(sanitizeInput($_POST['q'] ?? ''));
        $filters = [
            'body_part' => sanitizeInput($_POST['body_part'] ?? ''),
            'equipment' => sanitizeInput($_POST['equipment'] ?? ''),
        ];
        $res = (new ExerciseCatalog($uid))->search(substr($q, 0, 80), $filters);
        json_out(['success' => true] + $res);

    case 'exercise_detail':
        require_once '../app/Modules/Fitness/ExerciseCatalog.php';
        $source = sanitizeInput($_POST['source'] ?? '');
        $id     = sanitizeInput($_POST['id'] ?? '');
        $ex = ($source !== '' && $id !== '') ? (new ExerciseCatalog($uid))->find($source, $id) : null;
        if (!$ex) json_out(['success' => false, 'error' => 'Exercise not found.'], 404);
        // No video/GIF of its own → the same cached demo the workout screen uses.
        if (!$ex['video_url'] && !$ex['gif_url']) {
            require_once '../app/Modules/Fitness/ExerciseMedia.php';
            $m = ExerciseMedia::forName($ex['name']);
            if ($m) {
                $ex['gif_url'] = $m['gif_url'] ?? null;
                $ex['frames']  = $m['frames'] ?? [];
                $ex['demo_credit'] = $m['credit'];
                $ex['demo_name']   = $m['name'];
                if (!$ex['instructions']) $ex['instructions'] = $m['instructions'];
            }
        }
        json_out(['success' => true, 'exercise' => $ex]);

    case 'exercise_save':
        require_once '../app/Modules/Fitness/ExerciseCatalog.php';
        $libId = (new ExerciseCatalog($uid))->saveToLibrary(
            sanitizeInput($_POST['source'] ?? ''),
            sanitizeInput($_POST['id'] ?? '')
        );
        if (!$libId) json_out(['success' => false, 'error' => "Couldn't save that exercise right now."], 422);
        json_out(['success' => true, 'library_id' => $libId]);

    case 'plan_add_exercise':
        $planId = (int)($_POST['plan_id'] ?? 0);
        $name   = sanitizeInput($_POST['exercise_name'] ?? '');
        if (!$name) json_out(['success' => false, 'error' => 'Exercise name is required.'], 422);
        if (!fetchOne("SELECT id FROM workout_plans WHERE id=? AND user_id=?", [$planId, $uid])) {
            json_out(['success' => false, 'error' => 'Plan not found.'], 404);
        }
        $next = (int)(fetchOne("SELECT COALESCE(MAX(sort_order),-1)+1 n FROM workout_plan_items WHERE plan_id=?", [$planId])['n'] ?? 0);
        insert(
            "INSERT INTO workout_plan_items (plan_id,exercise_name,target_sets,target_reps,sort_order) VALUES (?,?,?,?,?)",
            [$planId, $name, max(1, min(50, (int)($_POST['sets'] ?? 3))), max(1, min(100, (int)($_POST['reps'] ?? 10))), $next]
        );
        json_out(['success' => true]);

    case 'exercise_add_custom':
        $name = sanitizeInput($_POST['name'] ?? '');
        if (!$name) json_out(['success' => false, 'error' => 'Exercise name is required.'], 422);
        $id = insert(
            "INSERT INTO exercise_library (user_id,name,muscle_group,equipment,category) VALUES (?,?,?,?,?)",
            [
                $uid, $name,
                sanitizeInput($_POST['muscle_group'] ?? '') ?: null,
                sanitizeInput($_POST['equipment'] ?? '') ?: null,
                sanitizeInput($_POST['category'] ?? '') ?: null,
            ]
        );
        json_out(['success' => true, 'id' => $id]);

    /* ── Exercise demo videos ─────────────────────────────────────
       Files live in assets/vids (downloaded manually, not user-uploaded via
       this app). Some arrived with clear names and were auto-mapped in
       setup.php; the rest need a human to say which exercise they show —
       this picker is that one-time assignment step, done once per file. */

    case 'exercise_video_list':
        $dir = ROOT_PATH . '/assets/vids';
        $files = is_dir($dir) ? array_values(array_diff(scandir($dir), ['.', '..'])) : [];
        $files = array_filter($files, fn($f) => preg_match('/\.(mp4|webm|mov)$/i', $f));

        $assigned = array_column(
            fetchAll("SELECT video_path FROM exercise_library WHERE video_path IS NOT NULL"),
            'video_path'
        );
        $assignedNames = array_map(fn($p) => basename($p), $assigned);
        $unassigned = array_values(array_diff($files, $assignedNames));

        json_out(['success' => true, 'unassigned' => $unassigned, 'total' => count($files)]);

    case 'exercise_assign_video':
        $filename = basename(sanitizeInput($_POST['filename'] ?? '')); // basename: no path traversal
        if (!$filename || !preg_match('/\.(mp4|webm|mov)$/i', $filename)) {
            json_out(['success' => false, 'error' => 'Invalid file.'], 422);
        }
        $fullPath = ROOT_PATH . '/assets/vids/' . $filename;
        if (!is_file($fullPath)) json_out(['success' => false, 'error' => 'File not found.'], 404);

        $exerciseId = (int)($_POST['exercise_id'] ?? 0);
        if ($exerciseId) {
            $ex = fetchOne("SELECT id FROM exercise_library WHERE id=? AND (user_id=? OR user_id IS NULL)", [$exerciseId, $uid]);
            if (!$ex) json_out(['success' => false, 'error' => 'Exercise not found.'], 404);
        } else {
            $newName = sanitizeInput($_POST['new_name'] ?? '');
            if (!$newName) json_out(['success' => false, 'error' => 'Pick an exercise or name a new one.'], 422);
            $exerciseId = insert(
                "INSERT INTO exercise_library (user_id,name,muscle_group,equipment,category,video_path) VALUES (?,?,?,?,?,?)",
                [
                    $uid, $newName,
                    sanitizeInput($_POST['muscle_group'] ?? '') ?: null,
                    sanitizeInput($_POST['equipment'] ?? '') ?: null,
                    'Strength',
                    'assets/vids/' . $filename,
                ]
            );
            json_out(['success' => true, 'exercise_id' => $exerciseId]);
        }

        update("UPDATE exercise_library SET video_path=? WHERE id=? AND (user_id=? OR user_id IS NULL)", ['assets/vids/' . $filename, $exerciseId, $uid]);
        json_out(['success' => true, 'exercise_id' => $exerciseId]);

    /* ── Progress / analytics ────────────────────────────────────── */

    case 'progress_stats':
        $weeks = max(1, min(26, (int)($_POST['weeks'] ?? 8)));

        // Weekly frequency + volume, oldest → newest.
        $weeklyStats = [];
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $wStart = date('Y-m-d', strtotime("monday this week -{$i} week"));
            $wEnd   = date('Y-m-d', strtotime("sunday this week -{$i} week"));
            $sessions = (int)fetchOne(
                "SELECT COUNT(DISTINCT log_date) c FROM workout_logs WHERE user_id=? AND log_date BETWEEN ? AND ?",
                [$uid, $wStart, $wEnd]
            )['c'];
            $volume = (float)(fetchOne(
                "SELECT COALESCE(SUM(vol),0) v FROM (
                    SELECT ws.reps * ws.weight_kg AS vol
                      FROM workout_sets ws JOIN workout_logs wl ON wl.id=ws.log_id
                     WHERE wl.user_id=? AND wl.log_date BETWEEN ? AND ?
                    UNION ALL
                    SELECT wl.sets * wl.reps * wl.weight_kg AS vol
                      FROM workout_logs wl
                     WHERE wl.user_id=? AND wl.log_date BETWEEN ? AND ?
                       AND wl.id NOT IN (SELECT DISTINCT log_id FROM workout_sets)
                 ) x", [$uid, $wStart, $wEnd, $uid, $wStart, $wEnd]
            )['v'] ?? 0);
            $weeklyStats[] = ['week_start' => $wStart, 'sessions' => $sessions, 'volume' => round($volume, 1)];
        }

        $totals = fetchOne(
            "SELECT COUNT(*) c, COALESCE(SUM(sets),0) total_sets, COALESCE(SUM(reps*sets),0) total_reps
               FROM workout_logs WHERE user_id=?",
            [$uid]
        );
        $trainingTimeSec = (int)(fetchOne(
            "SELECT COALESCE(SUM(duration_sec),0) t FROM workout_sessions WHERE user_id=? AND duration_sec IS NOT NULL",
            [$uid]
        )['t'] ?? 0);

        // Muscle-group distribution — best-effort join on exercise name
        // (case-insensitive) against the library, custom exercises included.
        $muscleDist = fetchAll(
            "SELECT COALESCE(el.muscle_group,'Other') AS muscle_group, COUNT(*) AS cnt
               FROM workout_logs wl
               LEFT JOIN exercise_library el
                 ON LOWER(el.name) = LOWER(wl.exercise_name) AND (el.user_id IS NULL OR el.user_id=?)
              WHERE wl.user_id=?
              GROUP BY muscle_group
              ORDER BY cnt DESC",
            [$uid, $uid]
        );

        json_out([
            'success'      => true,
            'weekly'       => $weeklyStats,
            'total_logs'   => (int)$totals['c'],
            'total_sets'   => (int)$totals['total_sets'],
            'total_reps'   => (int)$totals['total_reps'],
            'training_time_sec' => $trainingTimeSec,
            'muscle_distribution' => $muscleDist,
        ]);

    /* ── Fitness goals (progress computed from logs) ─────────────── */

    case 'goals_list':
        require_once '../includes/gamification.php';
        require_once '../app/Modules/Fitness/FitnessGoals.php';
        json_out(['success' => true, 'goals' => (new FitnessGoals($uid))->all()]);

    case 'goal_add':
        require_once '../includes/gamification.php';
        require_once '../app/Modules/Fitness/FitnessGoals.php';
        [$id, $err] = (new FitnessGoals($uid))->create(
            sanitizeInput($_POST['goal_type'] ?? ''),
            $_POST['target'] ?? '',
            sanitizeInput($_POST['exercise_name'] ?? ''),
            sanitizeInput($_POST['deadline'] ?? '')
        );
        if ($err) json_out(['success' => false, 'error' => $err], 422);
        json_out(['success' => true, 'id' => $id]);

    case 'goal_delete':
        require_once '../app/Modules/Fitness/FitnessGoals.php';
        (new FitnessGoals($uid))->delete((int)($_POST['id'] ?? 0));
        json_out(['success' => true]);

    /* ── Nutrition (what the user ate/drank, as entered) ──────────── */

    case 'nutrition_day':
        require_once '../app/Modules/Fitness/Nutrition.php';
        $date = sanitizeInput($_POST['date'] ?? date('Y-m-d'));
        if (!Nutrition::validDate($date)) json_out(['success' => false, 'error' => 'Invalid date.'], 422);
        $n = new Nutrition($uid);
        json_out(['success' => true, 'recent' => $n->recent(7)] + $n->day($date));

    case 'nutrition_add':
        require_once '../app/Modules/Fitness/Nutrition.php';
        $err = (new Nutrition($uid))->add(
            sanitizeInput($_POST['date'] ?? date('Y-m-d')),
            sanitizeInput($_POST['meal'] ?? ''),
            sanitizeInput($_POST['name'] ?? ''),
            $_POST['calories'] ?? '', $_POST['protein_g'] ?? '', $_POST['water_ml'] ?? ''
        );
        if ($err) json_out(['success' => false, 'error' => $err], 422);
        json_out(['success' => true]);

    case 'nutrition_delete':
        require_once '../app/Modules/Fitness/Nutrition.php';
        (new Nutrition($uid))->delete((int)($_POST['id'] ?? 0));
        json_out(['success' => true]);

    case 'nutrition_targets_save':
        require_once '../app/Modules/Fitness/Nutrition.php';
        $err = (new Nutrition($uid))->saveTargets($_POST['calories'] ?? '', $_POST['protein_g'] ?? '', $_POST['water_ml'] ?? '');
        if ($err) json_out(['success' => false, 'error' => $err], 422);
        json_out(['success' => true]);

    /* ── Recovery check-ins (raw inputs; no derived score) ────────── */

    case 'recovery_list':
        require_once '../app/Modules/Fitness/Recovery.php';
        $r = new Recovery($uid);
        json_out(['success' => true, 'days' => $r->recent(14), 'week' => $r->weekSummary()]);

    case 'recovery_save':
        require_once '../app/Modules/Fitness/Recovery.php';
        $err = (new Recovery($uid))->save(
            sanitizeInput($_POST['date'] ?? date('Y-m-d')),
            $_POST['sleep_hours'] ?? '', $_POST['energy'] ?? '', $_POST['soreness'] ?? '',
            sanitizeInput($_POST['notes'] ?? '')
        );
        if ($err) json_out(['success' => false, 'error' => $err], 422);
        json_out(['success' => true]);

    case 'recovery_delete':
        require_once '../app/Modules/Fitness/Recovery.php';
        (new Recovery($uid))->delete(sanitizeInput($_POST['date'] ?? ''));
        json_out(['success' => true]);

    /* ── Optional body stats ─────────────────────────────────────── */

    case 'body_stats_add':
        $date = sanitizeInput($_POST['log_date'] ?? date('Y-m-d'));
        $weight = $_POST['weight_kg'] !== '' ? (float)$_POST['weight_kg'] : null;
        $bf     = $_POST['body_fat_pct'] !== '' ? (float)$_POST['body_fat_pct'] : null;
        $notes  = sanitizeInput($_POST['notes'] ?? '');
        if ($weight === null && $bf === null) json_out(['success' => false, 'error' => 'Enter at least weight or body fat %.'], 422);
        insert(
            "INSERT INTO body_stats (user_id,log_date,weight_kg,body_fat_pct,notes) VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE weight_kg=VALUES(weight_kg), body_fat_pct=VALUES(body_fat_pct), notes=VALUES(notes)",
            [$uid, $date, $weight, $bf, $notes ?: null]
        );
        json_out(['success' => true]);

    case 'body_stats_list':
        $rows = fetchAll("SELECT * FROM body_stats WHERE user_id=? ORDER BY log_date DESC LIMIT 60", [$uid]);
        json_out(['success' => true, 'entries' => $rows]);

    case 'body_stats_delete':
        $id = (int)($_POST['id'] ?? 0);
        delete("DELETE FROM body_stats WHERE id=? AND user_id=?", [$id, $uid]);
        json_out(['success' => true]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}

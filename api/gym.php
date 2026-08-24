<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();
verify_csrf();

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');

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
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $exercise  = sanitizeInput($_POST['exercise_name'] ?? '');
        $setNum    = max(1, (int)($_POST['set_number'] ?? 1));
        $reps      = $_POST['reps']   !== '' ? (int)$_POST['reps']     : null;
        $weight    = $_POST['weight'] !== '' ? (float)$_POST['weight'] : null;
        $planId    = (int)($_POST['plan_id'] ?? 0) ?: null;

        if (!$exercise) json_out(['success' => false, 'error' => 'Exercise name is required.'], 422);
        $session = fetchOne("SELECT id FROM workout_sessions WHERE id=? AND user_id=?", [$sessionId, $uid]);
        if (!$session) json_out(['success' => false, 'error' => 'Session not found.'], 404);

        // Best weight ever lifted for this exercise BEFORE this set — checked
        // before inserting, so the very set that sets a new PR is correctly
        // flagged. Combines granular workout_sets with legacy workout_logs
        // rows that predate this table (no child sets, aggregate weight only).
        $prevBest = fetchOne(
            "SELECT MAX(w) AS best FROM (
                SELECT ws.weight_kg AS w FROM workout_sets ws
                  JOIN workout_logs wl ON wl.id = ws.log_id
                 WHERE wl.user_id=? AND wl.exercise_name=?
                UNION ALL
                SELECT wl.weight_kg AS w FROM workout_logs wl
                 WHERE wl.user_id=? AND wl.exercise_name=?
                   AND wl.id NOT IN (SELECT DISTINCT log_id FROM workout_sets)
             ) x",
            [$uid, $exercise, $uid, $exercise]
        )['best'] ?? null;
        $isPr = $weight !== null && ($prevBest === null || $weight > (float)$prevBest);

        // One workout_logs row per (session, exercise) — reused across sets.
        $log = fetchOne(
            "SELECT id FROM workout_logs WHERE session_id=? AND user_id=? AND exercise_name=?",
            [$sessionId, $uid, $exercise]
        );
        if ($log) {
            $logId = $log['id'];
        } else {
            $logId = insert(
                "INSERT INTO workout_logs (user_id,plan_id,session_id,exercise_name,sets,reps,weight_kg,log_date)
                 VALUES (?,?,?,?,0,?,?,CURDATE())",
                [$uid, $planId, $sessionId, $exercise, $reps, $weight]
            );
        }

        insert(
            "INSERT INTO workout_sets (log_id,set_number,reps,weight_kg) VALUES (?,?,?,?)",
            [$logId, $setNum, $reps, $weight]
        );

        // Aggregate refresh: sets = how many logged so far, reps/weight_kg =
        // this (most recent) set — top-line summary shown outside the session.
        update(
            "UPDATE workout_logs SET sets=(SELECT COUNT(*) FROM workout_sets WHERE log_id=?), reps=?, weight_kg=? WHERE id=?",
            [$logId, $reps, $weight, $logId]
        );

        // Only unlock on a genuine improvement over a real prior number —
        // $prevBest === null means this is the exercise's first-ever set,
        // which trivially satisfies $isPr but isn't a "record" yet.
        $prNewlyUnlocked = false;
        if ($isPr && $prevBest !== null) {
            require_once '../includes/gamification.php';
            $prNewlyUnlocked = function_exists('unlockAchievement') ? unlockAchievement($uid, 'gym_first_pr') : false;
        }

        json_out([
            'success' => true, 'log_id' => $logId, 'is_pr' => $isPr, 'previous_best' => $prevBest,
            'newAchievements' => $prNewlyUnlocked ? ['gym_first_pr'] : [],
        ]);

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

        // Video lookup is independent of log history — an exercise never
        // logged before can still have a demo clip. Case-insensitive match
        // against the library (built-in or the user's own custom entry).
        $video = fetchOne(
            "SELECT video_path FROM exercise_library
              WHERE LOWER(name)=LOWER(?) AND (user_id IS NULL OR user_id=?) AND video_path IS NOT NULL
              LIMIT 1",
            [$exercise, $uid]
        );
        $videoUrl = $video ? APP_BASE . '/' . $video['video_path'] : null;

        $lastLog = fetchOne(
            "SELECT id, log_date, sets, reps, weight_kg FROM workout_logs
              WHERE user_id=? AND exercise_name=? ORDER BY created_at DESC LIMIT 1",
            [$uid, $exercise]
        );
        if (!$lastLog) json_out(['success' => true, 'found' => false, 'video_url' => $videoUrl]);

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
            'video_url' => $videoUrl,
        ]);

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
            $ex = fetchOne("SELECT id FROM exercise_library WHERE id=?", [$exerciseId]);
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

        update("UPDATE exercise_library SET video_path=? WHERE id=?", ['assets/vids/' . $filename, $exerciseId]);
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

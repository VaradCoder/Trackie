<?php
/**
 * Trackie core activity engine.
 *
 *   recordActivity()  — something real happened (habit done, workout logged…)
 *   undoActivity()    — it was un-done (habit unticked, todo re-opened)
 *   activityStreak()  — consecutive active days, overall or for one module
 *   activityDays()    — per-day counts for charts / heatmaps
 *
 * Every completion goes through here, so XP, streaks, analytics and the
 * weekly review all agree. Rules:
 *   - One row per real completion: UNIQUE(user, action, ref_type, ref_id).
 *     Retries and double-clicks can never double count.
 *   - XP comes ONLY from ACTIVITY_XP below and is awarded through
 *     awardXpOnce() with the same keys as before, so no user is paid twice
 *     for something they already earned XP for.
 *   - Undo removes the activity (streaks/analytics stay truthful). XP already
 *     earned is kept — the same behaviour Trackie always had.
 */

require_once __DIR__ . '/gamification.php';

/** action => [module, xp]. The one place XP values live. */
const ACTIVITY_TYPES = [
    // Plan
    'todo'               => ['todos',       10],   // per todo
    'habit'              => ['habits',      15],   // per habit per day
    'goal'               => ['goals',      250],   // per goal completion
    'routine'            => ['routines',    12],   // per routine per day
    'study'              => ['study',       25],   // per study task
    'focus'              => ['focus',       20],   // per completed focus session
    // Life
    'workout'            => ['fitness',     15],   // per logged exercise entry
    'workout_session'    => ['fitness',     20],   // per finished workout session
    'fitness_goal'       => ['fitness',     50],   // per fitness goal reached
    'meditation_session' => ['meditation',  10],
    'sports_session'     => ['sports',      15],
    'finance_log'        => ['finance',      0],   // once per day with a logged transaction (streak only — no XP to farm)
    // Hobbies
    'reading_session'    => ['reading',     10],   // once per day with reading
    'book_finished'      => ['reading',     30],
    'game_completed'     => ['gaming',      30],
    'photo_shoot'        => ['photography', 15],
    'photo_upload_day'   => ['photography',  5],   // once per day with uploads
    'coding_session'     => ['coding',      10],   // once per day with a coding session
    'project_done'       => ['coding',      50],   // per project moved to Done
    'recipe_cooked'      => ['cooking',     10],   // per recipe per day
    'writing_day'        => ['writing',     10],   // once per day with words added
    'art_session'        => ['art',         10],   // once per day with practice
    'garden_care'        => ['gardening',    5],   // once per day with plant care
];

/** Human labels for modules (streak cards, analytics). */
const ACTIVITY_MODULES = [
    'todos' => 'Todos', 'habits' => 'Habits', 'goals' => 'Goals', 'routines' => 'Routines', 'study' => 'Study',
    'focus' => 'Focus', 'fitness' => 'Fitness', 'meditation' => 'Meditation', 'sports' => 'Sports', 'finance' => 'Finance',
    'reading' => 'Reading', 'gaming' => 'Gaming', 'photography' => 'Photography',
    'coding' => 'Coding', 'cooking' => 'Cooking', 'writing' => 'Writing', 'art' => 'Art', 'gardening' => 'Gardening',
];

function activityXp(string $action): int {
    return ACTIVITY_TYPES[$action][1] ?? 0;
}

function activityModule(string $action): string {
    return ACTIVITY_TYPES[$action][0] ?? 'other';
}

function activityReady(): bool {
    static $ok = null;
    return $ok ??= tableExists('activity_log');
}

/**
 * Record a completion. Returns the XP result (awardXp shape) when XP was
 * newly awarded, else null. Never throws — a logging problem must not break
 * the action the user just took.
 *
 * @param string   $refType  stable key type, e.g. 'todo', 'log:2026-09-29'
 * @param ?int     $refId    the item id (null only for truly repeatable events)
 * @param array    $opt      'date' => Y-m-d (default today), 'xp' => override
 */
function recordActivity(int $uid, string $action, string $refType, ?int $refId, array $opt = []): ?array {
    $xp   = (int)($opt['xp'] ?? activityXp($action));
    $date = isset($opt['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$opt['date']) ? $opt['date'] : date('Y-m-d');

    try {
        if (activityReady()) {
            ensureActivityBackfill($uid);
            $new = update(
                "INSERT IGNORE INTO activity_log (user_id, module, action, ref_type, ref_id, occurred_on, xp)
                 VALUES (?,?,?,?,?,?,?)",
                [$uid, activityModule($action), $action, $refType, $refId, $date, $xp]
            );
            if ($new > 0) $checkAfter = true;
        }
    } catch (Throwable $e) {
        error_log('recordActivity: ' . $e->getMessage());
    }

    $res = null;
    if ($xp > 0 && function_exists('awardXpOnce')) {
        // Same dedupe keys as before the engine existed → nobody is paid twice.
        $res = $refId !== null ? awardXpOnce($uid, $action, $xp, $refType, $refId)
                               : awardXp($uid, $action, $xp, $refType, null);
    }
    if (!empty($checkAfter)) {
        try {
            awardStreakMilestones($uid);
            // Goals linked to a source (pages read, focus minutes, …) move now.
            require_once __DIR__ . '/goal_sources.php';
            syncLinkedGoals($uid);
            achievementBuffer(checkAchievements($uid));
        } catch (Throwable $e) {
            error_log('recordActivity checks: ' . $e->getMessage());
        }
    }
    return $res;
}

/** Remove a completion that was undone. XP already earned is kept. */
function undoActivity(int $uid, string $action, string $refType, ?int $refId): void {
    if (!activityReady()) return;
    try {
        update(
            "DELETE FROM activity_log WHERE user_id=? AND action=? AND ref_type=? AND " . ($refId === null ? 'ref_id IS NULL' : 'ref_id=?'),
            array_merge([$uid, $action, $refType], $refId === null ? [] : [$refId])
        );
    } catch (Throwable $e) {
        error_log('undoActivity: ' . $e->getMessage());
    }
}

/**
 * Current/best streak of days with at least one activity — overall
 * ("Trackie streak") or for one module ('habits', 'reading', …).
 */
function activityStreak(int $uid, ?string $module = null): array {
    if (!activityReady()) return ['current' => 0, 'best' => 0];
    ensureActivityBackfill($uid);
    $rows = fetchAll(
        "SELECT DISTINCT occurred_on d FROM activity_log WHERE user_id=?" . ($module ? " AND module=?" : ''),
        $module ? [$uid, $module] : [$uid]
    );
    return calculateStreaks(array_column($rows, 'd'));
}

/** Streaks for every module the user has ever been active in, best-current first. */
function moduleStreaks(int $uid): array {
    if (!activityReady()) return [];
    ensureActivityBackfill($uid);
    $byModule = [];
    foreach (fetchAll("SELECT module, occurred_on d FROM activity_log WHERE user_id=? GROUP BY module, occurred_on", [$uid]) as $r) {
        $byModule[$r['module']][] = $r['d'];
    }
    $out = [];
    foreach ($byModule as $module => $dates) {
        $s = calculateStreaks($dates);
        $out[$module] = ['module' => $module, 'label' => ACTIVITY_MODULES[$module] ?? ucfirst($module),
                         'current' => $s['current'], 'best' => $s['best'], 'last' => max($dates)];
    }
    uasort($out, static fn($a, $b) => [$b['current'], $b['best']] <=> [$a['current'], $a['best']]);
    return $out;
}

/** Activity count per day for the last $days days (oldest first). */
function activityDays(int $uid, int $days = 7, ?string $module = null): array {
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' day'));
    $by = [];
    if (activityReady()) {
        ensureActivityBackfill($uid);
        $by = array_column(fetchAll(
            "SELECT occurred_on d, COUNT(*) n FROM activity_log WHERE user_id=? AND occurred_on>=?"
            . ($module ? " AND module=?" : '') . " GROUP BY occurred_on",
            $module ? [$uid, $from, $module] : [$uid, $from]
        ), 'n', 'd');
    }
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} day"));
        $out[] = ['date' => $d, 'count' => (int)($by[$d] ?? 0)];
    }
    return $out;
}

/**
 * One-time copy of a user's existing history into activity_log, so streaks
 * don't reset to zero when the engine goes live. Runs once per user (marked
 * in user_settings). Each source is skipped if its table doesn't exist.
 * INSERT IGNORE + the unique key make it safe to re-run.
 */
function ensureActivityBackfill(int $uid): void {
    static $done = [];
    if (isset($done[$uid]) || !activityReady() || !tableExists('user_settings')) return;
    $done[$uid] = true;

    $row = fetchOne("SELECT activity_backfilled_at FROM user_settings WHERE user_id=?", [$uid]);
    if ($row && $row['activity_backfilled_at']) return;

    $ins = "INSERT IGNORE INTO activity_log (user_id, module, action, ref_type, ref_id, occurred_on, xp) ";
    $sources = [
        // XP history is the richest record of what happened (dates embedded in 'log:YYYY-MM-DD' keys).
        'xp_events' => $ins . "SELECT user_id,
                 CASE action " . implode(' ', array_map(static fn($a, $t) => "WHEN '{$a}' THEN '{$t[0]}'", array_keys(ACTIVITY_TYPES), ACTIVITY_TYPES)) . " ELSE 'other' END,
                 action, COALESCE(ref_type, ''), ref_id,
                 CASE WHEN ref_type REGEXP ':[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN STR_TO_DATE(SUBSTRING_INDEX(ref_type, ':', -1), '%Y-%m-%d')
                      WHEN ref_type IN ('photo_day','reading_day') THEN STR_TO_DATE(ref_id, '%Y%m%d')
                      ELSE DATE(created_at) END,
                 xp
               FROM xp_events WHERE user_id=? AND action NOT IN ('achievement','streak_7','streak_30','focus')",
        'logs' => $ins . "SELECT user_id, 'habits', 'habit', CONCAT('log:', date_completed), habit_id, date_completed, 0 FROM logs WHERE user_id=?",
        'todos' => $ins . "SELECT user_id, 'todos', 'todo', 'todo', id, DATE(completed_at), 0 FROM todos
                           WHERE user_id=? AND completed=1 AND completed_at IS NOT NULL AND deleted_at IS NULL",
        'routine_logs' => $ins . "SELECT user_id, 'routines', 'routine', CONCAT('routine:', log_date), routine_id, log_date, 0 FROM routine_logs WHERE user_id=?",
        'focus_sessions' => $ins . "SELECT user_id, 'focus', 'focus', 'focus_session', id, DATE(COALESCE(completed_at, started_at)), 0 FROM focus_sessions
                                    WHERE user_id=? AND completed=1 AND type='focus'",
        'workout_logs' => $ins . "SELECT user_id, 'fitness', 'workout', CONCAT('log:', log_date), id, log_date, 0 FROM workout_logs WHERE user_id=?",
        'meditation_sessions' => $ins . "SELECT user_id, 'meditation', 'meditation_session', CONCAT('log:', session_date), id, session_date, 0 FROM meditation_sessions WHERE user_id=?",
        'sports_sessions' => $ins . "SELECT user_id, 'sports', 'sports_session', CONCAT('log:', session_date), id, session_date, 0 FROM sports_sessions WHERE user_id=?",
        'reading_sessions' => $ins . "SELECT user_id, 'reading', 'reading_session', 'reading_day', CAST(DATE_FORMAT(session_date, '%Y%m%d') AS UNSIGNED), session_date, 0
                                      FROM reading_sessions WHERE user_id=? GROUP BY user_id, session_date",
        'photo_images' => $ins . "SELECT user_id, 'photography', 'photo_upload_day', 'photo_day', CAST(DATE_FORMAT(created_at, '%Y%m%d') AS UNSIGNED), DATE(created_at), 0
                                  FROM photo_images WHERE user_id=? GROUP BY user_id, DATE(created_at)",
        'photos' => $ins . "SELECT user_id, 'photography', 'photo_shoot', 'shoot', id, COALESCE(taken_date, DATE(created_at)), 0 FROM photos WHERE user_id=?",
    ];
    foreach ($sources as $table => $sql) {
        try {
            if (tableExists($table)) update($sql, [$uid]);
        } catch (Throwable $e) {
            error_log("activity backfill ({$table}): " . $e->getMessage());
        }
    }
    // Goals / games / books only have XP-backed completions → covered by xp_events.
    update("INSERT INTO user_settings (user_id, activity_backfilled_at) VALUES (?, NOW())
            ON DUPLICATE KEY UPDATE activity_backfilled_at = NOW()", [$uid]);
}

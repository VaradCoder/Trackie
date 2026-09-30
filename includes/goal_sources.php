<?php
/**
 * Goals that fill themselves from real activity.
 *
 * A goal with a `source` has its progress computed from what the user
 * actually logged since `source_since` (habit check-ins, pages read, focus
 * minutes, …) instead of being typed in. The result is written back to
 * goals.progress so everything that already reads that column — goal cards,
 * Today, analytics, achievements — keeps working unchanged. Reaching the
 * target records the 'goal' activity (XP once, via the activity engine).
 */

require_once __DIR__ . '/activity.php';

/** key => [label, unit, table, SQL returning n for (uid, since[, ref])] */
const GOAL_SOURCES = [
    'habit'      => ['Check-ins of a habit', 'check-ins', 'logs',
                     "SELECT COUNT(*) n FROM logs l JOIN habits h ON h.id = l.habit_id WHERE h.user_id = ? AND l.date_completed >= ? AND l.habit_id = ?"],
    'todos'      => ['Todos completed', 'todos', 'todos',
                     "SELECT COUNT(*) n FROM todos WHERE user_id = ? AND completed = 1 AND deleted_at IS NULL AND parent_id IS NULL AND DATE(completed_at) >= ?"],
    'focus'      => ['Focus minutes', 'minutes', 'focus_sessions',
                     "SELECT COALESCE(SUM(duration_min), 0) n FROM focus_sessions WHERE user_id = ? AND completed = 1 AND type = 'focus' AND DATE(started_at) >= ?"],
    'workouts'   => ['Workouts finished', 'workouts', 'workout_sessions',
                     "SELECT COUNT(*) n FROM workout_sessions WHERE user_id = ? AND ended_at IS NOT NULL AND session_date >= ?"],
    'reading'    => ['Pages read', 'pages', 'reading_sessions',
                     "SELECT COALESCE(SUM(pages), 0) n FROM reading_sessions WHERE user_id = ? AND session_date >= ?"],
    'writing'    => ['Words written', 'words', 'writing_log',
                     "SELECT COALESCE(SUM(words_added), 0) n FROM writing_log WHERE user_id = ? AND log_date >= ?"],
    'meditation' => ['Minutes meditated', 'minutes', 'meditation_sessions',
                     "SELECT COALESCE(SUM(duration_min), 0) n FROM meditation_sessions WHERE user_id = ? AND session_date >= ?"],
    'coding'     => ['Coding minutes', 'minutes', 'coding_sessions',
                     "SELECT COALESCE(SUM(minutes), 0) n FROM coding_sessions WHERE user_id = ? AND session_date >= ?"],
];

/** Sources whose tables exist on this install (for the picker). */
function availableGoalSources(): array {
    return array_filter(GOAL_SOURCES, static fn($s) => tableExists($s[2]));
}

/** Current value of a source for a user, counted from $since (Y-m-d). */
function goalSourceValue(int $uid, string $source, ?int $ref, string $since): int {
    $def = GOAL_SOURCES[$source] ?? null;
    if (!$def || !tableExists($def[2])) return 0;
    $params = [$uid, $since];
    if ($source === 'habit') {
        if (!$ref) return 0;
        $params[] = $ref;
    }
    try { return (int)(fetchOne($def[3], $params)['n'] ?? 0); }
    catch (Throwable $e) { error_log("goal source $source: " . $e->getMessage()); return 0; }
}

/** Human description, e.g. "Pages read since 3 Sep" or "Check-ins of Morning run since …". */
function goalSourceLabel(array $g, array $habitNames = []): string {
    $def = GOAL_SOURCES[$g['source'] ?? ''] ?? null;
    if (!$def) return '';
    $what = $g['source'] === 'habit' ? 'Check-ins of ' . ($habitNames[(int)$g['source_ref']] ?? 'a habit') : $def[0];
    return $what . ($g['source_since'] ? ' since ' . date('j M', strtotime($g['source_since'])) : '');
}

/**
 * Recompute every linked goal of this user and store the progress. Returns
 * the ids of goals that became complete on this sync. Cheap: one query per
 * linked goal, and only goals that aren't complete yet.
 */
function syncLinkedGoals(int $uid): array {
    static $done = [];
    if (isset($done[$uid])) return [];      // once per request
    $done[$uid] = true;
    try {
        $goals = fetchAll("SELECT id, source, source_ref, source_since, created_at, progress, target_value
                             FROM goals WHERE user_id = ? AND source IS NOT NULL AND progress < target_value", [$uid]);
    } catch (Throwable $e) {
        return [];                           // pre-migration database
    }
    $completed = [];
    foreach ($goals as $g) {
        $since = $g['source_since'] ?: substr((string)$g['created_at'], 0, 10);
        $val   = min((int)$g['target_value'], goalSourceValue($uid, $g['source'], $g['source_ref'] ? (int)$g['source_ref'] : null, $since));
        if ($val === (int)$g['progress']) continue;
        update("UPDATE goals SET progress = ? WHERE id = ? AND user_id = ?", [$val, $g['id'], $uid]);
        if ($val >= (int)$g['target_value']) {
            recordActivity($uid, 'goal', 'goal', (int)$g['id']);   // XP once per goal
            $completed[] = (int)$g['id'];
            if (function_exists('createNotification')) {
                $name = fetchOne("SELECT goal_name FROM goals WHERE id = ?", [$g['id']])['goal_name'] ?? 'A goal';
                createNotification($uid, 'goal', '🎯 Goal reached: ' . $name, 'Filled from your logged activity.', APP_BASE . '/pages/goals.php');
            }
        }
    }
    return $completed;
}

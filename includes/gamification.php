<?php
/**
 * Trackie — Gamification engine (Phase 2)
 * XP, levels & achievements. Reuses existing tables: user_xp, xp_events, achievements.
 * Requires config/database.php + includes/functions.php (db helpers, calculateStreaks, tableExists).
 *
 * All functions are safe no-ops if the gamification tables don't exist yet.
 */

/* ── Levels ───────────────────────────────────────────────────── */

/** Cumulative XP required to REACH a level. Level 1 = 0. */
function xpForLevel(int $level): int {
    if ($level <= 1) return 0;
    return 50 * $level * ($level - 1);   // L2=100, L5=1000, L10=4500, L20=19000, L50=122500
}

/** The level a given total XP corresponds to. */
function levelForXp(int $xp): int {
    $L = 1;
    while (xpForLevel($L + 1) <= $xp) $L++;
    return $L;
}

/** Friendly title for a level. */
function levelTitle(int $level): string {
    if ($level >= 50) return 'Productivity Master';
    if ($level >= 20) return 'Disciplined';
    if ($level >= 10) return 'Focused';
    if ($level >= 5)  return 'Consistent';
    return 'Beginner';
}

/** Full XP snapshot for a user (total, level, title, progress to next). */
function xpSummary(int $uid): array {
    $total = 0;
    if (tableExists('user_xp')) {
        $total = (int)(fetchOne("SELECT total_xp FROM user_xp WHERE user_id=?", [$uid])['total_xp'] ?? 0);
    }
    $level = levelForXp($total);
    $cur   = xpForLevel($level);
    $next  = xpForLevel($level + 1);
    $span  = max(1, $next - $cur);
    return [
        'total'   => $total,
        'level'   => $level,
        'title'   => levelTitle($level),
        'into'    => $total - $cur,
        'span'    => $span,
        'next_at' => $next,
        'to_next' => max(0, $next - $total),
        'pct'     => (int)round(($total - $cur) / $span * 100),
    ];
}

/* ── XP awarding ───────────────────────────────────────────────── */

/** Award XP (repeatable). Logs an event, bumps the total, recomputes level. */
function awardXp(int $uid, string $action, int $xp, ?string $refType = null, ?int $refId = null): array {
    if (!tableExists('user_xp')) return ['ok' => false];

    $before    = (int)(fetchOne("SELECT total_xp FROM user_xp WHERE user_id=?", [$uid])['total_xp'] ?? 0);
    $oldLevel  = levelForXp($before);

    insert("INSERT INTO xp_events (user_id,action,xp,ref_type,ref_id) VALUES (?,?,?,?,?)",
           [$uid, $action, $xp, $refType, $refId]);
    insert("INSERT INTO user_xp (user_id,total_xp,level) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE total_xp = total_xp + VALUES(total_xp)",
           [$uid, $xp, levelForXp($xp)]);

    $total    = (int)fetchOne("SELECT total_xp FROM user_xp WHERE user_id=?", [$uid])['total_xp'];
    $newLevel = levelForXp($total);
    update("UPDATE user_xp SET level=? WHERE user_id=?", [$newLevel, $uid]);

    // Surface level-ups in the notification bell
    if ($newLevel > $oldLevel && function_exists('createNotification')) {
        createNotification(
            $uid, 'xp',
            "⚡ Level {$newLevel} — " . levelTitle($newLevel),
            "You leveled up! Keep the momentum going.",
            APP_BASE . '/pages/progress.php'
        );
    }

    return [
        'ok'        => true,
        'gained'    => $xp,
        'total'     => $total,
        'level'     => $newLevel,
        'leveledUp' => $newLevel > $oldLevel,
        'title'     => levelTitle($newLevel),
    ];
}

/**
 * Award XP only once for a given (action, refType, refId) combination.
 * Returns the award result, or null if it was already granted.
 */
function awardXpOnce(int $uid, string $action, int $xp, string $refType, int $refId): ?array {
    if (!tableExists('xp_events')) return null;
    $exists = fetchOne(
        "SELECT id FROM xp_events WHERE user_id=? AND action=? AND ref_type=? AND ref_id=? LIMIT 1",
        [$uid, $action, $refType, $refId]
    );
    if ($exists) return null;
    return awardXp($uid, $action, $xp, $refType, $refId);
}


/**
 * Award streak-milestone XP (7 / 30 days) once each, on the Trackie streak
 * (any logged activity) — falls back to the habit streak before the activity
 * engine exists. Same XP keys as always, so nobody is paid twice.
 */
function awardStreakMilestones(int $uid): void {
    if (function_exists('activityStreak') && function_exists('activityReady') && activityReady()) {
        $cur = (int)(activityStreak($uid)['current'] ?? 0);
    } elseif (function_exists('habitStreaks')) {
        $cur = (int)(habitStreaks($uid)['current'] ?? 0);
    } else {
        return;
    }
    if ($cur >= 7 && awardXpOnce($uid, 'streak_7', 100, 'streak', 7) && function_exists('createNotification')) {
        createNotification($uid, 'streak', '🔥 7-day streak!', 'A week strong — keep it alive.');
    }
    if ($cur >= 30 && awardXpOnce($uid, 'streak_30', 500, 'streak', 30) && function_exists('createNotification')) {
        createNotification($uid, 'streak', '🔥 30-day streak!', 'Incredible consistency. +500 XP.');
    }
}

/**
 * Achievements unlocked inside recordActivity() are held here so the API
 * call that follows (checkAchievements() → toast payload) still reports them.
 */
function achievementBuffer(?array $add = null): array {
    static $buf = [];
    if ($add === null) { $out = $buf; $buf = []; return $out; }
    $buf = array_values(array_unique(array_merge($buf, $add)));
    return $buf;
}

/* ── Achievements ─────────────────────────────────────────────── */

function achievementDefs(): array {
    return [
        'first_todo'    => ['emoji' => '🏅', 'name' => 'First Todo',            'desc' => 'Complete your first todo'],
        'streak_7'      => ['emoji' => '🔥', 'name' => '7 Day Streak',          'desc' => 'Reach a 7-day habit streak'],
        'consistency'   => ['emoji' => '📈', 'name' => 'Consistency Champion',  'desc' => 'Reach a 14-day streak'],
        'study_beast'   => ['emoji' => '📚', 'name' => 'Study Beast',           'desc' => 'Complete 20 study tasks'],
        'fitness_freak' => ['emoji' => '💪', 'name' => 'Fitness Freak',         'desc' => 'Log a Fitness habit 15 times'],
        'goal_crusher'  => ['emoji' => '🎯', 'name' => 'Goal Crusher',          'desc' => 'Complete your first goal'],
        'productivity'  => ['emoji' => '⚡', 'name' => 'Productivity Machine',  'desc' => 'Complete 100 todos'],
        'focus_warrior' => ['emoji' => '⏱', 'name' => 'Focus Warrior',         'desc' => 'Complete 10 focus sessions'],
        'time_master'   => ['emoji' => '🏆', 'name' => 'Time Master',           'desc' => 'Earn 5,000 XP'],
        'gym_first_rep' => ['emoji' => '🏋', 'name' => 'First Rep',             'desc' => 'Log your first workout'],
        'gym_10'        => ['emoji' => '💪', 'name' => 'Iron Habit',            'desc' => 'Log 10 workouts'],
        'gym_50'        => ['emoji' => '🏆', 'name' => 'Gym Veteran',           'desc' => 'Log 50 workout days'],
        'gym_streak_7'  => ['emoji' => '🔥', 'name' => 'Week Warrior',          'desc' => 'Reach a 7-day gym streak'],
        'gym_first_pr'  => ['emoji' => '📈', 'name' => 'New Record',            'desc' => 'Set your first personal record'],
        'project_ship'  => ['emoji' => '🚀', 'name' => 'Shipped It',           'desc' => 'Mark your first project as Done'],
        'bookworm_5'    => ['emoji' => '📖', 'name' => 'Bookworm',              'desc' => 'Finish 5 books'],
        // Across Trackie (activity log)
        'trackie_7'     => ['emoji' => '🔥', 'name' => 'On a Roll',             'desc' => 'Log something 7 days in a row'],
        'trackie_30'    => ['emoji' => '🌟', 'name' => 'Unstoppable',           'desc' => 'Log something 30 days in a row'],
        'all_rounder'   => ['emoji' => '🧭', 'name' => 'All-Rounder',           'desc' => 'Be active in 5 different areas'],
        'chef_10'       => ['emoji' => '🍳', 'name' => 'Home Chef',             'desc' => 'Cook 10 recipes'],
        'writer_5k'     => ['emoji' => '✍️', 'name' => 'Wordsmith',             'desc' => 'Write 5,000 words'],
        'green_thumb'   => ['emoji' => '🌱', 'name' => 'Green Thumb',           'desc' => 'Care for plants on 14 days'],
        'coder_10'      => ['emoji' => '💻', 'name' => 'Code Habit',            'desc' => 'Code on 10 days'],
        'zen_7'         => ['emoji' => '🧘', 'name' => 'Inner Calm',            'desc' => 'Meditate 7 days in a row'],
        'gamer_first'   => ['emoji' => '🎮', 'name' => 'Credits Roll',          'desc' => 'Complete your first game'],
        'shutterbug'    => ['emoji' => '📷', 'name' => 'Shutterbug',            'desc' => 'Upload photos on 10 days'],
        'reader_30'     => ['emoji' => '📚', 'name' => 'Daily Reader',          'desc' => 'Read on 30 days'],
        'athlete_10'    => ['emoji' => '🏅', 'name' => 'Athlete',               'desc' => 'Log 10 sports sessions'],
        'artist_10'     => ['emoji' => '🎨', 'name' => 'Sketchbook',            'desc' => 'Practise art on 10 days'],
        'saver'         => ['emoji' => '💰', 'name' => 'Saver',                 'desc' => 'Reach a savings goal'],
    ];
}

/** Unlock an achievement once; awards a +50 XP bonus. Returns true if newly unlocked. */
function unlockAchievement(int $uid, string $key): bool {
    if (!tableExists('achievements')) return false;
    if (fetchOne("SELECT id FROM achievements WHERE user_id=? AND key_name=?", [$uid, $key])) return false;
    insert("INSERT INTO achievements (user_id,key_name) VALUES (?,?)", [$uid, $key]);
    awardXp($uid, 'achievement', 50, 'achievement', null);
    $def = achievementDefs()[$key] ?? null;
    if ($def && function_exists('createNotification')) {
        createNotification(
            $uid, 'achievement',
            $def['emoji'] . ' ' . $def['name'] . ' unlocked!',
            $def['desc'] . ' (+50 XP)',
            APP_BASE . '/pages/progress.php'
        );
    }
    return true;
}

/** Evaluate every achievement condition against real data; unlock any newly met. */
function checkAchievements(int $uid): array {
    if (!tableExists('achievements')) return [];
    $newly = achievementBuffer();
    $count = fn($sql) => (int)(fetchOne($sql, [$uid])['c'] ?? 0);

    $todosDone   = $count("SELECT COUNT(*) c FROM todos WHERE user_id=? AND completed=1 AND deleted_at IS NULL");
    $studyDone   = tableExists('study_plan') ? $count("SELECT COUNT(*) c FROM study_plan WHERE user_id=? AND completed=1") : 0;
    $goalsDone   = $count("SELECT COUNT(*) c FROM goals WHERE user_id=? AND progress >= target_value");
    $fitnessLogs = $count("SELECT COUNT(*) c FROM logs l JOIN habits h ON h.id=l.habit_id WHERE l.user_id=? AND h.group_name='Fitness'");
    $focusDone   = tableExists('focus_sessions') ? $count("SELECT COUNT(*) c FROM focus_sessions WHERE user_id=? AND completed=1") : 0;
    $totalXp     = (int)(fetchOne("SELECT total_xp FROM user_xp WHERE user_id=?", [$uid])['total_xp'] ?? 0);
    $streaks     = function_exists('habitStreaks') ? habitStreaks($uid) : ['best' => 0];
    $bestStreak  = (int)($streaks['best'] ?? 0);

    $gymDays      = tableExists('workout_logs')  ? $count("SELECT COUNT(DISTINCT log_date) c FROM workout_logs WHERE user_id=?") : 0;
    $gymStreak    = tableExists('workout_logs')  ? (function_exists('calculateStreaks')
        ? (calculateStreaks(array_column(fetchAll("SELECT DISTINCT log_date FROM workout_logs WHERE user_id=? ORDER BY log_date", [$uid]), 'log_date'))['current'] ?? 0)
        : 0) : 0;
    $projectsDone = tableExists('projects') ? $count("SELECT COUNT(*) c FROM projects WHERE user_id=? AND status='done'") : 0;
    $booksFinished = tableExists('books') ? $count("SELECT COUNT(*) c FROM books WHERE user_id=? AND status='finished'") : 0;

    $met = [
        'first_todo'    => $todosDone >= 1,
        'productivity'  => $todosDone >= 100,
        'streak_7'      => $bestStreak >= 7,
        'consistency'   => $bestStreak >= 14,
        'study_beast'   => $studyDone >= 20,
        'fitness_freak' => $fitnessLogs >= 15,
        'goal_crusher'  => $goalsDone >= 1,
        'focus_warrior' => $focusDone >= 10,
        'time_master'   => $totalXp >= 5000,
        'gym_first_rep' => $gymDays >= 1,
        'gym_10'        => $gymDays >= 10,
        'gym_50'        => $gymDays >= 50,
        'gym_streak_7'  => $gymStreak >= 7,
        'project_ship'  => $projectsDone >= 1,
        'bookworm_5'    => $booksFinished >= 5,
    ];

    // Activity-log based (only when the engine is installed).
    if (tableExists('activity_log') && function_exists('activityStreak')) {
        $act = [];
        foreach (fetchAll("SELECT action, COUNT(*) n FROM activity_log WHERE user_id=? GROUP BY action", [$uid]) as $r) $act[$r['action']] = (int)$r['n'];
        $a = fn(string $k) => $act[$k] ?? 0;
        $modules  = $count("SELECT COUNT(DISTINCT module) c FROM activity_log WHERE user_id=?");
        $words    = tableExists('writing_log') ? $count("SELECT COALESCE(SUM(words_added),0) c FROM writing_log WHERE user_id=?") : 0;
        $savedOk  = $count("SELECT COUNT(*) c FROM goals WHERE user_id=? AND kind='savings' AND target_value > 0 AND progress >= target_value");
        $met += [
            'trackie_7'   => (int)activityStreak($uid)['best'] >= 7,
            'trackie_30'  => (int)activityStreak($uid)['best'] >= 30,
            'all_rounder' => $modules >= 5,
            'chef_10'     => $a('recipe_cooked') >= 10,
            'writer_5k'   => $words >= 5000,
            'green_thumb' => $a('garden_care') >= 14,
            'coder_10'    => $a('coding_session') >= 10,
            'zen_7'       => (int)activityStreak($uid, 'meditation')['best'] >= 7,
            'gamer_first' => $a('game_completed') >= 1,
            'shutterbug'  => $a('photo_upload_day') >= 10,
            'reader_30'   => $a('reading_session') >= 30,
            'athlete_10'  => $a('sports_session') >= 10,
            'artist_10'   => $a('art_session') >= 10,
            'saver'       => $savedOk >= 1,
        ];
    }
    foreach ($met as $key => $ok) {
        if ($ok && unlockAchievement($uid, $key)) $newly[] = $key;
    }
    return $newly;
}

/**
 * Map checkAchievements()'s newly-unlocked keys to display data for the
 * client's achievement-unlock toast. Callers pass this straight through in
 * their JSON response (`'achievements' => achievementPayload($newly)`);
 * Trackie.showAchievementToasts() consumes the shape as-is.
 */
function achievementPayload(array $keys): array {
    if (!$keys) return [];
    $defs = achievementDefs();
    $out = [];
    foreach ($keys as $key) {
        if (!isset($defs[$key])) continue;
        $out[] = ['key' => $key] + $defs[$key];
    }
    return $out;
}

/** Recent XP events for the progress page. */
function recentXpEvents(int $uid, int $limit = 15): array {
    if (!tableExists('xp_events')) return [];
    return fetchAll("SELECT action, xp, ref_type, created_at FROM xp_events WHERE user_id=? ORDER BY id DESC LIMIT ?",
                    [$uid, $limit]);
}

/** Unlocked achievement keys for a user. */
function unlockedAchievements(int $uid): array {
    if (!tableExists('achievements')) return [];
    return array_column(fetchAll("SELECT key_name FROM achievements WHERE user_id=?", [$uid]), 'key_name');
}

/* ── Focus sessions ────────────────────────────────────────────── */

/** Focus-time stats (completed 'focus' sessions): today / week / month minutes + count + streak. */
function focusStats(int $uid): array {
    $z = ['today' => 0, 'week' => 0, 'month' => 0, 'sessions_today' => 0, 'total_sessions' => 0, 'streak' => 0];
    if (!tableExists('focus_sessions')) return $z;
    $sum = fn($where) => (int)(fetchOne(
        "SELECT COALESCE(SUM(duration_min),0) m FROM focus_sessions
         WHERE user_id=? AND completed=1 AND type='focus' AND $where", [$uid])['m'] ?? 0);
    $z['today']  = $sum("DATE(started_at)=CURDATE()");
    $z['week']   = $sum("started_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)");
    $z['month']  = $sum("started_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)");
    $z['sessions_today'] = (int)(fetchOne(
        "SELECT COUNT(*) c FROM focus_sessions WHERE user_id=? AND completed=1 AND type='focus' AND DATE(started_at)=CURDATE()", [$uid])['c'] ?? 0);
    $z['total_sessions'] = (int)(fetchOne(
        "SELECT COUNT(*) c FROM focus_sessions WHERE user_id=? AND completed=1 AND type='focus'", [$uid])['c'] ?? 0);
    // Focus streak = consecutive days (up to today) with >=1 completed focus session
    $days = array_column(fetchAll(
        "SELECT DISTINCT DATE(started_at) d FROM focus_sessions WHERE user_id=? AND completed=1 AND type='focus'", [$uid]), 'd');
    if (function_exists('calculateStreaks')) $z['streak'] = (int)(calculateStreaks($days)['current'] ?? 0);
    return $z;
}

/* ── Trackie Score (productivity score 0–100) ──────────────────── */

/**
 * Unified daily productivity score. Each signal contributes 0–1; only signals
 * that actually have data are counted, then renormalized — so a new user isn't
 * unfairly zeroed. Weights: habits .30, todos .30, streak .15, focus .15, goals .10.
 */
function trackieScore(int $uid): array {
    $parts = [];  // [value0to1, weight]
    // Raw counts are returned alongside the score so callers that need the
    // underlying numbers (daily_snapshots, the weekly review) reuse this one
    // query set instead of re-deriving them and risking a different answer.
    $raw = [
        'habits_done' => 0, 'habits_total' => 0,
        'todos_done'  => 0, 'todos_total'  => 0,
        'focus_minutes' => 0, 'streak' => 0,
    ];

    // Habits logged today / total habits
    $habTotal = (int)(fetchOne("SELECT COUNT(*) c FROM habits WHERE user_id=?", [$uid])['c'] ?? 0);
    $raw['habits_total'] = $habTotal;
    if ($habTotal > 0) {
        $habDone = (int)(fetchOne("SELECT COUNT(*) c FROM logs WHERE user_id=? AND date_completed=CURDATE()", [$uid])['c'] ?? 0);
        $raw['habits_done'] = $habDone;
        $parts['habits'] = [min(1, $habDone / $habTotal), 0.30];
    }
    // Todos: today's done / actionable (due today + overdue + undated incomplete)
    $tToday = fetchOne(
        "SELECT
            SUM(completed=1) done,
            COUNT(*) total
         FROM todos
         WHERE user_id=? AND deleted_at IS NULL
           AND (DATE(due_date)=CURDATE() OR (completed=0 AND due_date < CURDATE()) OR (completed=0 AND due_date IS NULL))",
        [$uid]);
    $tTotal = (int)($tToday['total'] ?? 0);
    $raw['todos_total'] = $tTotal;
    $raw['todos_done']  = (int)($tToday['done'] ?? 0);
    if ($tTotal > 0) $parts['todos'] = [min(1, (int)$tToday['done'] / $tTotal), 0.30];

    // Streak health (current overall habit streak vs 14-day target).
    // habitStreaks() honours deliberate rest days — see calculateStreaks().
    if (function_exists('habitStreaks')) {
        $cur = (int)(habitStreaks($uid)['current'] ?? 0);
        $raw['streak'] = $cur;
        if ($cur > 0) $parts['streak'] = [min(1, $cur / 14), 0.15];
    }
    // Focus sessions today vs 3 target
    if (tableExists('focus_sessions')) {
        $fRow = fetchOne("SELECT COUNT(*) c, COALESCE(SUM(duration_min),0) m FROM focus_sessions WHERE user_id=? AND completed=1 AND type='focus' AND DATE(started_at)=CURDATE()", [$uid]);
        $f = (int)($fRow['c'] ?? 0);
        $raw['focus_minutes'] = (int)($fRow['m'] ?? 0);
        if ($f > 0) $parts['focus'] = [min(1, $f / 3), 0.15];
    }
    // Goals: average progress
    $g = fetchOne("SELECT AVG(LEAST(1, progress/GREATEST(target_value,1))) a FROM goals WHERE user_id=?", [$uid]);
    if ($g && $g['a'] !== null) $parts['goals'] = [(float)$g['a'], 0.10];

    if (!$parts) {
        return ['score' => 0, 'band' => 'Needs Improvement', 'has_data' => false, 'raw' => $raw];
    }

    // Weights are renormalised over the dimensions that HAVE data, so a user
    // with no goals is not penalised for the goals weight.
    $wsum = array_sum(array_map(fn($p) => $p[1], $parts));
    $score = 0;
    foreach ($parts as $p) $score += $p[0] * ($p[1] / $wsum);
    $score = (int)round($score * 100);

    return [
        'score'    => $score,
        'band'     => scoreBand($score),
        'has_data' => true,
        'raw'      => $raw,
        'parts'    => array_keys($parts),
    ];
}

/**
 * Upsert today's row in daily_snapshots.
 *
 * Called on authenticated page loads (see maybeRecordSnapshot) rather than
 * from a cron job, because shared hosting has no scheduler. The row is
 * REWRITTEN through the day so it converges on the true end-of-day figure —
 * a snapshot taken at 08:00 would otherwise record a 0% day forever.
 *
 * Reuses trackieScore()'s raw counts instead of re-querying, so the stored
 * history can never disagree with the score the user was shown.
 */
function recordDailySnapshot(int $uid): void {
    if (!tableExists('daily_snapshots')) return;

    $sc  = trackieScore($uid);
    $raw = $sc['raw'] ?? [];

    // Nothing to record for a brand-new account with no habits/todos at all.
    // Writing a 0-score row would misrepresent "hasn't set anything up yet"
    // as "had a bad day", and that lie would then feed every trend and review.
    if (empty($sc['has_data'])) return;

    insert(
        "INSERT INTO daily_snapshots
            (user_id, snapshot_date, score, habits_done, habits_total,
             todos_done, todos_total, focus_minutes, streak)
         VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            score         = VALUES(score),
            habits_done   = VALUES(habits_done),
            habits_total  = VALUES(habits_total),
            todos_done    = VALUES(todos_done),
            todos_total   = VALUES(todos_total),
            focus_minutes = VALUES(focus_minutes),
            streak        = VALUES(streak)",
        [
            $uid,
            (int)($sc['score'] ?? 0),
            (int)($raw['habits_done']   ?? 0),
            (int)($raw['habits_total']  ?? 0),
            (int)($raw['todos_done']    ?? 0),
            (int)($raw['todos_total']   ?? 0),
            (int)($raw['focus_minutes'] ?? 0),
            (int)($raw['streak']        ?? 0),
        ]
    );
}

/**
 * Throttled wrapper for page loads. trackieScore() costs ~6 queries, which is
 * not something to pay on every single request, so this runs at most once per
 * SNAPSHOT_EVERY seconds per session. The UNIQUE key makes repeats harmless.
 *
 * Never allowed to break a page render: a snapshot is telemetry, and telemetry
 * must not be able to take down the app it measures.
 */
function maybeRecordSnapshot(int $uid, int $everySeconds = 600): void {
    if ($uid <= 0) return;
    $now  = time();
    $last = (int)($_SESSION['tk_snapshot_at'] ?? 0);
    $day  = $_SESSION['tk_snapshot_day'] ?? '';
    $today = date('Y-m-d');

    // Always write once on the first request of a new day, so a day the user
    // opened the app is never missing a row just because of the throttle.
    if ($day === $today && ($now - $last) < $everySeconds) return;

    try {
        recordDailySnapshot($uid);
        $_SESSION['tk_snapshot_at']  = $now;
        $_SESSION['tk_snapshot_day'] = $today;
    } catch (Throwable $e) {
        // Back off for the throttle window so a persistent failure doesn't
        // retry on every request.
        $_SESSION['tk_snapshot_at']  = $now;
        $_SESSION['tk_snapshot_day'] = $today;
    }
}

/**
 * Score history for trends. Returns rows oldest-first.
 * Days the user never opened Trackie are simply ABSENT — callers must not
 * treat a missing day as a zero (see the daily_snapshots migration note).
 */
function scoreHistory(int $uid, int $days = 30): array {
    if (!tableExists('daily_snapshots')) return [];
    return fetchAll(
        "SELECT snapshot_date, score, habits_done, habits_total,
                todos_done, todos_total, focus_minutes, streak, mood
           FROM daily_snapshots
          WHERE user_id = ? AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
          ORDER BY snapshot_date ASC",
        [$uid, $days]
    );
}

/**
 * This-week vs last-week average score.
 * Returns null deltas when either window has no data — an unmeasured week is
 * not a zero-score week.
 */
function scoreTrend(int $uid): array {
    if (!tableExists('daily_snapshots')) {
        return ['this' => null, 'prev' => null, 'delta' => null, 'days' => 0];
    }
    $row = fetchOne(
        "SELECT
            AVG(CASE WHEN snapshot_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)  THEN score END) cur,
            AVG(CASE WHEN snapshot_date <  DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                     AND  snapshot_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN score END) prev,
            COUNT(*) n
           FROM daily_snapshots
          WHERE user_id = ? AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)",
        [$uid]
    );
    $cur  = $row['cur']  !== null ? (int)round((float)$row['cur'])  : null;
    $prev = $row['prev'] !== null ? (int)round((float)$row['prev']) : null;
    return [
        'this'  => $cur,
        'prev'  => $prev,
        'delta' => ($cur !== null && $prev !== null) ? $cur - $prev : null,
        'days'  => (int)($row['n'] ?? 0),
    ];
}

function scoreBand(int $s): string {
    if ($s >= 90) return 'Excellent';
    if ($s >= 75) return 'Good';
    if ($s >= 50) return 'Average';
    return 'Needs Improvement';
}

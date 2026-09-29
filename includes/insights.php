<?php
/**
 * Progress read-models built on activity_log (includes/activity.php):
 *   progressOverview()  — totals, per-day, per-module, heatmap for a range
 *   weeklyReview()      — one week vs the week before, per module + key metrics
 *   crossInsights()     — rule-based observations, each with a minimum-data
 *                         threshold so nothing is claimed from too little data
 *   maybeNotifyWeeklyReview() — bell notification once per new week
 *
 * Every number comes from recorded activity. Nothing is estimated.
 */

require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/settings.php';

const WEEKDAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

function progressOverview(int $uid, int $days): array {
    $days = max(7, min(365, $days));
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' day'));
    $out = ['days' => $days, 'from' => $from, 'active_days' => 0, 'activities' => 0, 'xp' => 0,
            'per_day' => [], 'modules' => [], 'heatmap' => [], 'weekday' => array_fill(0, 7, 0)];
    if (!activityReady()) return $out;
    ensureActivityBackfill($uid);

    $byDay = array_column(fetchAll("SELECT occurred_on d, COUNT(*) n FROM activity_log WHERE user_id=? AND occurred_on>=? GROUP BY occurred_on", [$uid, $from]), 'n', 'd');
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} day"));
        $out['per_day'][] = ['date' => $d, 'count' => (int)($byDay[$d] ?? 0)];
    }
    $out['active_days'] = count($byDay);
    $out['activities']  = array_sum(array_map('intval', $byDay));
    $out['xp'] = (int)(fetchOne("SELECT COALESCE(SUM(xp),0) x FROM xp_events WHERE user_id=? AND created_at >= ?", [$uid, $from . ' 00:00:00'])['x'] ?? 0);

    foreach (fetchAll("SELECT module, COUNT(*) n, COUNT(DISTINCT occurred_on) days, MAX(occurred_on) last
                       FROM activity_log WHERE user_id=? AND occurred_on>=? GROUP BY module ORDER BY n DESC", [$uid, $from]) as $r) {
        $out['modules'][] = ['module' => $r['module'], 'label' => ACTIVITY_MODULES[$r['module']] ?? ucfirst($r['module']),
                             'count' => (int)$r['n'], 'days' => (int)$r['days'], 'last' => $r['last']];
    }
    // 26-week heatmap (always, independent of the range).
    $hmFrom = date('Y-m-d', strtotime('-181 day'));
    $out['heatmap'] = array_map('intval', array_column(fetchAll(
        "SELECT occurred_on d, COUNT(*) n FROM activity_log WHERE user_id=? AND occurred_on>=? GROUP BY occurred_on", [$uid, $hmFrom]), 'n', 'd'));
    foreach ($byDay as $d => $n) $out['weekday'][(int)date('w', strtotime($d))] += (int)$n;
    return $out;
}

/** Stats for the week starting $weekFrom (Y-m-d) vs the week before. */
function weeklyReview(int $uid, string $weekFrom): array {
    $weekTo  = date('Y-m-d', strtotime('+6 day', strtotime($weekFrom)));
    $prevFrom = date('Y-m-d', strtotime('-7 day', strtotime($weekFrom)));
    $prevTo   = date('Y-m-d', strtotime('-1 day', strtotime($weekFrom)));
    $mod = static function (string $a, string $b) use ($uid): array {
        return array_column(fetchAll("SELECT module, COUNT(*) n FROM activity_log WHERE user_id=? AND occurred_on BETWEEN ? AND ? GROUP BY module", [$uid, $a, $b]), 'n', 'module');
    };
    $cnt = static function (string $sql, array $p): int {
        try { return (int)(fetchOne($sql, $p)['n'] ?? 0); } catch (Throwable $e) { return 0; }
    };
    $days = static fn(string $a, string $b) => $cnt("SELECT COUNT(DISTINCT occurred_on) n FROM activity_log WHERE user_id=? AND occurred_on BETWEEN ? AND ?", [$uid, $a, $b]);
    $xp   = static fn(string $a, string $b) => $cnt("SELECT COALESCE(SUM(xp),0) n FROM xp_events WHERE user_id=? AND created_at BETWEEN ? AND ?", [$uid, "$a 00:00:00", "$b 23:59:59"]);

    $metric = static function (string $table, string $sql) use ($uid, $cnt, $weekFrom, $weekTo, $prevFrom, $prevTo): ?array {
        if (!tableExists($table)) return null;
        return ['now' => $cnt($sql, [$uid, $weekFrom, $weekTo]), 'prev' => $cnt($sql, [$uid, $prevFrom, $prevTo])];
    };
    $metrics = array_filter([
        'Todos completed'      => $metric('todos', "SELECT COUNT(*) n FROM todos WHERE user_id=? AND completed=1 AND deleted_at IS NULL AND DATE(completed_at) BETWEEN ? AND ?"),
        'Habit check-ins'      => $metric('logs', "SELECT COUNT(*) n FROM logs WHERE user_id=? AND date_completed BETWEEN ? AND ?"),
        'Focus minutes'        => $metric('focus_sessions', "SELECT COALESCE(SUM(duration_min),0) n FROM focus_sessions WHERE user_id=? AND completed=1 AND type='focus' AND DATE(started_at) BETWEEN ? AND ?"),
        'Workouts'             => $metric('workout_sessions', "SELECT COUNT(*) n FROM workout_sessions WHERE user_id=? AND ended_at IS NOT NULL AND session_date BETWEEN ? AND ?"),
        'Pages read'           => $metric('reading_sessions', "SELECT COALESCE(SUM(pages),0) n FROM reading_sessions WHERE user_id=? AND session_date BETWEEN ? AND ?"),
        'Words written'        => $metric('writing_log', "SELECT COALESCE(SUM(words_added),0) n FROM writing_log WHERE user_id=? AND log_date BETWEEN ? AND ?"),
        'Minutes meditated'    => $metric('meditation_sessions', "SELECT COALESCE(SUM(duration_min),0) n FROM meditation_sessions WHERE user_id=? AND session_date BETWEEN ? AND ?"),
        'Coding minutes'       => $metric('coding_sessions', "SELECT COALESCE(SUM(minutes),0) n FROM coding_sessions WHERE user_id=? AND session_date BETWEEN ? AND ?"),
    ], static fn($m) => $m !== null && ($m['now'] > 0 || $m['prev'] > 0));

    $now = $mod($weekFrom, $weekTo);
    $prev = $mod($prevFrom, $prevTo);
    $modules = [];
    foreach (array_unique(array_merge(array_keys($now), array_keys($prev))) as $m) {
        $modules[] = ['module' => $m, 'label' => ACTIVITY_MODULES[$m] ?? ucfirst($m), 'now' => (int)($now[$m] ?? 0), 'prev' => (int)($prev[$m] ?? 0)];
    }
    usort($modules, static fn($a, $b) => $b['now'] <=> $a['now'] ?: $b['prev'] <=> $a['prev']);

    return [
        'from' => $weekFrom, 'to' => $weekTo, 'prev_from' => $prevFrom, 'prev_to' => $prevTo,
        'active_days' => $days($weekFrom, $weekTo), 'prev_active_days' => $days($prevFrom, $prevTo),
        'xp' => $xp($weekFrom, $weekTo), 'prev_xp' => $xp($prevFrom, $prevTo),
        'modules' => $modules, 'metrics' => $metrics,
        'dropped' => array_values(array_filter($modules, static fn($m) => $m['prev'] > 0 && $m['now'] === 0)),
        'new'     => array_values(array_filter($modules, static fn($m) => $m['now'] > 0 && $m['prev'] === 0)),
    ];
}

/** Observations with thresholds; empty when there isn't enough data. */
function crossInsights(int $uid): array {
    if (!activityReady()) return [];
    ensureActivityBackfill($uid);
    $out = [];
    $span = fetchOne("SELECT MIN(occurred_on) a, COUNT(DISTINCT occurred_on) d FROM activity_log WHERE user_id=?", [$uid]);
    if (!$span || (int)$span['d'] < 7) return [];

    // Busiest weekday — needs 4+ weeks of history and a clear lead.
    if (strtotime($span['a']) <= strtotime('-28 day')) {
        $w = array_fill(0, 7, 0);
        foreach (fetchAll("SELECT occurred_on d, COUNT(*) n FROM activity_log WHERE user_id=? AND occurred_on >= DATE_SUB(CURDATE(), INTERVAL 83 DAY) GROUP BY occurred_on", [$uid]) as $r) $w[(int)date('w', strtotime($r['d']))] += (int)$r['n'];
        arsort($w); $top = array_key_first($w); $vals = array_values($w);
        if (array_sum($vals) >= 20 && $vals[0] >= 1.3 * max(1, $vals[1])) {
            $out[] = ['icon' => 'fa-calendar-day', 'text' => WEEKDAY_NAMES[$top] . ' is your most active day over the last 12 weeks.'];
        }
        $low = array_key_last($w);
        if (array_sum($vals) >= 20 && end($vals) === 0) $out[] = ['icon' => 'fa-moon', 'text' => WEEKDAY_NAMES[$low] . 's are your quietest day — nothing logged on them in 12 weeks.'];
    }
    // Logging time — only rows logged live (backfilled rows carry the backfill time).
    $hrs = fetchAll("SELECT HOUR(created_at) h, COUNT(*) n FROM activity_log WHERE user_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND DATE(created_at)=occurred_on GROUP BY h", [$uid]);
    $total = array_sum(array_column($hrs, 'n'));
    if ($total >= 20) {
        $bands = ['morning (5–12)' => 0, 'afternoon (12–17)' => 0, 'evening (17–22)' => 0, 'late night (22–5)' => 0];
        foreach ($hrs as $r) {
            $h = (int)$r['h'];
            $k = $h >= 5 && $h < 12 ? 'morning (5–12)' : ($h >= 12 && $h < 17 ? 'afternoon (12–17)' : ($h >= 17 && $h < 22 ? 'evening (17–22)' : 'late night (22–5)'));
            $bands[$k] += (int)$r['n'];
        }
        arsort($bands); $b = array_key_first($bands);
        if ($bands[$b] / $total >= 0.45) $out[] = ['icon' => 'fa-clock', 'text' => round($bands[$b] / $total * 100) . "% of what you log happens in the {$b}."];
    }
    // Streak at risk: a module with a 3+ day streak and nothing today, late in the day.
    if ((int)date('G') >= 18) {
        foreach (moduleStreaks($uid) as $s) {
            if ($s['current'] >= 3 && $s['last'] < date('Y-m-d')) { $out[] = ['icon' => 'fa-fire', 'text' => "Your {$s['current']}-day {$s['label']} streak ends tonight unless you log something."]; break; }
        }
    }
    // Consistency: share of days active in the last 30.
    $d30 = (int)fetchOne("SELECT COUNT(DISTINCT occurred_on) n FROM activity_log WHERE user_id=? AND occurred_on >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)", [$uid])['n'];
    if (strtotime($span['a']) <= strtotime('-29 day')) $out[] = ['icon' => 'fa-chart-line', 'text' => "You were active on {$d30} of the last 30 days."];
    return $out;
}

/** One bell notification per new week pointing at the Weekly Review. */
function maybeNotifyWeeklyReview(int $uid): void {
    if (!activityReady() || !function_exists('createNotification')) return;
    $ws = userSetting($uid, 'week_start');
    if ((int)date('w') !== $ws) return;                       // only on the first day of the user's week
    $had = fetchOne("SELECT id FROM activity_log WHERE user_id=? AND occurred_on BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND DATE_SUB(CURDATE(), INTERVAL 1 DAY) LIMIT 1", [$uid]);
    if (!$had) return;                                        // nothing to review
    createNotification($uid, 'review', '📊 Your weekly review is ready', 'See how last week went across Trackie.', APP_BASE . '/pages/review.php');
}

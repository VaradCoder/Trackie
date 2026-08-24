<?php
/**
 * Trackie — Cross-module intelligence (Phase 2 Week 2, v1).
 *
 * The per-page insights already shipped (renderInsight() on Habits, Todos,
 * Finance, Goals, Study Plan) each only see their own table. This is the
 * upgrade the Phase 2 blueprint called for: rules that read across MULTIPLE
 * modules to surface things a user couldn't get from any single page.
 *
 * Threshold/correlation rules only — no ML, matching the style already
 * proven on the per-page insights. Each rule requires real minimum data
 * before it fires (never a confident claim from 2 data points), and every
 * rule degrades to producing nothing rather than a misleading insight.
 *
 * crossModuleInsights($uid) returns the single strongest-scoring insight
 * (or null) — same one-insight-per-call-site contract as renderInsight(),
 * so it drops into any page the same way.
 */

/**
 * Rule 1 — Workout day -> task completion rate.
 * Compares the user's todo-completion rate on days they logged a workout
 * vs. days they didn't, over the last 30 days. Needs at least 3 workout
 * days AND 3 non-workout days with any todo activity to say anything —
 * otherwise the comparison is noise, not a pattern.
 */
function _intel_workoutTaskLink(int $uid): ?array {
    if (!tableExists('workout_logs')) return null;

    $rows = fetchAll(
        "SELECT DATE(t.completed_at) d, COUNT(*) done
         FROM todos t
         WHERE t.user_id=? AND t.completed=1 AND t.completed_at IS NOT NULL
           AND t.completed_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
         GROUP BY d",
        [$uid]
    );
    if (count($rows) < 6) return null; // not enough completed-task days to compare

    $workoutDays = array_column(
        fetchAll(
            "SELECT DISTINCT log_date FROM workout_logs
             WHERE user_id=? AND log_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
            [$uid]
        ),
        'log_date'
    );
    if (count($workoutDays) < 3) return null;

    $withWorkout = 0; $withWorkoutN = 0;
    $withoutWorkout = 0; $withoutWorkoutN = 0;
    foreach ($rows as $r) {
        if (in_array($r['d'], $workoutDays, true)) { $withWorkout += (int)$r['done']; $withWorkoutN++; }
        else                                        { $withoutWorkout += (int)$r['done']; $withoutWorkoutN++; }
    }
    if ($withWorkoutN < 3 || $withoutWorkoutN < 3) return null;

    $avgWith    = $withWorkout / $withWorkoutN;
    $avgWithout = $withoutWorkout / $withoutWorkoutN;
    if ($avgWithout <= 0) return null;

    $deltaPct = (int)round(($avgWith - $avgWithout) / $avgWithout * 100);
    if (abs($deltaPct) < 15) return null; // not a meaningful enough difference to claim

    $text = $deltaPct > 0
        ? "You complete {$deltaPct}% more tasks on days you work out."
        : "You complete " . abs($deltaPct) . "% fewer tasks on workout days — maybe schedule lighter task loads then.";
    return ['text' => $text, 'icon' => 'fa-dumbbell', 'strength' => min(90, abs($deltaPct))];
}

/**
 * Rule 2 — Habit consistently missed -> "just show up" nudge instead of guilt.
 * A habit with 0 logs in the last 3 days despite being active for 7+ days
 * doesn't need another "you failed" message — it needs the bar lowered so
 * the streak can restart.
 */
function _intel_habitRebuild(int $uid): ?array {
    $habit = fetchOne(
        "SELECT h.id, h.name, h.frequency,
                DATEDIFF(CURDATE(), h.created_at) age_days,
                (SELECT MAX(date_completed) FROM logs WHERE habit_id=h.id) last_log
         FROM habits h
         WHERE h.user_id=? AND h.frequency='daily'
         HAVING age_days >= 7
            AND (last_log IS NULL OR DATEDIFF(CURDATE(), last_log) >= 3)
         ORDER BY last_log ASC
         LIMIT 1",
        [$uid]
    );
    if (!$habit) return null;

    $missedDays = $habit['last_log'] ? (int)((strtotime(date('Y-m-d')) - strtotime($habit['last_log'])) / 86400) : (int)$habit['age_days'];
    return [
        'text' => "\"{$habit['name']}\" hasn't been logged in {$missedDays} days. Don't chase the old streak — just log it once today to restart.",
        'icon' => 'fa-heart-pulse',
        'strength' => min(70, 40 + $missedDays),
    ];
}

/**
 * Rule 3 — Most productive time-of-day, from completed_at timestamps.
 * "The app understands me" pattern that's genuinely hard to notice by hand.
 * Needs at least 8 completions to say anything about a specific hour band.
 */
function _intel_productiveWindow(int $uid): ?array {
    $rows = fetchAll(
        "SELECT HOUR(completed_at) hr, COUNT(*) c FROM todos
         WHERE user_id=? AND completed=1 AND completed_at IS NOT NULL
         GROUP BY hr",
        [$uid]
    );
    $total = array_sum(array_column($rows, 'c'));
    if ($total < 8) return null;

    // Bucket into 3-hour windows so "7pm" noise doesn't outrank a real "evening" pattern.
    $buckets = ['Early morning (5–9am)'=>0,'Morning (9am–12pm)'=>0,'Afternoon (12–5pm)'=>0,'Evening (5–9pm)'=>0,'Night (9pm–5am)'=>0];
    foreach ($rows as $r) {
        $h = (int)$r['hr']; $c = (int)$r['c'];
        if     ($h>=5 && $h<9)  $buckets['Early morning (5–9am)'] += $c;
        elseif ($h>=9 && $h<12) $buckets['Morning (9am–12pm)']    += $c;
        elseif ($h>=12 && $h<17)$buckets['Afternoon (12–5pm)']    += $c;
        elseif ($h>=17 && $h<21)$buckets['Evening (5–9pm)']       += $c;
        else                    $buckets['Night (9pm–5am)']       += $c;
    }
    arsort($buckets);
    $topLabel = array_key_first($buckets);
    $topCount = $buckets[$topLabel];
    $pct = (int)round($topCount / $total * 100);
    if ($pct < 35) return null; // no real peak — fairly spread out, nothing worth claiming

    return [
        'text' => "You complete {$pct}% of your tasks during {$topLabel}. That's your peak window.",
        'icon' => 'fa-clock',
        'strength' => $pct,
    ];
}

/**
 * Rule 4 — Finance overspend nudge, surfaced outside the Finance page itself
 * (the per-page version already exists in finance.php; this is the same
 * signal made visible on the Dashboard, which is the actual cross-module win
 * — the user sees the warning before they open Finance, not after).
 */
function _intel_overspendNudge(int $uid): ?array {
    if (!tableExists('transactions')) return null;
    $thisMonth = date('Y-m-01');
    $lastMonthStart = date('Y-m-01', strtotime('-1 month'));
    $lastMonthEnd   = date('Y-m-t', strtotime('-1 month'));

    $thisSpend = (float)fetchOne(
        "SELECT COALESCE(SUM(amount),0) s FROM transactions WHERE user_id=? AND type='expense' AND tx_date>=?",
        [$uid, $thisMonth]
    )['s'];
    $lastSpend = (float)fetchOne(
        "SELECT COALESCE(SUM(amount),0) s FROM transactions WHERE user_id=? AND type='expense' AND tx_date BETWEEN ? AND ?",
        [$uid, $lastMonthStart, $lastMonthEnd]
    )['s'];
    if ($lastSpend <= 0) return null;

    $daysElapsed  = (int)date('j');
    $daysInMonth  = (int)date('t');
    $paceExpected = $lastSpend * ($daysElapsed / $daysInMonth);
    if ($paceExpected <= 0 || $thisSpend < $paceExpected * 1.25) return null; // not meaningfully ahead of pace

    $pct = (int)round(($thisSpend - $paceExpected) / $paceExpected * 100);
    return [
        'text' => "You're spending {$pct}% faster than last month's pace. Consider postponing a non-essential purchase this week.",
        'icon' => 'fa-wallet',
        'strength' => min(80, 40 + $pct),
    ];
}

/**
 * Returns the single strongest-scoring cross-module insight for this user,
 * or null if no rule has enough data / a strong enough signal to say
 * anything (silence beats a weak or misleading claim).
 */
function crossModuleInsights(int $uid): ?array {
    $candidates = array_filter([
        _intel_workoutTaskLink($uid),
        _intel_habitRebuild($uid),
        _intel_productiveWindow($uid),
        _intel_overspendNudge($uid),
    ]);
    if (!$candidates) return null;

    usort($candidates, fn($a, $b) => $b['strength'] <=> $a['strength']);
    return $candidates[0];
}

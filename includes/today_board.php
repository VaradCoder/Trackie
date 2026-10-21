<?php
/**
 * The Today board's data — one list of everything on a day's plate — shared
 * by Today (which renders it) and Overview (which summarises it), so the two
 * can never disagree about what "done today" means.
 *
 * @return array{items: array, notDue: string[], routinesSkipped: string[], routineRestToday: bool}
 */
require_once __DIR__ . '/habit_schedule.php';
require_once __DIR__ . '/routines.php';
require_once __DIR__ . '/settings.php';

function todayBoard(int $uid, string $today): array {
    /* ════════════════════════════════════════════════════════════════
       One list of everything on today's plate. Each item:
         key, kind (todo|study|habit|routine|workout), id, title, done,
         section ('overdue'|'today'), sort (string), icon, color, meta[], link
       ════════════════════════════════════════════════════════════════ */
    $items = [];
    $priRank = ['high' => 0, 'medium' => 1, 'low' => 2];
    $recurLabel = ['daily' => 'Every day', 'weekly' => 'This week', 'monthly' => 'This month'];

    // ── Todos: due today, overdue (one-off), and recurring ones for this period ──
    foreach (fetchAll(
        "SELECT id, title, priority, completed, due_date, recurring FROM todos
          WHERE user_id=? AND deleted_at IS NULL AND parent_id IS NULL AND (
                due_date = ?
                -- overdue one-offs: still open, or finished today (they stay in Done until tomorrow)
             OR (recurring = 'none' AND due_date < ? AND (completed = 0 OR DATE(completed_at) = ?))
             OR (recurring <> 'none' AND (due_date IS NULL OR due_date <= ?)))
          ORDER BY due_date IS NULL, due_date LIMIT 100",
        [$uid, $today, $today, $today, $today]) as $t) {
        $overdue = $t['recurring'] === 'none' && $t['due_date'] && $t['due_date'] < $today;
        $meta = [];
        if ($overdue) {
            $days = (int)((strtotime($today) - strtotime($t['due_date'])) / 86400);
            $meta[] = ['html' => '<span class="td-due-late">' . ($days === 1 ? 'Yesterday' : $days . ' days late') . '</span>'];
        }
        if ($t['recurring'] !== 'none') $meta[] = ['icon' => 'fa-repeat', 'text' => $recurLabel[$t['recurring']] ?? 'Repeats'];
        if ($t['priority'] !== 'medium') $meta[] = ['html' => '<span class="td-pri ' . h($t['priority']) . '">' . ucfirst(h($t['priority'])) . '</span>'];
        $items[] = [
            'key' => 'todo-' . $t['id'], 'kind' => 'todo', 'id' => (int)$t['id'], 'title' => $t['title'],
            'done' => (bool)$t['completed'], 'section' => $overdue ? 'overdue' : 'today',
            'sort' => '3' . ($priRank[$t['priority']] ?? 1) . str_pad((string)$t['id'], 9, '0', STR_PAD_LEFT),
            'icon' => 'fa-check', 'label' => 'Todo', 'color' => $t['priority'] === 'high' ? '#ef4444' : 'var(--ok)', 'meta' => $meta,
        ];
    }

    // ── Study tasks due today ──
    if (tableExists('study_plan')) {
        foreach (fetchAll("SELECT id, title, priority, completed FROM study_plan WHERE user_id=? AND due_date=?", [$uid, $today]) as $t) {
            $items[] = [
                'key' => 'study-' . $t['id'], 'kind' => 'study', 'id' => (int)$t['id'], 'title' => $t['title'],
                'done' => (bool)$t['completed'], 'section' => 'today',
                'sort' => '3' . ($priRank[$t['priority']] ?? 1) . str_pad((string)$t['id'], 9, '0', STR_PAD_LEFT),
                'icon' => 'fa-graduation-cap', 'label' => 'Study', 'color' => '#8b5cf6', 'meta' => [],
            ];
        }
    }

    // ── Habits due today (schedule-aware); the rest are listed as "not due" ──
    $weekStart = (int)userSetting($uid, 'week_start');
    $hLogs = [];
    foreach (fetchAll("SELECT l.habit_id, l.date_completed d FROM logs l JOIN habits h ON h.id=l.habit_id
                       WHERE h.user_id=? AND l.date_completed >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)", [$uid]) as $r) {
        $hLogs[$r['habit_id']][] = $r['d'];
    }
    $notDue = [];
    foreach (fetchAll("SELECT id, name, color, frequency, schedule_days FROM habits WHERE user_id=? ORDER BY name", [$uid]) as $h) {
        $done = in_array($today, $hLogs[$h['id']] ?? [], true);
        if (!$done && !habitDueOn($h, $today, $hLogs[$h['id']] ?? [], $weekStart)) { $notDue[] = $h['name']; continue; }
        $items[] = [
            'key' => 'habit-' . $h['id'], 'kind' => 'habit', 'id' => (int)$h['id'], 'title' => $h['name'],
            'done' => $done, 'section' => 'today', 'sort' => '2' . strtolower($h['name']),
            'icon' => 'fa-heart', 'label' => 'Habit', 'color' => $h['color'] ?: '#ef4444',
            'meta' => [['text' => habitScheduleLabel($h)]],
        ];
    }

    // ── Routines (time-ordered; skipped / rest-day ones aren't owed today) ──
    $routineRestToday = (bool)routineRestDays($uid, $today, $today);
    $routinesSkipped  = [];
    foreach (routinesOnDay($uid, $today) as $r) {
        if ($r['state'] === 'off') continue;
        if ($r['state'] === 'skipped' || $r['state'] === 'rest') { $routinesSkipped[] = $r['title'] . ($r['state'] === 'skipped' ? ' (skipped today)' : ''); continue; }
        $items[] = [
            'key' => 'routine-' . $r['id'], 'kind' => 'routine', 'id' => (int)$r['id'], 'title' => $r['title'],
            'done' => $r['state'] === 'done', 'section' => 'today', 'sort' => '1' . substr($r['time_slot'], 0, 5),
            'icon' => 'fa-clock', 'label' => date('g:i A', strtotime($r['time_slot'])), 'color' => 'var(--info)', 'meta' => [],
        ];
    }

    // ── Today's planned workout (started on the Fitness page) ──
    if (tableExists('workout_plans')) {
        $plan = fetchOne("SELECT id, name FROM workout_plans WHERE user_id=? AND day_of_week=? ORDER BY id LIMIT 1", [$uid, date('l')]);
        if ($plan) {
            $trained = (bool)fetchOne("SELECT id FROM workout_logs WHERE user_id=? AND log_date=? LIMIT 1", [$uid, $today]);
            $items[] = [
                'key' => 'workout-' . $plan['id'], 'kind' => 'workout', 'id' => (int)$plan['id'], 'title' => $plan['name'],
                'done' => $trained, 'section' => 'today', 'sort' => '15',
                'icon' => 'fa-dumbbell', 'label' => 'Workout', 'color' => '#f97316', 'meta' => [],
                'link' => APP_BASE . '/pages/gym.php',
            ];
        }
    }

    usort($items, static fn($a, $b) => strcmp($a['sort'], $b['sort']));
    return compact('items', 'notDue', 'routinesSkipped', 'routineRestToday');
}

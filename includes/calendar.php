<?php
/**
 * Calendar engine — every dated thing in Trackie as one event shape:
 *   ['uid','source','id','title','date','time','done','actionable','priority','link']
 * Sources are skipped silently when their table doesn't exist.
 * Pages render events; they never query modules directly.
 */

const CALENDAR_SOURCES = [
    'todo'     => ['Todos',          'fa-check-square', '#ef4444'],
    'study'    => ['Study',          'fa-book-open',    '#8b5cf6'],
    'reminder' => ['Reminders',      'fa-bell',         '#f59e0b'],
    'goal'     => ['Goal deadlines', 'fa-bullseye',     '#22c55e'],
    'workout'  => ['Workouts',       'fa-dumbbell',     '#0ea5e9'],
    'shoot'    => ['Photo shoots',   'fa-camera',       '#64748b'],
    'bill'     => ['Renewals',       'fa-rotate',       '#eab308'],
    'google'   => ['Google Calendar','fa-calendar',     '#4285f4'],
];

function calendarEvents(int $uid, string $from, string $to): array {
    $ev = [];
    $add = static function (string $src, $id, string $title, string $date, ?string $time = null, array $x = []) use (&$ev) {
        $ev[] = array_merge(['uid' => "$src-$id", 'source' => $src, 'id' => (int)$id, 'title' => $title, 'date' => $date,
            'time' => $time, 'done' => false, 'actionable' => false, 'priority' => null, 'link' => null], $x);
    };
    $q = static function (string $table, string $sql, array $p) {
        try { return tableExists($table) ? fetchAll($sql, $p) : []; } catch (Throwable $e) { error_log("calendar {$table}: " . $e->getMessage()); return []; }
    };
    $base = APP_BASE . '/pages/';

    foreach ($q('todos', "SELECT id,title,due_date,priority,completed FROM todos WHERE user_id=? AND deleted_at IS NULL AND parent_id IS NULL AND due_date BETWEEN ? AND ?", [$uid, $from, $to]) as $r)
        $add('todo', $r['id'], $r['title'], $r['due_date'], null, ['done' => (bool)$r['completed'], 'actionable' => true, 'priority' => $r['priority'], 'link' => $base . 'todos.php']);
    foreach ($q('study_plan', "SELECT id,title,due_date,priority,completed FROM study_plan WHERE user_id=? AND due_date BETWEEN ? AND ?", [$uid, $from, $to]) as $r)
        $add('study', $r['id'], $r['title'], $r['due_date'], null, ['done' => (bool)$r['completed'], 'actionable' => true, 'priority' => $r['priority'], 'link' => $base . 'study_plan.php']);
    // Reminders: one-time ones on their date; recurring ones at their next fire time.
    foreach ($q('reminders', "SELECT id,title,type,remind_date,remind_time,next_fire_at FROM reminders WHERE user_id=? AND active=1
                              AND ((type='once' AND remind_date BETWEEN ? AND ?) OR (type<>'once' AND DATE(next_fire_at) BETWEEN ? AND ?))", [$uid, $from, $to, $from, $to]) as $r) {
        $d = $r['type'] === 'once' ? $r['remind_date'] : substr($r['next_fire_at'], 0, 10);
        $t = $r['type'] === 'once' ? substr($r['remind_time'], 0, 5) : substr($r['next_fire_at'], 11, 5);
        $add('reminder', $r['id'], $r['title'], $d, $t, ['link' => $base . 'reminders.php']);
    }
    foreach ($q('goals', "SELECT id,goal_name,deadline,progress,target_value FROM goals WHERE user_id=? AND deadline BETWEEN ? AND ?", [$uid, $from, $to]) as $r)
        $add('goal', $r['id'], 'Goal due: ' . $r['goal_name'], $r['deadline'], null, ['done' => $r['progress'] >= $r['target_value'], 'link' => $base . 'goals.php']);
    foreach ($q('workout_sessions', "SELECT id,plan_name,session_date,duration_sec FROM workout_sessions WHERE user_id=? AND ended_at IS NOT NULL AND session_date BETWEEN ? AND ?", [$uid, $from, $to]) as $r)
        $add('workout', $r['id'], ($r['plan_name'] ?: 'Workout') . ($r['duration_sec'] ? ' · ' . round($r['duration_sec'] / 60) . ' min' : ''), $r['session_date'], null, ['done' => true, 'link' => $base . 'gym.php']);
    foreach ($q('photos', "SELECT id,title,taken_date FROM photos WHERE user_id=? AND taken_date BETWEEN ? AND ?", [$uid, $from, $to]) as $r)
        $add('shoot', $r['id'], $r['title'], $r['taken_date'], null, ['link' => $base . 'photography.php?tab=shoots']);
    foreach ($q('subscriptions', "SELECT id,name,next_renewal FROM subscriptions WHERE user_id=? AND active=1 AND next_renewal BETWEEN ? AND ?", [$uid, $from, $to]) as $r)
        $add('bill', $r['id'], 'Renews: ' . $r['name'], $r['next_renewal'], null, ['link' => $base . 'finance.php']);
    // Google events synced by the Google provider (integration_data kind 'event').
    foreach ($q('integration_data', "SELECT id,payload,occurred_at FROM integration_data WHERE user_id=? AND provider='google' AND kind='event' AND DATE(occurred_at) BETWEEN ? AND ?", [$uid, $from, $to]) as $r) {
        $p = json_decode($r['payload'], true) ?: [];
        $add('google', $r['id'], $p['title'] ?? '(No title)', substr($r['occurred_at'], 0, 10), empty($p['allDay']) ? substr($r['occurred_at'], 11, 5) : null, ['link' => $p['link'] ?? null]);
    }

    usort($ev, static fn($a, $b) => [$a['date'], $a['time'] ?? '99', $a['done']] <=> [$b['date'], $b['time'] ?? '99', $b['done']]);
    return $ev;
}

<?php
/**
 * "Your space" on the dashboard — one tile per module the user actually has,
 * grouped the way the sidebar groups them (navTree(): Plan / Life / Hobbies,
 * hobby modules only when picked). Every tile is the module's own recorded
 * data: a headline number, a supporting line, its streak and the last 7 days
 * of activity. Section order follows the user's onboarding focus.
 *
 * Every query is guarded: a module whose table doesn't exist yet simply shows
 * its activity numbers, never an error.
 */
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/settings.php';

/** page key (navTree) => activity_log module */
const DASH_PAGE_MODULE = [
    'todos' => 'todos', 'habits' => 'habits', 'goals' => 'goals', 'routines' => 'routines',
    'study_plan' => 'study', 'focus' => 'focus', 'finance' => 'finance', 'gym' => 'fitness',
    'sports' => 'sports', 'meditation' => 'meditation', 'library' => 'reading', 'gaming' => 'gaming',
    'photography' => 'photography', 'projects' => 'coding', 'cooking' => 'cooking', 'writing' => 'writing',
    'art' => 'art', 'gardening' => 'gardening',
];

/** Tile colours for the non-hobby pages (hobby modules use allHobbiesMeta()). */
const DASH_PAGE_COLOR = [
    'todos' => '#6366f1', 'habits' => '#ec4899', 'goals' => '#f59e0b', 'routines' => '#0ea5e9',
    'calendar' => '#3b82f6', 'study_plan' => '#8b5cf6', 'focus' => '#14b8a6', 'reminders' => '#f97316',
    'finance' => '#22c55e', 'music' => '#1db954',
];

/** Section order from the onboarding focus (null / unknown → Plan, Life, Hobbies). */
function dashSectionOrder(?string $focus): array {
    return match ($focus) {
        'fitness', 'wellbeing', 'money' => ['life', 'plan', 'hobbies'],
        'creativity'                    => ['hobbies', 'plan', 'life'],
        default                         => ['plan', 'life', 'hobbies'],
    };
}

/**
 * @return array{sections: array<string, array{title:string, sub:string, manage:?array, tiles:array}>, order: string[], focus: ?string}
 */
function dashboardSections(int $uid, array $items, string $today): array {
    $t = static fn(string $x) => tableExists($x);
    $one = static function (string $sql, array $p) {
        try { return fetchOne($sql, $p) ?: []; } catch (Throwable $e) { error_log('dashboardSections: ' . $e->getMessage()); return []; }
    };
    $weekFrom = date('Y-m-d', strtotime('-6 day'));
    $monthFrom = date('Y-m-01');
    $fmtMin = static fn(int $m) => $m >= 60 ? floor($m / 60) . 'h ' . ($m % 60) . 'm' : $m . 'm';
    $ago = static function (?string $d) use ($today): string {
        if (!$d) return '';
        $n = (int)round((strtotime($today) - strtotime($d)) / 86400);
        return $n <= 0 ? 'today' : ($n === 1 ? 'yesterday' : "$n days ago");
    };

    // Activity per module: the last 7 days (sparkline) and streaks.
    $streaks = activityReady() ? moduleStreaks($uid) : [];   // first: it runs the activity backfill
    $spark = [];
    if (activityReady()) {
        foreach (fetchAll("SELECT module, occurred_on d, COUNT(*) n FROM activity_log WHERE user_id=? AND occurred_on >= ? GROUP BY module, occurred_on", [$uid, $weekFrom]) as $r) {
            $spark[$r['module']][$r['d']] = (int)$r['n'];
        }
    }
    $days = [];
    for ($i = 6; $i >= 0; $i--) $days[] = date('Y-m-d', strtotime("-$i day"));

    // Per-page headline: [value, unit, sub]. Only real numbers.
    $board = static fn(string $kind) => array_values(array_filter($items, static fn($i) => $i['kind'] === $kind));
    $tile = function (string $page) use ($uid, $t, $one, $today, $weekFrom, $monthFrom, $fmtMin, $ago, $board): array {
        switch ($page) {
            case 'todos':
                $r = $t('todos') ? $one("SELECT SUM(completed=0) open, SUM(completed=0 AND due_date < ?) late FROM todos WHERE user_id=? AND deleted_at IS NULL AND parent_id IS NULL", [$today, $uid]) : [];
                return [(int)($r['open'] ?? 0), 'open', (int)($r['late'] ?? 0) ? (int)$r['late'] . ' overdue' : 'Nothing overdue'];
            case 'habits':
                $h = $board('habit'); $d = count(array_filter($h, static fn($i) => $i['done']));
                return [$h ? "$d/" . count($h) : 0, 'today', $h ? ($d === count($h) ? 'All done today' : (count($h) - $d) . ' still to log') : 'None due today'];
            case 'goals':
                $r = $t('goals') ? $one("SELECT COUNT(*) n, MIN(CASE WHEN deadline >= ? THEN deadline END) next FROM goals WHERE user_id=? AND progress < target_value", [$today, $uid]) : [];
                return [(int)($r['n'] ?? 0), 'active', !empty($r['next']) ? 'Next deadline ' . date('j M', strtotime($r['next'])) : 'No deadlines set'];
            case 'routines':
                $rt = $board('routine'); $d = count(array_filter($rt, static fn($i) => $i['done']));
                return [$rt ? "$d/" . count($rt) : 0, 'today', $rt ? ($d === count($rt) ? 'Routine complete' : 'Next: ' . (array_values(array_filter($rt, static fn($i) => !$i['done']))[0]['title'] ?? '')) : 'Nothing scheduled today'];
            case 'calendar':
                require_once __DIR__ . '/calendar.php';
                $ev = array_values(array_filter(calendarEvents($uid, date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime('+7 day'))), static fn($e) => $e['source'] !== 'routine' && !$e['done']));
                return [count($ev), 'next 7 days', $ev ? 'Next: ' . $ev[0]['title'] . ' · ' . date('D', strtotime($ev[0]['date'])) : 'A clear week ahead'];
            case 'study_plan':
                if (!$t('study_plan')) return [0, 'open', ''];
                $r = $one("SELECT SUM(completed=0) open, MIN(CASE WHEN type='exam' AND completed=0 AND due_date >= ? THEN due_date END) exam FROM study_plan WHERE user_id=?", [$today, $uid]);
                $ex = !empty($r['exam']) ? (int)round((strtotime($r['exam']) - strtotime($today)) / 86400) : null;
                return [(int)($r['open'] ?? 0), 'open', $ex !== null ? ($ex === 0 ? 'Exam today' : "Exam in $ex day" . ($ex === 1 ? '' : 's')) : 'No exams coming up'];
            case 'focus':
                if (!function_exists('focusStats') || !$t('focus_sessions')) return [0, 'today', ''];
                $f = focusStats($uid);
                return [$fmtMin((int)$f['today']), 'today', $fmtMin((int)$f['week']) . ' this week'];
            case 'reminders':
                $r = $t('reminders') ? $one("SELECT COUNT(*) n, MIN(CASE WHEN next_fire_at >= NOW() THEN next_fire_at END) next FROM reminders WHERE user_id=? AND active=1", [$uid]) : [];
                return [(int)($r['n'] ?? 0), 'active', !empty($r['next']) ? 'Next ' . (substr($r['next'], 0, 10) === $today ? 'at ' . date('g:i A', strtotime($r['next'])) : date('D j M', strtotime($r['next']))) : 'None scheduled'];
            case 'finance':
                if (!$t('transactions')) return [0, '', ''];
                $r = $one("SELECT COALESCE(SUM(CASE WHEN type='expense' THEN amount END),0) spent FROM transactions WHERE user_id=? AND tx_date >= ?", [$uid, $monthFrom]);
                $b = $t('budgets') ? (float)($one("SELECT COALESCE(SUM(monthly_limit),0) b FROM budgets WHERE user_id=?", [$uid])['b'] ?? 0) : 0;
                return [money((float)($r['spent'] ?? 0), $uid), 'this month', $b > 0 ? round((float)$r['spent'] / $b * 100) . '% of ' . money($b, $uid) . ' budget' : 'Spent so far'];
            case 'gym':
                if (!$t('workout_logs') || !function_exists('workoutStreak')) return [0, '', ''];
                $w = workoutStreak($uid);
                $d = (int)($one("SELECT COUNT(DISTINCT log_date) n FROM workout_logs WHERE user_id=? AND log_date >= ?", [$uid, $weekFrom])['n'] ?? 0);
                return [$d, 'days trained', $w['current'] ? $w['current'] . '-' . $w['unit'] . ' streak' : 'No streak yet'];
            case 'sports':
                $r = $t('sports_sessions') ? $one("SELECT COUNT(*) n, COALESCE(SUM(duration_min),0) m FROM sports_sessions WHERE user_id=? AND session_date >= ?", [$uid, $weekFrom]) : [];
                return [(int)($r['n'] ?? 0), 'sessions', $fmtMin((int)($r['m'] ?? 0)) . ' this week'];
            case 'meditation':
                $r = $t('meditation_sessions') ? $one("SELECT COUNT(*) n, COALESCE(SUM(duration_min),0) m FROM meditation_sessions WHERE user_id=? AND session_date >= ?", [$uid, $weekFrom]) : [];
                return [$fmtMin((int)($r['m'] ?? 0)), 'this week', (int)($r['n'] ?? 0) . ' session' . ((int)($r['n'] ?? 0) === 1 ? '' : 's')];
            case 'library':
                if (!$t('books')) return [0, '', ''];
                $p = $t('reading_sessions') ? (int)($one("SELECT COALESCE(SUM(pages),0) p FROM reading_sessions WHERE user_id=? AND session_date >= ?", [$uid, $weekFrom])['p'] ?? 0) : 0;
                $b = $one("SELECT title FROM books WHERE user_id=? AND status='reading' ORDER BY id DESC LIMIT 1", [$uid]);
                return [$p, 'pages this week', !empty($b['title']) ? 'Reading ' . $b['title'] : 'No book on the go'];
            case 'projects':
                $m = $t('coding_sessions') ? (int)($one("SELECT COALESCE(SUM(minutes),0) m FROM coding_sessions WHERE user_id=? AND session_date >= ?", [$uid, $weekFrom])['m'] ?? 0) : 0;
                $a = $t('projects') ? (int)($one("SELECT COUNT(*) n FROM projects WHERE user_id=? AND status <> 'done'", [$uid])['n'] ?? 0) : 0;
                return [$fmtMin($m), 'coded this week', $a . ' active project' . ($a === 1 ? '' : 's')];
            case 'writing':
                $w = $t('writing_log') ? (int)($one("SELECT COALESCE(SUM(words_added),0) w FROM writing_log WHERE user_id=? AND log_date >= ?", [$uid, $weekFrom])['w'] ?? 0) : 0;
                return [number_format($w), 'words this week', ''];
            case 'gaming':
                $g = $t('games') ? $one("SELECT SUM(status='playing') p, SUM(status='completed' AND YEAR(completed_at)=YEAR(CURDATE())) c FROM games WHERE user_id=?", [$uid]) : [];
                return [(int)($g['p'] ?? 0), 'playing', (int)($g['c'] ?? 0) . ' finished this year'];
            case 'cooking':
                $c = $t('cook_logs') ? (int)($one("SELECT COUNT(*) n FROM cook_logs WHERE user_id=? AND cooked_on >= ?", [$uid, $weekFrom])['n'] ?? 0) : 0;
                $r = $t('recipes') ? (int)($one("SELECT COUNT(*) n FROM recipes WHERE user_id=?", [$uid])['n'] ?? 0) : 0;
                return [$c, 'cooked this week', $r . ' recipe' . ($r === 1 ? '' : 's') . ' saved'];
            case 'art':
                $r = $t('art_sessions') ? $one("SELECT COUNT(*) n, COALESCE(SUM(minutes),0) m FROM art_sessions WHERE user_id=? AND session_date >= ?", [$uid, $weekFrom]) : [];
                return [$fmtMin((int)($r['m'] ?? 0)), 'practised this week', (int)($r['n'] ?? 0) . ' session' . ((int)($r['n'] ?? 0) === 1 ? '' : 's')];
            case 'photography':
                $p = $t('photos') ? (int)($one("SELECT COUNT(*) n FROM photos WHERE user_id=? AND COALESCE(taken_date, DATE(created_at)) >= ?", [$uid, $monthFrom])['n'] ?? 0) : 0;
                return [$p, 'shoots this month', ''];
            case 'gardening':
                $due = $t('plants') ? (int)($one("SELECT COUNT(*) n FROM plants WHERE user_id=? AND status <> 'dead' AND (last_watered IS NULL OR DATE_ADD(last_watered, INTERVAL water_frequency_days DAY) <= CURDATE())", [$uid])['n'] ?? 0) : 0;
                $all = $t('plants') ? (int)($one("SELECT COUNT(*) n FROM plants WHERE user_id=?", [$uid])['n'] ?? 0) : 0;
                return [$due, 'need water', $all . ' plant' . ($all === 1 ? '' : 's')];
            case 'music':
                require_once __DIR__ . '/providers.php';
                $sp = provider('spotify');
                $on = $sp && $sp->isConfigured() && $sp->isConnected($uid);
                return [$on ? 'On' : 'Off', 'Spotify', $on ? 'Connected — focus playlists ready' : 'Connect to see what\'s playing'];
        }
        return ['', '', ''];
    };

    $groups = [];
    foreach (navTree() as $g) $groups[$g['key']] = $g['items'];
    $build = function (array $navItems) use ($tile, $spark, $streaks, $days, $ago): array {
        $out = [];
        foreach ($navItems as $it) {
            if (in_array($it['page'], ['today', 'hobbies'], true)) continue;   // Today is the bento above; the hub isn't a module
            $mod = DASH_PAGE_MODULE[$it['page']] ?? null;
            [$value, $unit, $sub] = $tile($it['page']);
            $meta = null;
            foreach (allHobbiesMeta() as $hm) if (($hm['module'] ?? '') === $it['href']) { $meta = $hm; break; }
            $out[] = [
                'page' => $it['page'], 'href' => $it['href'], 'label' => $it['label'], 'icon' => $it['icon'],
                'color' => $meta['color'] ?? DASH_PAGE_COLOR[$it['page']] ?? '#6366f1',
                'value' => $value, 'unit' => $unit, 'sub' => $sub,
                'streak' => $mod ? (int)($streaks[$mod]['current'] ?? 0) : 0,
                'last' => $mod && !empty($streaks[$mod]['last']) ? $ago($streaks[$mod]['last']) : '',
                'spark' => $mod ? array_map(static fn($d) => (int)($spark[$mod][$d] ?? 0), $days) : null,
            ];
        }
        return $out;
    };

    $focus = userPrefs($uid)['primary_focus'] ?? null;
    $plan = $build($groups['plan'] ?? []);
    if ($focus === 'study') {   // study first: Study Plan and Focus lead the Plan row
        usort($plan, static fn($a, $b) => (int)!in_array($a['page'], ['study_plan', 'focus'], true) <=> (int)!in_array($b['page'], ['study_plan', 'focus'], true));
    }
    $life = $build($groups['life'] ?? []);
    if ($focus === 'money') usort($life, static fn($a, $b) => (int)($a['page'] !== 'finance') <=> (int)($b['page'] !== 'finance'));
    if ($focus === 'fitness') usort($life, static fn($a, $b) => (int)($a['page'] !== 'gym') <=> (int)($b['page'] !== 'gym'));
    $hobbies = $build($groups['hobbies'] ?? []);
    $picked = (bool)array_filter($hobbies, static fn($x) => $x['page'] !== 'music');

    return [
        'focus' => $focus,
        'picked' => $picked,
        'order' => dashSectionOrder($focus),
        'sections' => [
            'plan'    => ['title' => 'Plan', 'sub' => 'What you decided to do', 'manage' => null, 'tiles' => $plan],
            'life'    => ['title' => 'Life', 'sub' => 'Money and body', 'manage' => ['Choose modules', 'hobbies.php'], 'tiles' => $life],
            'hobbies' => ['title' => 'Hobbies', 'sub' => 'The things you love doing', 'manage' => ['Pick hobbies', 'hobbies.php'], 'tiles' => $hobbies],
        ],
    ];
}

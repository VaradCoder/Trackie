<?php
/**
 * Trackie v1.0 — Utility functions
 */

// ── Assets ────────────────────────────────────────────────────

/**
 * Cache-busted URL for a CSS/JS asset, preferring the minified build.
 *
 * Uses assets/x.min.css when it exists AND is at least as new as the source,
 * so an out-of-date build is ignored rather than shipped. Forgetting to run
 * `php scripts/build_assets.php` therefore degrades to the readable original
 * instead of serving stale code.
 *
 * @param string $rel Source path relative to the app root, e.g. 'assets/js/app.js'
 */
function assetUrl(string $rel): string {
    $abs = ROOT_PATH . '/' . ltrim($rel, '/');
    $ext = pathinfo($rel, PATHINFO_EXTENSION);
    $min = preg_replace('/\.' . preg_quote($ext, '/') . '$/', ".min.$ext", $rel);
    $minAbs = ROOT_PATH . '/' . ltrim($min, '/');

    $srcTime = @filemtime($abs) ?: 0;
    if (is_file($minAbs) && (@filemtime($minAbs) ?: 0) >= $srcTime) {
        return APP_BASE . '/' . ltrim($min, '/') . '?v=' . (@filemtime($minAbs) ?: time());
    }
    return APP_BASE . '/' . ltrim($rel, '/') . '?v=' . ($srcTime ?: time());
}

// ── Input ─────────────────────────────────────────────────────

/** Trim only. Never htmlspecialchars before DB — do that at output. */
function sanitizeInput(string $input): string {
    return trim($input);
}

function validateEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function hashPassword(string $pw): string {
    return password_hash($pw, PASSWORD_DEFAULT);
}

/**
 * Score a password 0-4 and explain what would improve it.
 *
 * Lives here so the API and the client meter share ONE definition of "strong"
 * — a meter that disagrees with the server is worse than no meter, because it
 * green-lights a password the server then rejects.
 *
 * @return array{score:int,label:string,issues:string[],ok:bool}
 */
function passwordStrength(string $pw): array {
    $issues = [];
    $len = strlen($pw);

    if ($len < 8)                      $issues[] = 'at least 8 characters';
    if (!preg_match('/[a-z]/', $pw))   $issues[] = 'a lowercase letter';
    if (!preg_match('/[A-Z]/', $pw))   $issues[] = 'an uppercase letter';
    if (!preg_match('/\d/', $pw))      $issues[] = 'a number';

    // Reject the passwords that actually show up in credential-stuffing lists.
    // Substring match, so "password123" and "Qwerty2024" are caught too.
    $common = ['password', 'qwerty', '111111', '123456', 'letmein', 'welcome',
               'admin', 'iloveyou', 'abc123', 'trackie', 'monkey', 'dragon'];
    $lower = strtolower($pw);
    foreach ($common as $c) {
        if (strpos($lower, $c) !== false) {
            $issues[] = 'something less guessable (avoid common words)';
            break;
        }
    }

    // Score for the meter: length milestones + character variety.
    $score = 0;
    if ($len >= 8)  $score++;
    if ($len >= 12) $score++;
    if (preg_match('/[a-z]/', $pw) && preg_match('/[A-Z]/', $pw)) $score++;
    if (preg_match('/\d/', $pw) && preg_match('/[^A-Za-z0-9]/', $pw)) $score++;
    if ($issues) $score = min($score, 2);   // never show "strong" for a rejected password

    $labels = ['Very weak', 'Weak', 'Fair', 'Good', 'Strong'];

    return [
        'score'  => $score,
        'label'  => $labels[$score] ?? 'Weak',
        'issues' => $issues,
        'ok'     => empty($issues),
    ];
}

function verifyPassword(string $pw, string $hash): bool {
    return password_verify($pw, $hash);
}

// ── CSRF ──────────────────────────────────────────────────────

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function verify_csrf(): void {
    $token = $_POST['csrf_token']
          ?? $_SERVER['HTTP_X_CSRF_TOKEN']
          ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        json_out(['error' => 'CSRF token mismatch']);
    }
}

// ── Auth ──────────────────────────────────────────────────────

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function currentUserId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function currentUserName(): string {
    return $_SESSION['user_name'] ?? 'User';
}

// ── Redirect ──────────────────────────────────────────────────

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

// ── Flash messages ────────────────────────────────────────────

function flash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $msg];
}

function getFlash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

// ── JSON responses ────────────────────────────────────────────

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function isAjax(): bool {
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || (($_SERVER['CONTENT_TYPE'] ?? '') === 'application/json')
        || isset($_POST['_ajax']);
}

// ── Date helpers ──────────────────────────────────────────────

function formatDate(string $date, string $fmt = 'M j, Y'): string {
    $ts = strtotime($date);
    return $ts ? date($fmt, $ts) : '';
}

function timeAgo(string $timestamp): string {
    $diff = time() - strtotime($timestamp);
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}

// ── Streak calculation ────────────────────────────────────────

/**
 * Given an array of DATE strings (any order), return [current, best] streaks.
 * A day counts if at least one habit was logged.
 */
/**
 * Downscale an uploaded image in place, if the server can.
 *
 * Second line of defence behind the browser-side resize in profile.php: a
 * direct API caller never runs that JS, and without this a 5 MB original would
 * be stored and then served as a 44px avatar on every page view.
 *
 * Returns false (leaving the file untouched) when GD is unavailable — it is a
 * bundled extension but not guaranteed on shared hosting, and a missing
 * extension must degrade to "keep the original", never to a failed upload.
 */
function shrinkImageFile(string $path, int $maxPx = 512, int $quality = 82): bool {
    if (!extension_loaded('gd') || !is_file($path)) return false;

    $info = @getimagesize($path);
    if (!$info) return false;
    [$w, $h] = $info;
    if ($w < 1 || $h < 1) return false;

    $src = match ($info['mime']) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png'  => @imagecreatefrompng($path),
        'image/gif'  => @imagecreatefromgif($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
        default      => null,
    };
    if (!$src) return false;

    $scale = min(1, $maxPx / max($w, $h));
    // Already small AND already modest in size — re-encoding would only lose
    // quality for no meaningful saving.
    if ($scale >= 1 && filesize($path) < 300 * 1024) { imagedestroy($src); return false; }

    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    // Flatten onto white: JPEG has no alpha, and avatars always sit on a solid
    // surface. Without this, transparent PNGs come out with a black background.
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    $ok = @imagejpeg($dst, $path, $quality);
    imagedestroy($src);
    imagedestroy($dst);
    return (bool)$ok;
}

// ── Onboarding preferences ────────────────────────────────────

/**
 * The personalisation answers captured during onboarding, cached per request.
 *
 * Every value may be NULL — onboarding lets the user skip each step, and users
 * who registered before these questions existed have none of them. Consumers
 * MUST treat NULL as "not set" and fall back to the neutral default; never
 * invent a value, or the app will act on a preference the user never gave.
 */
function userPrefs(int $uid, bool $fresh = false): array {
    static $cache = [];
    // $fresh bypasses the per-request cache. Required after a write in the same
    // request, otherwise the caller reads back the pre-update values.
    if (!$fresh && isset($cache[$uid])) return $cache[$uid];

    $blank = [
        'primary_focus' => null, 'experience_level' => null,
        'daily_reminder_time' => null, 'sleep_goal_hours' => null,
        'water_goal_ml' => null,
    ];
    try {
        $row = fetchOne(
            "SELECT primary_focus, experience_level, daily_reminder_time,
                    sleep_goal_hours, water_goal_ml
               FROM users WHERE id = ?", [$uid]
        );
    } catch (Throwable $e) {
        // Pre-migration database — degrade to "nothing set" rather than fatal.
        $row = null;
    }
    return $cache[$uid] = array_merge($blank, $row ?: []);
}

/** Human label for a primary_focus key, or null when unset/unknown. */
function focusLabel(?string $key): ?string {
    $map = [
        'discipline' => 'Building discipline',
        'fitness'    => 'Getting fitter',
        'wellbeing'  => 'Feeling better',
        'study'      => 'Studying better',
        'money'      => 'Spending smarter',
        'creativity' => 'Making more',
    ];
    return $key !== null ? ($map[$key] ?? null) : null;
}

/**
 * Dashboard component order per column, driven by primary_focus.
 *
 * A NULL or unknown focus returns the ORIGINAL order exactly, so a user who
 * skipped the question sees no change at all. Only the order WITHIN a column
 * changes — the CSS grid columns themselves are untouched, which keeps this a
 * safe reordering rather than a layout rewrite.
 *
 * Honest limitation: the dashboard has no Finance or Writing widget, so the
 * 'money' and 'creativity' focuses currently only affect which existing card
 * leads. Those get a dedicated widget when their modules earn one.
 */
function dashboardLayout(?string $focus): array {
    $default = [
        1 => ['dash_welcome', 'dash_calendar', 'dash_quickstats'],
        2 => ['dash_weather', 'dash_habits', 'dash_challenge'],
        3 => ['dash_todos'],
        4 => ['dash_spotify', 'dash_integrations'],
    ];

    // Habits lead for every focus that is fundamentally about consistency;
    // weather is decorative and should never outrank the day's actual work.
    $habitsFirst = ['dash_habits', 'dash_challenge', 'dash_weather'];

    switch ($focus) {
        case 'discipline':
        case 'fitness':
            return [1 => ['dash_quickstats', 'dash_welcome', 'dash_calendar'],
                    2 => $habitsFirst] + $default;
        case 'wellbeing':
            return [2 => $habitsFirst] + $default;
        case 'study':
            return [1 => ['dash_quickstats', 'dash_calendar', 'dash_welcome'],
                    2 => ['dash_habits', 'dash_weather', 'dash_challenge']] + $default;
        case 'money':
        case 'creativity':
            return [2 => ['dash_challenge', 'dash_habits', 'dash_weather']] + $default;
        default:
            return $default;   // not set / unknown → unchanged
    }
}

// ── Streak data helpers ───────────────────────────────────────
// These live here, not in gamification.php, because pages that render a
// streak (sidebar, dashboard, analytics) do not all load gamification.

/** Distinct dates the user logged ANY habit — used for the overall streak. */
function userLogDates(int $uid): array {
    if (!tableExists('logs')) return [];
    return array_column(
        fetchAll("SELECT DISTINCT date_completed FROM logs WHERE user_id=? ORDER BY date_completed", [$uid]),
        'date_completed'
    );
}

/**
 * Distinct dates the user marked a habit as a deliberate rest day ('skip').
 * 'fail' is NOT included — an admitted miss is a miss and should break the
 * streak; only an intentional rest day earns protection.
 */
function userNeutralDates(int $uid): array {
    if (!tableExists('habit_status_log')) return [];
    return array_column(
        fetchAll("SELECT DISTINCT log_date FROM habit_status_log
                   WHERE user_id=? AND status='skip' ORDER BY log_date", [$uid]),
        'log_date'
    );
}

/**
 * THE canonical habit streak for a user. Every habit-streak call site should
 * use this rather than calling calculateStreaks() with its own query — there
 * were 8 such call sites and they would otherwise disagree about whether rest
 * days count.
 */
function habitStreaks(int $uid): array {
    return calculateStreaks(userLogDates($uid), userNeutralDates($uid));
}

/**
 * True when every day strictly between $from and $from+$gap is a rest day.
 * Used to decide whether a gap in completions was deliberate.
 */
function allNeutralBetween(string $from, int $gap, array $neutral): bool {
    for ($d = 1; $d < $gap; $d++) {
        if (!isset($neutral[date('Y-m-d', strtotime("$from +$d day"))])) return false;
    }
    return true;
}

/** Is there any completion strictly earlier than $date? */
function anyCompletionBefore(string $date, array $set): bool {
    foreach ($set as $d => $_) {
        if ($d < $date) return true;
    }
    return false;
}

/**
 * @param string[] $dates        Y-m-d dates the user COMPLETED something.
 * @param string[] $neutralDates Y-m-d dates the user deliberately marked as a
 *        rest day ("skip"). These BRIDGE a gap without extending the streak:
 *        they neither break it nor count toward it.
 *
 * Rest days used to be cosmetic. `habit_status_log` recorded skip/fail, but
 * this function only ever saw completion dates, so a user who explicitly said
 * "rest day" still lost their streak — the UI offered mercy the logic did not
 * grant. Defaulting to an empty array keeps every existing caller (workout
 * streaks, meditation) behaving exactly as before.
 */
function calculateStreaks(array $dates, array $neutralDates = []): array {
    if (empty($dates)) return ['current' => 0, 'best' => 0];

    $set = array_flip(array_unique($dates));
    ksort($set);
    $sorted = array_keys($set);   // ascending dates

    // A skip only counts if nothing was completed that day — a day with a real
    // completion is a completion, whatever else was logged against it.
    $neutral = array_flip(array_diff(array_unique($neutralDates), $sorted));

    // Best streak (scan ascending).
    //
    // This compared `$diff === 1.0`, but PHP's `/` returns an INT when both
    // operands are ints and the division is exact — 86400/86400 is int(1), and
    // int(1) === 1.0 is false. The branch was therefore unreachable and "best"
    // was permanently 1 for every user, which also meant the streak_7 and
    // consistency achievements could never unlock.
    //
    // Comparing calendar days (rather than seconds) also fixes a second, subtler
    // break: across a DST transition two adjacent midnights are 82800 or 90000
    // seconds apart, so a seconds-based check — including a naive (int) cast,
    // which would floor 0.958 to 0 — silently ended the streak twice a year.
    $best = $cur = 1;
    for ($i = 1; $i < count($sorted); $i++) {
        $prev = new DateTimeImmutable($sorted[$i - 1] . ' 00:00:00');
        $curr = new DateTimeImmutable($sorted[$i]     . ' 00:00:00');
        $gap  = (int)$prev->diff($curr)->days;

        // Contiguous, or separated only by days the user marked as rest.
        if ($gap === 1 || ($gap > 1 && allNeutralBetween($sorted[$i - 1], $gap, $neutral))) {
            $cur++;
            $best = max($best, $cur);
        } else {
            $cur = 1;
        }
    }

    // Current streak (walk backwards from today)
    $current = 0;
    $check   = date('Y-m-d');
    // Allow streak to persist if today not yet logged (don't break at midnight)
    if (!isset($set[$check])) {
        $check = date('Y-m-d', strtotime('-1 day'));
    }
    while (true) {
        if (isset($set[$check])) {
            $current++;                      // a real completion extends it
        } elseif (isset($neutral[$check])) {
            // Rest day: step over it without incrementing. Guard against a
            // trailing run of skips with no completion behind them, which
            // would otherwise walk backwards forever over empty history.
            if ($current === 0 && !anyCompletionBefore($check, $set)) break;
        } else {
            break;
        }
        $check = date('Y-m-d', strtotime($check . ' -1 day'));
    }

    // Invariant: the best streak can never be shorter than the current one.
    // Had this been here, the bug above would have been caught immediately.
    return ['current' => $current, 'best' => max($best, $current)];
}

// ── File upload validation ────────────────────────────────────

/**
 * Returns [] on success, or array of error strings.
 * Uses finfo for MIME validation — extension alone is not trusted.
 */
function validateUpload(array $file, int $maxBytes = 5_242_880): array {
    $errors = [];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Upload failed (code ' . $file['error'] . ').';
        return $errors;
    }
    if ($file['size'] > $maxBytes) {
        $errors[] = 'File exceeds ' . round($maxBytes / 1048576, 1) . ' MB limit.';
    }

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mime     = $finfo->file($file['tmp_name']);
    $allowed  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime, $allowed, true)) {
        $errors[] = 'Only JPEG, PNG, GIF and WebP images are allowed.';
    }
    return $errors;
}

function mimeToExt(string $mime): string {
    return match($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        default      => 'bin',
    };
}

// ── Generate random token ─────────────────────────────────────

function generateToken(int $bytes = 32): string {
    return bin2hex(random_bytes($bytes));
}

// ── h() shorthand for output escaping ────────────────────────

/**
 * Reads a config value set in config/env.php. Prefers a defined constant
 * (works everywhere, including hosts like InfinityFree where putenv() is
 * disabled) and falls back to getenv() for back-compat.
 */
function env(string $key, string $default = ''): string {
    if (defined($key)) return (string)constant($key);
    $v = getenv($key);
    return $v !== false ? $v : $default;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Single source of truth for every hobby Trackie knows about: its icon,
 * accent color, and — if a dedicated module has been built — the page it
 * links to. Used by Profile (picker), the Habits suggestions, and the
 * Hobbies hub page, so all three always agree on the hobby list.
 */
/**
 * Hobby registry. `navGroup` decides which sidebar section the module lands
 * in — 'health' for body/mind training, 'lifestyle' for creative and leisure
 * pursuits. Consumers that don't care about grouping simply ignore the key.
 */
function allHobbiesMeta(): array {
    return [
        'Fitness'     => ['icon' => 'fa-dumbbell',     'color' => '#ef4444', 'module' => 'gym.php',      'moduleLabel' => 'Fitness',  'navGroup' => 'health',    'desc' => 'Workout plans, set-by-set logging, PRs, progress charts'],
        'Coding'      => ['icon' => 'fa-code',         'color' => '#3b82f6', 'module' => 'projects.php', 'moduleLabel' => 'Projects', 'navGroup' => 'lifestyle', 'desc' => 'Projects, tasks, GitHub repo links'],
        'Reading'     => ['icon' => 'fa-book',         'color' => '#8b5cf6', 'module' => 'library.php',  'moduleLabel' => 'Library',  'navGroup' => 'lifestyle', 'desc' => 'Your shelves — want to read, reading, finished'],
        'Gaming'      => ['icon' => 'fa-gamepad',      'color' => '#22c55e', 'module' => 'gaming.php',      'moduleLabel' => 'Gaming',      'navGroup' => 'lifestyle', 'desc' => 'Backlog, playtime, and achievements'],
        'Cooking'     => ['icon' => 'fa-utensils',     'color' => '#f97316', 'module' => 'cooking.php',     'moduleLabel' => 'Cooking',     'navGroup' => 'lifestyle', 'desc' => 'Recipes tried and meal planning'],
        'Art'         => ['icon' => 'fa-palette',      'color' => '#ec4899', 'module' => 'art.php',         'moduleLabel' => 'Art',         'navGroup' => 'lifestyle', 'desc' => 'A gallery of sketches and pieces'],
        'Sports'      => ['icon' => 'fa-futbol',       'color' => '#0ea5e9', 'module' => 'sports.php',      'moduleLabel' => 'Sports',      'navGroup' => 'health',    'desc' => 'Matches, training logs, personal bests'],
        'Writing'     => ['icon' => 'fa-pen-nib',      'color' => '#14b8a6', 'module' => 'writing.php',     'moduleLabel' => 'Writing',     'navGroup' => 'lifestyle', 'desc' => 'Drafts, word-count streaks, ideas'],
        'Meditation'  => ['icon' => 'fa-spa',          'color' => '#a855f7', 'module' => 'meditation.php',  'moduleLabel' => 'Meditation',  'navGroup' => 'health',    'desc' => 'Session length and streaks'],
        'Photography' => ['icon' => 'fa-camera',       'color' => '#64748b', 'module' => 'photography.php', 'moduleLabel' => 'Photography', 'navGroup' => 'lifestyle', 'desc' => 'A daily photo log and portfolio'],
        'Gardening'   => ['icon' => 'fa-seedling',     'color' => '#16a34a', 'module' => 'gardening.php',   'moduleLabel' => 'Gardening',   'navGroup' => 'lifestyle', 'desc' => 'Plant care schedule and harvest log'],
    ];
}

/**
 * ─────────────────────────────────────────────────────────────────────────
 * navTree() — THE single source of truth for application navigation.
 *
 * Before this existed, nav was duplicated across FOUR places that silently
 * drifted apart: includes/sidebar.php, the hardcoded bottom nav in
 * includes/header.php, manifest.json's PWA shortcuts, and the command
 * palette's static COMMANDS array in app.js. Renaming a page fixed one and
 * broke three. Every consumer now reads from here.
 *
 * Shape — a list of groups, each:
 *   [
 *     'key'     => 'productivity',   // stable id, used for collapse state
 *     'heading' => 'Productivity',   // NULL renders no heading (replaces the
 *                                    //   old ''/' ' whitespace-key hack, which
 *                                    //   capped the design at two unlabelled
 *                                    //   groups because PHP keys must differ)
 *     'items'   => [ ['page','href','icon','label', 'primary'?] ]
 *   ]
 *
 * `primary => true` marks the handful of destinations that also appear in the
 * mobile bottom bar, so desktop and mobile can never disagree about them.
 *
 * Result is memoised per request: the sidebar, bottom nav and palette all call
 * this on the same page render, and only the first call hits the database.
 * ───────────────────────────────────────────────────────────────────────── */
function navTree(): array {
    static $cached = null;
    if ($cached !== null) return $cached;

    $tree = [];

    $tree[] = ['key' => 'home', 'heading' => null, 'items' => [
        ['page' => 'dashboard', 'href' => 'dashboard.php', 'icon' => 'fa-gauge', 'label' => 'Dashboard', 'primary' => true, 'short' => 'Home'],
    ]];

    // "Plan" — everything about deciding/tracking what to do (was "Productivity").
    $tree[] = ['key' => 'plan', 'heading' => 'Plan', 'items' => [
        ['page' => 'today',      'href' => 'today.php',      'icon' => 'fa-sun',          'label' => 'Today',      'primary' => true],
        ['page' => 'todos',      'href' => 'todos.php',      'icon' => 'fa-check-square', 'label' => 'Todos',      'primary' => true, 'short' => 'Tasks'],
        ['page' => 'habits',     'href' => 'habits.php',     'icon' => 'fa-heart',        'label' => 'Habits'],
        ['page' => 'goals',      'href' => 'goals.php',      'icon' => 'fa-bullseye',     'label' => 'Goals'],
        ['page' => 'routines',   'href' => 'routines.php',   'icon' => 'fa-clock',        'label' => 'Routines'],
        ['page' => 'calendar',   'href' => 'calendar.php',   'icon' => 'fa-calendar',     'label' => 'Calendar',   'primary' => true],
        ['page' => 'study_plan', 'href' => 'study_plan.php', 'icon' => 'fa-book-open',    'label' => 'Study Plan'],
        ['page' => 'focus',      'href' => 'focus.php',      'icon' => 'fa-stopwatch',    'label' => 'Focus'],
        ['page' => 'reminders',  'href' => 'reminders.php',  'icon' => 'fa-bell',         'label' => 'Reminders'],
    ]];

    // "Life" — the practical/wellbeing side (was separate Money + Health
    // groups). Finance always shows; health-tagged hobby modules join it
    // below once picked hobbies are known.
    $life = [
        ['page' => 'finance', 'href' => 'finance.php', 'icon' => 'fa-wallet', 'label' => 'Finance'],
    ];

    // ── Hobby modules, split by the registry's navGroup ──────────────────
    // Only modules the user actually picked are shown, but the hobby hub is
    // ALWAYS present: it used to sit inside this conditional, which meant a
    // user with zero hobbies had no route to the page where you pick them.
    $picked = [];
    if (function_exists('isLoggedIn') && isLoggedIn()) {
        // navTree() renders the sidebar on EVERY page, so an exception here
        // takes down the whole app rather than one feature. Guarded so a
        // pre-migration database (no users.hobbies column) degrades to
        // "no hobby modules" instead of a fatal on every request.
        try {
            $raw = fetchOne("SELECT hobbies FROM users WHERE id=?", [currentUserId()])['hobbies'] ?? '';
            $picked = array_filter(array_map('trim', explode(',', (string)$raw)));
        } catch (Throwable $e) {
            $picked = [];
        }
    }

    $hobbies = [];
    foreach (allHobbiesMeta() as $hobby => $meta) {
        if (!$meta['module'] || !in_array($hobby, $picked, true)) continue;
        $entry = [
            'page'  => basename($meta['module'], '.php'),
            'href'  => $meta['module'],
            'icon'  => $meta['icon'],
            'label' => $meta['moduleLabel'],
        ];
        // Health/fitness-flavoured modules (e.g. Gym) read as "Life", not "Hobbies".
        if (($meta['navGroup'] ?? 'lifestyle') === 'health') $life[] = $entry;
        else                                                 $hobbies[] = $entry;
    }

    $tree[] = ['key' => 'life', 'heading' => 'Life', 'items' => $life];

    // Music is a permanent integration, not a hobby — always in Hobbies.
    $hobbies[] = ['page' => 'music',   'href' => 'music.php',   'icon' => 'fa-music', 'label' => 'Music'];
    $hobbies[] = ['page' => 'hobbies', 'href' => 'hobbies.php', 'icon' => 'fa-star',  'label' => 'All Hobbies'];
    $tree[] = ['key' => 'hobbies', 'heading' => 'Hobbies', 'items' => $hobbies];

    // "Progress" — how you're doing overall (was "Insights").
    $tree[] = ['key' => 'progress', 'heading' => 'Progress', 'items' => [
        ['page' => 'analytics', 'href' => 'analytics.php', 'icon' => 'fa-chart-bar', 'label' => 'Analytics'],
        ['page' => 'progress',  'href' => 'progress.php',  'icon' => 'fa-trophy',    'label' => 'Progress'],
        ['page' => 'review',    'href' => 'review.php',    'icon' => 'fa-calendar-week', 'label' => 'Weekly Review'],
    ]];

    // Settings used to be hardcoded markup AFTER the render loop, so any
    // data-driven consumer (palette, mobile sheet) silently missed it.
    $settings = [
        ['page' => 'profile',  'href' => 'profile.php',  'icon' => 'fa-user', 'label' => 'Profile'],
        ['page' => 'settings', 'href' => 'settings.php', 'icon' => 'fa-gear', 'label' => 'Settings'],
    ];
    if (function_exists('isAdmin') && isAdmin()) {
        $settings[] = ['page' => 'admin', 'href' => 'admin.php', 'icon' => 'fa-shield-halved', 'label' => 'Admin'];
    }
    $tree[] = ['key' => 'settings', 'heading' => 'Settings', 'items' => $settings];

    return $cached = $tree;
}

/** Flat list of every nav destination — for the palette and the mobile sheet. */
function navFlat(): array {
    $out = [];
    foreach (navTree() as $group) {
        foreach ($group['items'] as $item) {
            $item['group'] = $group['heading'] ?? 'Home';
            $out[] = $item;
        }
    }
    return $out;
}

/** The destinations that appear in the mobile bottom bar. */
function navPrimary(): array {
    return array_values(array_filter(navFlat(), fn($i) => !empty($i['primary'])));
}

/** Font Awesome icon for a page slug, taken from navTree(). '' if unknown. */
function navIconFor(string $page): string {
    foreach (navFlat() as $item) {
        if ($item['page'] === $page) return $item['icon'];
    }
    return '';
}

/**
 * Renders the 3-way Done / Fail / Skip habit-log control.
 * $status is 'done' | 'fail' | 'skip' | null (not logged yet today).
 */
/**
 * Maps a user's chosen hobbies to a handful of starter-habit suggestions —
 * Trackie's personalization hook (see Profile → Your hobbies).
 */
function suggestedHabitsForHobbies(?string $hobbiesCsv): array {
    $map = [
        'Fitness'      => ['name' => '30-min workout',       'color' => '#ef4444', 'freq' => 'daily'],
        'Reading'      => ['name' => 'Read 20 pages',         'color' => '#8b5cf6', 'freq' => 'daily'],
        'Coding'       => ['name' => 'Code for 1 hour',       'color' => '#3b82f6', 'freq' => 'daily'],
        'Gaming'       => ['name' => 'Screen-time check-in',  'color' => '#22c55e', 'freq' => 'daily'],
        'Cooking'      => ['name' => 'Cook a meal from scratch','color' => '#f97316', 'freq' => 'weekly'],
        'Art'          => ['name' => 'Sketch or paint',       'color' => '#ec4899', 'freq' => 'daily'],
        'Sports'       => ['name' => 'Play or train',         'color' => '#0ea5e9', 'freq' => 'weekly'],
        'Writing'      => ['name' => 'Write 300 words',       'color' => '#14b8a6', 'freq' => 'daily'],
        'Meditation'   => ['name' => 'Meditate 10 minutes',   'color' => '#a855f7', 'freq' => 'daily'],
        'Photography'  => ['name' => 'Take a photo a day',    'color' => '#64748b', 'freq' => 'daily'],
        'Gardening'    => ['name' => 'Tend the garden',       'color' => '#16a34a', 'freq' => 'weekly'],
    ];
    $hobbies = array_filter(array_map('trim', explode(',', (string)$hobbiesCsv)));
    $out = [];
    foreach ($hobbies as $h) {
        if (isset($map[$h])) $out[] = $map[$h] + ['hobby' => $h];
    }
    return $out;
}

function renderHabitStatusControl(int $habitId, ?string $status): string {
    $opts = [
        'done' => ['icon' => 'fa-check',  'label' => 'Done'],
        'fail' => ['icon' => 'fa-xmark',  'label' => 'Fail'],
        'skip' => ['icon' => 'fa-forward','label' => 'Skip'],
    ];
    $html = '<div class="habit-status-group" role="group" aria-label="Log today\'s status">';
    foreach ($opts as $key => $o) {
        $active = $status === $key ? ' is-active' : '';
        $html .= '<button type="button" class="habit-status-btn habit-status-' . $key . $active . '"'
               . ' onclick="setHabitStatus(' . $habitId . ',\'' . $key . '\',this)" title="' . $o['label'] . '">'
               . '<i class="fas ' . $o['icon'] . '"></i><span>' . $o['label'] . '</span></button>';
    }
    $html .= '</div>';
    return $html;
}

// ── Rate limiting ─────────────────────────────────────────────

/**
 * Returns true if the request is allowed, false if rate-limited.
 * @param string $action   e.g. 'login', 'register', 'password_reset'
 * @param string $key      Identifier — use IP address or user email
 * @param int    $max      Max attempts allowed in the window
 * @param int    $window   Window in seconds (default 15 min)
 */
function rateLimit(string $action, string $key, int $max = 5, int $window = 900): bool {
    // Purge expired records
    delete(
        "DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL ? SECOND)",
        [$window]
    );

    $row = fetchOne(
        "SELECT id, attempts FROM rate_limits
         WHERE identifier=? AND action=?
         AND window_start > DATE_SUB(NOW(), INTERVAL ? SECOND)
         LIMIT 1",
        [$key, $action, $window]
    );

    if (!$row) {
        insert(
            "INSERT INTO rate_limits (identifier, action, attempts, window_start) VALUES (?,?,1,NOW())",
            [$key, $action]
        );
        return true;
    }

    if ((int)$row['attempts'] >= $max) return false;

    update(
        "UPDATE rate_limits SET attempts = attempts + 1 WHERE id = ?",
        [$row['id']]
    );
    return true;
}

// ── Notification helpers ──────────────────────────────────────

/**
 * Create an in-app notification for a user.
 * Prevents duplicate notifications of the same type on the same day.
 */
function createNotification(
    int $userId, string $type, string $title,
    string $message = '', string $link = ''
): void {
    // Settings → Preferences: achievement / level / streak notifications can be turned off.
    if (in_array($type, ['xp', 'achievement', 'streak'], true)) {
        require_once __DIR__ . '/settings.php';
        if (!userSetting($userId, 'notify_achievements')) return;
    }
    // Deduplicate: don't re-insert same type+title combo today
    $exists = fetchOne(
        "SELECT id FROM notifications
         WHERE user_id=? AND type=? AND title=? AND DATE(created_at)=CURDATE()
         LIMIT 1",
        [$userId, $type, $title]
    );
    if ($exists) return;

    insert(
        "INSERT INTO notifications (user_id, type, title, message, link) VALUES (?,?,?,?,?)",
        [$userId, $type, $title, $message, $link]
    );

    // Also to the phone (Android app via Firebase). Reminders are excluded: the
    // app schedules those as alarms, so a push would duplicate them.
    if ($type !== 'reminder') {
        try {
            require_once __DIR__ . '/native.php';
            require_once __DIR__ . '/settings.php';
            if (nativeReady() && fcmEnabled() && !inQuietHours($userId)) {
                sendFcmToUser($userId, ['title' => $title, 'body' => $message, 'url' => $link ?: APP_BASE . '/pages/today.php', 'tag' => $type]);
            }
        } catch (Throwable $e) {
            error_log('push: ' . $e->getMessage());   // the bell entry above still stands
        }
    }
}

/**
 * Check whether a table exists (cached per request).
 * Lets pages that depend on newer tables degrade gracefully on a
 * database that hasn't been migrated yet, instead of throwing a 500.
 */
function tableExists(string $table): bool {
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];
    try {
        db()->query("SELECT 1 FROM `" . str_replace('`', '', $table) . "` LIMIT 1");
        return $cache[$table] = true;
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

/**
 * Render a "module needs database setup" page and exit.
 * Used by pages whose tables may be missing on an un-migrated install.
 * Requires $pageTitle / $currentPage to be set by the caller first.
 */
function renderSetupNeeded(string $moduleName): void {
    require_once __DIR__ . '/head.php'; ?>
    <div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="main-wrap">
    <?php include __DIR__ . '/header.php'; ?>
    <div class="page-content">
      <div class="card"><div class="empty-state">
        <div class="empty-state-icon"><i class="fas fa-database"></i></div>
        <div class="empty-state-title"><?= h($moduleName) ?> needs a quick setup</div>
        <p>The database tables for this module aren't created yet.<br>
           Run the one-click setup to add them — your existing data is untouched.</p>
        <a href="<?= APP_BASE ?>/pages/setup.php" class="btn btn-primary" style="margin-top:.75rem">
          <i class="fas fa-bolt"></i> Run database setup
        </a>
      </div></div>
    </div>
    <?php include __DIR__ . '/footer.php';
    exit;
}

/**
 * Count unread notifications for a user.
 * Returns 0 safely if the notifications table does not yet exist.
 */
function unreadNotificationCount(int $userId): int {
    try {
        return (int)(fetchOne(
            "SELECT COUNT(*) c FROM notifications WHERE user_id=? AND is_read=0",
            [$userId]
        )['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Reset recurring todos whose completion has rolled over into a new period.
 * Daily todos reset the day after they were completed; weekly todos reset once
 * a new week (Mon-start) begins; monthly todos reset on the 1st of the month.
 * Safe to call on every page load - only touches rows that are actually stale.
 */
function resetRecurringTodos(int $uid): void {
    update(
        "UPDATE todos SET completed=0, completed_at=NULL
         WHERE user_id=? AND deleted_at IS NULL AND recurring='daily'
           AND completed=1 AND completed_at IS NOT NULL AND DATE(completed_at) < CURDATE()",
        [$uid]
    );

    update(
        "UPDATE todos SET completed=0, completed_at=NULL
         WHERE user_id=? AND deleted_at IS NULL AND recurring='weekly'
           AND completed=1 AND completed_at IS NOT NULL
           AND DATE(completed_at) < DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)",
        [$uid]
    );

    update(
        "UPDATE todos SET completed=0, completed_at=NULL
         WHERE user_id=? AND deleted_at IS NULL AND recurring='monthly'
           AND completed=1 AND completed_at IS NOT NULL
           AND DATE(completed_at) < DATE_FORMAT(CURDATE(), '%Y-%m-01')",
        [$uid]
    );
}

// ── Reusable UI components (v2 design system) ─────────────────────
// Shared markup so every page renders headers, stat cards, and empty
// states identically. All values are output-escaped here; callers pass
// raw strings. Icon = Font Awesome class (e.g. 'fa-bolt').

/**
 * Standard page header: title (with optional icon) + optional subtitle,
 * plus an optional right-aligned actions slot (raw HTML — caller builds
 * its own buttons). Matches the v2 spacing used across pages.
 */
/**
 * Absolute origin for canonical/OG links: APP_URL from env when set, else the
 * request's own scheme + host (only letters, digits, dots, dashes and a port
 * are accepted, so a forged Host header can't inject markup). No trailing slash.
 */
function siteBaseUrl(): string {
    if (defined('APP_URL') && APP_URL !== '') return rtrim(APP_URL, '/');
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (!preg_match('/^[a-z0-9.-]+(:\d{1,5})?$/i', $host)) return '';
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https://' : 'http://') . $host;
}

function renderPageHeader(string $title, array $opts = []): string {
    $icon    = $opts['icon']    ?? '';
    $sub     = $opts['sub']     ?? '';
    $actions = $opts['actions'] ?? '';
    $iconHtml = $icon ? '<i class="fas ' . h($icon) . '" style="color:var(--accent)"></i> ' : '';
    $subHtml  = $sub ? '<p class="page-header-sub">' . h($sub) . '</p>' : '';
    return
        '<div class="page-header">'
      .   '<div class="page-header-text">'
      // h1, not h2: this is the page's single top-level heading. It rendered
      // as h2 for a long time, which left every page in the app with no h1 at
      // all and broke screen-reader document structure (WCAG 1.3.1 / 2.4.6).
      .     '<h1 class="page-header-title">' . $iconHtml . h($title) . '</h1>'
      .     $subHtml
      .   '</div>'
      .   ($actions ? '<div class="page-header-actions">' . $actions . '</div>' : '')
      . '</div>';
}

/**
 * Single stat card. $color tints the icon (any CSS color / var()).
 */
function renderStatCard($value, string $label, string $icon = '', string $color = 'var(--muted)'): string {
    $iconHtml = $icon
        ? '<i class="fas ' . h($icon) . '" style="color:' . h($color) . '"></i> '
        : '';
    return
        '<div class="stat-card">'
      .   '<div class="stat-val">' . h((string)$value) . '</div>'
      .   '<div class="stat-label">' . $iconHtml . h($label) . '</div>'
      . '</div>';
}

/**
 * Reusable empty state. $actionHtml is raw (a button/link) or ''.
 */
function renderEmptyState(string $icon, string $title, string $text = '', string $actionHtml = ''): string {
    return
        '<div class="empty-state">'
      .   '<div class="empty-state-icon"><i class="fas ' . h($icon) . '"></i></div>'
      .   '<div class="empty-state-title">' . h($title) . '</div>'
      .   ($text ? '<p>' . h($text) . '</p>' : '')
      .   ($actionHtml ? '<div style="margin-top:.75rem">' . $actionHtml . '</div>' : '')
      . '</div>';
}

/**
 * AI-style insight strip. A single intelligent, rule-based sentence
 * computed from the user's own data. $text is plain (escaped here).
 * Returns '' when $text is empty so callers can unconditionally echo it.
 */
function renderInsight(string $text, string $icon = 'fa-wand-magic-sparkles'): string {
    if ($text === '') return '';
    return
        '<div class="insight-strip" role="note">'
      .   '<span class="insight-strip-icon"><i class="fas ' . h($icon) . '"></i></span>'
      .   '<span class="insight-strip-text">' . h($text) . '</span>'
      . '</div>';
}

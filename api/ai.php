<?php
/**
 * AI Coach — generates a short, data-grounded coaching insight for a hobby
 * module from the user's own stats. Tries Gemini first (cheaper / free tier),
 * falls back to OpenAI if only that key is set. Returns a clear "not
 * configured" state if neither key is present — never fakes a response.
 *
 * Insights are cached in the session per hobby per day so repeated tab opens
 * don't re-spend API credits; "refresh" forces a new call.
 */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();
verify_csrf();

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');

if ($action !== 'coach') json_out(['success' => false, 'error' => 'Unknown action.'], 400);

$hobby   = sanitizeInput($_POST['hobby'] ?? '');
$force   = !empty($_POST['force']);
$today   = date('Y-m-d');
$cacheKey = "ai_coach_{$hobby}_{$uid}";

if (!$force && !empty($_SESSION[$cacheKey]) && ($_SESSION[$cacheKey]['date'] ?? '') === $today) {
    json_out(['success' => true, 'text' => $_SESSION[$cacheKey]['text'], 'cached' => true]);
}

$geminiKey = env('GEMINI_API_KEY');
$openaiKey = env('OPENAI_API_KEY');
if (!$geminiKey && !$openaiKey) {
    json_out(['success' => false, 'reason' => 'not_configured', 'error' => 'AI insights are not set up on this server.'], 503);
}

// ── Build a compact, data-grounded prompt from the user's real stats ──
$prompt = null;

if ($hobby === 'Fitness' && tableExists('workout_logs')) {
    $stats = fetchOne(
        "SELECT COUNT(DISTINCT log_date) days,
                COUNT(DISTINCT CASE WHEN log_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN log_date END) week_days
         FROM workout_logs WHERE user_id=?", [$uid]
    );
    $topExercises = fetchAll(
        "SELECT exercise_name, COUNT(*) c, MAX(weight_kg) max_w FROM workout_logs
         WHERE user_id=? GROUP BY exercise_name ORDER BY c DESC LIMIT 5", [$uid]
    );
    $logDates = array_column(fetchAll("SELECT DISTINCT log_date FROM workout_logs WHERE user_id=? ORDER BY log_date", [$uid]), 'log_date');
    $streak = calculateStreaks($logDates, userNeutralDates($uid));

    if ((int)$stats['days'] === 0) {
        json_out(['success' => true, 'text' => "No workouts logged yet — log your first session and I'll start spotting patterns in your training.", 'cached' => false]);
    }

    $exList = implode(', ', array_map(fn($e) => "{$e['exercise_name']} ({$e['c']}x" . ($e['max_w'] ? ", max {$e['max_w']}kg" : '') . ")", $topExercises));
    $prompt = "You are a concise, encouraging fitness coach. Here is a user's real training data from their tracking app:\n"
        . "- Total workout days logged: {$stats['days']}\n"
        . "- Workouts this week: {$stats['week_days']}\n"
        . "- Current streak: {$streak['current']} days, best streak: {$streak['best']} days\n"
        . "- Most-logged exercises: {$exList}\n\n"
        . "Give ONE short paragraph (max 60 words) of specific, actionable coaching feedback based only on this data. "
        . "No generic filler, no disclaimers, no markdown headers — just the insight, like a coach texting a quick note.";
}

if ($hobby === 'Coding' && tableExists('projects')) {
    $projStats = fetchOne(
        "SELECT COUNT(*) total, SUM(status='done') done, SUM(status='active') active FROM projects WHERE user_id=?", [$uid]
    );
    $taskStats = fetchOne(
        "SELECT COUNT(*) total, SUM(status='done') done FROM project_tasks t JOIN projects p ON p.id=t.project_id WHERE p.user_id=?", [$uid]
    );
    if ((int)$projStats['total'] === 0) {
        json_out(['success' => true, 'text' => "No projects yet — add one and I'll track your shipping velocity over time.", 'cached' => false]);
    }
    $prompt = "You are a concise, pragmatic engineering mentor. Here is a user's real project data:\n"
        . "- Total projects: {$projStats['total']} ({$projStats['done']} done, {$projStats['active']} active)\n"
        . "- Tasks: {$taskStats['done']}/{$taskStats['total']} completed\n\n"
        . "Give ONE short paragraph (max 60 words) of specific, actionable feedback based only on this data — e.g. flag stalled projects, "
        . "praise good task-completion rate, or suggest finishing something before starting new work. No filler, no markdown.";
}

if ($hobby === 'Reading' && tableExists('books')) {
    $bookStats = fetchOne(
        "SELECT COUNT(*) total, SUM(status='finished') finished, SUM(status='reading') reading, AVG(rating) avg_rating FROM books WHERE user_id=?", [$uid]
    );
    if ((int)$bookStats['total'] === 0) {
        json_out(['success' => true, 'text' => "No books yet — add what you're reading and I'll help you build a real habit around it.", 'cached' => false]);
    }
    $prompt = "You are a concise, well-read reading coach. Here is a user's real library data:\n"
        . "- Total books: {$bookStats['total']} ({$bookStats['finished']} finished, {$bookStats['reading']} currently reading)\n"
        . "- Average rating given: " . ($bookStats['avg_rating'] ? round($bookStats['avg_rating'], 1) . '/5' : 'none yet') . "\n\n"
        . "Give ONE short paragraph (max 60 words) of specific feedback based only on this data. No filler, no markdown.";
}

if (!$prompt) json_out(['success' => false, 'error' => 'Unsupported hobby or no data module.'], 422);

// ── Call whichever provider is configured (Gemini first) ──────────
$text = $geminiKey ? callGemini($prompt, $geminiKey) : null;
if (!$text && $openaiKey) $text = callOpenAI($prompt, $openaiKey);

if (!$text) json_out(['success' => false, 'error' => 'AI provider request failed. Try again shortly.'], 502);

$_SESSION[$cacheKey] = ['date' => $today, 'text' => $text];
json_out(['success' => true, 'text' => $text, 'cached' => false]);

function callGemini(string $prompt, string $key): ?string {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($key);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['maxOutputTokens' => 150, 'temperature' => 0.7],
        ]),
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$raw) return null;
    $data = json_decode($raw, true);
    return trim($data['candidates'][0]['content']['parts'][0]['text'] ?? '') ?: null;
}

function callOpenAI(string $prompt, string $key): ?string {
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
        CURLOPT_POSTFIELDS     => json_encode([
            'model'      => 'gpt-4o-mini',
            'messages'   => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => 150,
            'temperature'=> 0.7,
        ]),
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$raw) return null;
    $data = json_decode($raw, true);
    return trim($data['choices'][0]['message']['content'] ?? '') ?: null;
}

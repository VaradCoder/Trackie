<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../app/Modules/Reading/ReadingService.php';

requireAuth();
verify_csrf();

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');
$svc    = new ReadingService($uid);

$optInt = static function (string $k): ?int {
    $v = trim((string)($_POST[$k] ?? ''));
    return $v === '' ? null : (int)$v;
};

try {
    switch ($action) {

        /* ── Books (add / update_status / rate / delete keep their old contracts) ── */

        case 'add':
            $id = $svc->addBook($_POST);
            json_out(['success' => true, 'id' => $id]);

        case 'edit':
            $svc->editBook((int)($_POST['book_id'] ?? 0), $_POST);
            json_out(['success' => true]);

        case 'update_status':
            $res = $svc->setStatus((int)($_POST['book_id'] ?? 0), sanitizeInput($_POST['status'] ?? ''));
            $newAch = [];
            if (($_POST['status'] ?? '') === 'finished' && function_exists('checkAchievements')) $newAch = checkAchievements($uid);
            json_out(['success' => true, 'xp' => $res['xp'], 'newAchievements' => $newAch]);

        case 'rate':
            $svc->rate((int)($_POST['book_id'] ?? 0), (int)($_POST['rating'] ?? 0));
            json_out(['success' => true]);

        case 'delete':
            $id = (int)($_POST['book_id'] ?? 0);
            if (!$id) json_out(['success' => false, 'error' => 'Invalid book.'], 422);
            $svc->deleteBook($id);
            json_out(['success' => true]);

        case 'detail':
            $d = $svc->detail((int)($_POST['book_id'] ?? 0));
            if (!$d) json_out(['success' => false, 'error' => 'Book not found.'], 404);
            json_out(['success' => true] + $d);

        /* ── Book search (Open Library, server-side + cached) ── */

        case 'search':
            $results = ReadingService::provider()->search((string)($_POST['q'] ?? ''), 8);
            if ($results === null) {
                json_out(['success' => false, 'error' => "Book search isn't available right now — you can still add the book by hand."], 502);
            }
            json_out(['success' => true, 'results' => $results, 'source' => 'Open Library']);

        /* ── Sessions ── */

        case 'session_log':
            $res = $svc->logSession(
                (int)($_POST['book_id'] ?? 0),
                (int)($_POST['minutes'] ?? 0),
                $optInt('end_page'),
                $_POST['note'] ?? null,
                $_POST['date'] ?? null
            );
            json_out(['success' => true] + $res);

        case 'session_delete':
            $svc->deleteSession((int)($_POST['session_id'] ?? 0));
            json_out(['success' => true]);

        /* ── Notes & quotes ── */

        case 'note_add':
            $id = $svc->addNote((int)($_POST['book_id'] ?? 0), (string)($_POST['kind'] ?? 'note'),
                                (string)($_POST['body'] ?? ''), $optInt('page'));
            json_out(['success' => true, 'id' => $id]);

        case 'note_delete':
            $svc->deleteNote((int)($_POST['note_id'] ?? 0));
            json_out(['success' => true]);

        /* ── Goal + stats ── */

        case 'goal_save':
            $svc->saveGoal((int)date('Y'), $optInt('books_target'), $optInt('pages_target'));
            json_out(['success' => true]);

        case 'stats':
            $year = (int)($_POST['year'] ?? date('Y'));
            if ($year < 2000 || $year > (int)date('Y')) $year = (int)date('Y');
            json_out(['success' => true, 'stats' => $svc->stats($year)]);

        default:
            json_out(['success' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (InvalidArgumentException $e) {
    json_out(['success' => false, 'error' => $e->getMessage()], 422);
}

<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();
verify_csrf();

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');

switch ($action) {

    case 'add':
        $title  = sanitizeInput($_POST['title']  ?? '');
        $author = sanitizeInput($_POST['author'] ?? '');
        $status = sanitizeInput($_POST['status'] ?? 'want');
        if (!$title) json_out(['success' => false, 'error' => 'Title is required.'], 422);
        if (!in_array($status, ['want','reading','finished'], true)) $status = 'want';

        $startedAt  = $status !== 'want' ? date('Y-m-d') : null;
        $finishedAt = $status === 'finished' ? date('Y-m-d') : null;

        $id = insert(
            "INSERT INTO books (user_id,title,author,status,started_at,finished_at) VALUES (?,?,?,?,?,?)",
            [$uid, $title, $author ?: null, $status, $startedAt, $finishedAt]
        );
        json_out(['success' => true, 'id' => $id]);

    case 'update_status':
        $id     = (int)($_POST['book_id'] ?? 0);
        $status = sanitizeInput($_POST['status'] ?? '');
        if (!in_array($status, ['want','reading','finished'], true)) json_out(['success' => false, 'error' => 'Invalid status.'], 422);

        $book = fetchOne("SELECT * FROM books WHERE id=? AND user_id=?", [$id, $uid]);
        if (!$book) json_out(['success' => false, 'error' => 'Not found.'], 404);

        $startedAt  = $book['started_at'];
        $finishedAt = $book['finished_at'];
        if ($status === 'reading' && !$startedAt) $startedAt = date('Y-m-d');
        if ($status === 'finished' && !$finishedAt) $finishedAt = date('Y-m-d');
        if ($status === 'want') { $startedAt = null; $finishedAt = null; }

        update("UPDATE books SET status=?, started_at=?, finished_at=? WHERE id=?", [$status, $startedAt, $finishedAt, $id]);

        $newAch = [];
        if ($status === 'finished') {
            require_once '../includes/gamification.php';
            if (function_exists('awardXpOnce')) awardXpOnce($uid, 'book_finished', 30, 'book', $id);
            $newAch = function_exists('checkAchievements') ? checkAchievements($uid) : [];
        }
        json_out(['success' => true, 'newAchievements' => $newAch]);

    case 'rate':
        $id     = (int)($_POST['book_id'] ?? 0);
        $rating = (int)($_POST['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) json_out(['success' => false, 'error' => 'Rating must be 1-5.'], 422);
        update("UPDATE books SET rating=? WHERE id=? AND user_id=?", [$rating, $id, $uid]);
        json_out(['success' => true]);

    case 'delete':
        $id = (int)($_POST['book_id'] ?? 0);
        if (!$id) json_out(['success' => false, 'error' => 'Invalid book.'], 422);
        delete("DELETE FROM books WHERE id=? AND user_id=?", [$id, $uid]);
        json_out(['success' => true]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}

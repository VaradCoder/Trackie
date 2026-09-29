<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/activity.php';

requireAuth();
verify_csrf();

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');
const W_TYPES  = ['draft', 'article', 'story', 'book', 'idea'];
const W_STATUS = ['idea', 'drafting', 'editing', 'published'];

/** Words in any script (letters/numbers runs, apostrophes kept inside words). */
function wordCount(string $t): int {
    return (int)preg_match_all("/[\\p{L}\\p{N}]+(?:['’][\\p{L}\\p{N}]+)*/u", $t);
}
function ownWriting(int $uid, int $id): array {
    $w = fetchOne("SELECT * FROM writings WHERE id=? AND user_id=?", [$id, $uid]);
    if (!$w) json_out(['success' => false, 'error' => 'Piece not found.'], 404);
    return $w;
}

switch ($action) {

    case 'add':
        $title = mb_substr(trim(sanitizeInput($_POST['title'] ?? '')), 0, 150);
        if ($title === '') json_out(['success' => false, 'error' => 'Title is required.'], 422);
        $type   = in_array($_POST['type'] ?? '', W_TYPES, true) ? $_POST['type'] : 'draft';
        $status = in_array($_POST['status'] ?? '', W_STATUS, true) ? $_POST['status'] : 'idea';
        $id = insert("INSERT INTO writings (user_id,title,type,word_count,status,updated_at) VALUES (?,?,?,0,?,NOW())", [$uid, $title, $type, $status]);
        json_out(['success' => true, 'id' => $id]);

    case 'get':
        $w = ownWriting($uid, (int)($_POST['item_id'] ?? 0));
        json_out(['success' => true, 'piece' => $w]);

    case 'edit':
        $w = ownWriting($uid, (int)($_POST['item_id'] ?? 0));
        $title = mb_substr(trim(sanitizeInput($_POST['title'] ?? '')), 0, 150);
        if ($title === '') json_out(['success' => false, 'error' => 'Title is required.'], 422);
        $type = in_array($_POST['type'] ?? '', W_TYPES, true) ? $_POST['type'] : $w['type'];
        update("UPDATE writings SET title=?, type=? WHERE id=? AND user_id=?", [$title, $type, $w['id'], $uid]);
        json_out(['success' => true]);

    case 'save_content':
        // Autosave from the editor. Words ADDED since the last save count toward
        // today's total (deleting text never subtracts from what you wrote).
        $w = ownWriting($uid, (int)($_POST['item_id'] ?? 0));
        $content = str_replace("\r", '', (string)($_POST['content'] ?? ''));
        if (strlen($content) > 2_000_000) json_out(['success' => false, 'error' => 'That piece is too long to save (2 MB max).'], 422);
        if (!mb_check_encoding($content, 'UTF-8')) json_out(['success' => false, 'error' => "The text contains characters that couldn't be read — try pasting it again."], 422);
        $words = wordCount($content);
        $added = max(0, $words - (int)$w['word_count']);
        update("UPDATE writings SET content=?, word_count=?, updated_at=NOW(), status=IF(status='idea','drafting',status) WHERE id=? AND user_id=?",
               [$content, $words, $w['id'], $uid]);
        $xp = null;
        if ($added > 0) {
            update("INSERT INTO writing_log (user_id,log_date,words_added) VALUES (?,CURDATE(),?)
                    ON DUPLICATE KEY UPDATE words_added = words_added + VALUES(words_added)", [$uid, $added]);
            $xp = recordActivity($uid, 'writing_day', 'writing_day', (int)date('Ymd'));
        }
        $today = (int)(fetchOne("SELECT words_added w FROM writing_log WHERE user_id=? AND log_date=CURDATE()", [$uid])['w'] ?? 0);
        json_out(['success' => true, 'words' => $words, 'added' => $added, 'today' => $today, 'xp' => $xp]);

    case 'update_status':
        $status = sanitizeInput($_POST['status'] ?? '');
        if (!in_array($status, W_STATUS, true)) json_out(['success' => false, 'error' => 'Invalid status.'], 422);
        update("UPDATE writings SET status=? WHERE id=? AND user_id=?", [$status, (int)($_POST['item_id'] ?? 0), $uid]);
        json_out(['success' => true]);

    case 'update_words':
        // Legacy manual count (pieces written outside Trackie). Doesn't touch the daily log.
        $words = max(0, (int)($_POST['word_count'] ?? 0));
        update("UPDATE writings SET word_count=? WHERE id=? AND user_id=? AND (content IS NULL OR content='')", [$words, (int)($_POST['item_id'] ?? 0), $uid]);
        json_out(['success' => true]);

    case 'delete':
        delete("DELETE FROM writings WHERE id=? AND user_id=?", [(int)($_POST['item_id'] ?? 0), $uid]);
        json_out(['success' => true]);

    case 'goal_save':
        $g = trim((string)($_POST['writing_goal'] ?? ''));
        $g = $g === '' ? null : (int)$g;
        if ($g !== null && ($g < 1 || $g > 50000)) json_out(['success' => false, 'error' => 'Daily goal must be 1–50,000 words.'], 422);
        update("INSERT INTO user_settings (user_id, writing_goal) VALUES (?,?) ON DUPLICATE KEY UPDATE writing_goal=VALUES(writing_goal)", [$uid, $g]);
        json_out(['success' => true]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}

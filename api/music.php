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

    case 'journal_add':
        $body = trim(sanitizeInput($_POST['body'] ?? ''));
        $date = sanitizeInput($_POST['entry_date'] ?? date('Y-m-d'));
        if (!$body) json_out(['success' => false, 'error' => 'Entry cannot be empty.'], 422);
        $id = insert(
            "INSERT INTO hobby_journal (user_id,hobby,entry_date,body) VALUES (?,'Music',?,?)",
            [$uid, $date, $body]
        );
        json_out(['success' => true, 'id' => $id]);

    case 'journal_delete':
        $id = (int)($_POST['entry_id'] ?? 0);
        delete("DELETE FROM hobby_journal WHERE id=? AND user_id=? AND hobby='Music'", [$id, $uid]);
        json_out(['success' => true]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}

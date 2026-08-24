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
        $title = sanitizeInput($_POST['title'] ?? '');
        if (!$title) json_out(['success' => false, 'error' => 'Title is required.'], 422);
        $id = insert(
            "INSERT INTO writings (user_id,title,type,word_count,status) VALUES (?,?,?,?,?)",
            [$uid, $title, sanitizeInput($_POST['type'] ?? 'draft'),
             ($_POST['word_count'] ?? '') !== '' ? (int)$_POST['word_count'] : 0,
             sanitizeInput($_POST['status'] ?? 'idea')]
        );
        json_out(['success' => true, 'id' => $id]);

    case 'update_status':
        $id = (int)($_POST['item_id'] ?? 0);
        $status = sanitizeInput($_POST['status'] ?? '');
        if (!in_array($status, ['idea','drafting','editing','published'], true)) json_out(['success' => false, 'error' => 'Invalid status.'], 422);
        update("UPDATE writings SET status=? WHERE id=? AND user_id=?", [$status, $id, $uid]);
        json_out(['success' => true]);

    case 'update_words':
        $id = (int)($_POST['item_id'] ?? 0);
        $count = (int)($_POST['word_count'] ?? 0);
        update("UPDATE writings SET word_count=? WHERE id=? AND user_id=?", [$count, $id, $uid]);
        json_out(['success' => true]);

    case 'delete':
        $id = (int)($_POST['item_id'] ?? 0);
        delete("DELETE FROM writings WHERE id=? AND user_id=?", [$id, $uid]);
        json_out(['success' => true]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}

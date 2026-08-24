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
            "INSERT INTO photos (user_id,title,location,camera,status,taken_date) VALUES (?,?,?,?,?,?)",
            [$uid, $title, sanitizeInput($_POST['location'] ?? '') ?: null, sanitizeInput($_POST['camera'] ?? '') ?: null,
             sanitizeInput($_POST['status'] ?? 'to_edit'), sanitizeInput($_POST['taken_date'] ?? '') ?: null]
        );
        json_out(['success' => true, 'id' => $id]);

    case 'update_status':
        $id = (int)($_POST['item_id'] ?? 0);
        $status = sanitizeInput($_POST['status'] ?? '');
        if (!in_array($status, ['to_edit','edited'], true)) json_out(['success' => false, 'error' => 'Invalid status.'], 422);
        update("UPDATE photos SET status=? WHERE id=? AND user_id=?", [$status, $id, $uid]);
        json_out(['success' => true]);

    case 'delete':
        $id = (int)($_POST['item_id'] ?? 0);
        delete("DELETE FROM photos WHERE id=? AND user_id=?", [$id, $uid]);
        json_out(['success' => true]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}

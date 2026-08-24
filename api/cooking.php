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
            "INSERT INTO recipes (user_id,title,category,cook_time_min,status) VALUES (?,?,?,?,?)",
            [$uid, $title, sanitizeInput($_POST['category'] ?? '') ?: null,
             $_POST['cook_time_min'] !== '' ? (int)$_POST['cook_time_min'] : null,
             sanitizeInput($_POST['status'] ?? 'want_to_try')]
        );
        json_out(['success' => true, 'id' => $id]);

    case 'update_status':
        $id = (int)($_POST['item_id'] ?? 0);
        $status = sanitizeInput($_POST['status'] ?? '');
        if (!in_array($status, ['want_to_try','tried','favorite'], true)) json_out(['success' => false, 'error' => 'Invalid status.'], 422);
        update("UPDATE recipes SET status=? WHERE id=? AND user_id=?", [$status, $id, $uid]);
        json_out(['success' => true]);

    case 'rate':
        $id = (int)($_POST['item_id'] ?? 0);
        $rating = (int)($_POST['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) json_out(['success' => false, 'error' => 'Rating must be 1-5.'], 422);
        update("UPDATE recipes SET rating=? WHERE id=? AND user_id=?", [$rating, $id, $uid]);
        json_out(['success' => true]);

    case 'delete':
        $id = (int)($_POST['item_id'] ?? 0);
        delete("DELETE FROM recipes WHERE id=? AND user_id=?", [$id, $uid]);
        json_out(['success' => true]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}

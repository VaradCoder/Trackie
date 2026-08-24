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
        $name = sanitizeInput($_POST['name'] ?? '');
        if (!$name) json_out(['success' => false, 'error' => 'Name is required.'], 422);
        $id = insert(
            "INSERT INTO plants (user_id,name,species,water_frequency_days,last_watered) VALUES (?,?,?,?,?)",
            [$uid, $name, sanitizeInput($_POST['species'] ?? '') ?: null,
             (int)($_POST['water_frequency_days'] ?? 7) ?: 7, date('Y-m-d')]
        );
        json_out(['success' => true, 'id' => $id]);

    case 'water':
        $id = (int)($_POST['item_id'] ?? 0);
        update("UPDATE plants SET last_watered=?, status='healthy' WHERE id=? AND user_id=?", [date('Y-m-d'), $id, $uid]);
        json_out(['success' => true]);

    case 'update_status':
        $id = (int)($_POST['item_id'] ?? 0);
        $status = sanitizeInput($_POST['status'] ?? '');
        if (!in_array($status, ['healthy','needs_attention','dormant'], true)) json_out(['success' => false, 'error' => 'Invalid status.'], 422);
        update("UPDATE plants SET status=? WHERE id=? AND user_id=?", [$status, $id, $uid]);
        json_out(['success' => true]);

    case 'delete':
        $id = (int)($_POST['item_id'] ?? 0);
        delete("DELETE FROM plants WHERE id=? AND user_id=?", [$id, $uid]);
        json_out(['success' => true]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}

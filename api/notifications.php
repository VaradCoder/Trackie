<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();
$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? $_GET['action'] ?? 'list');

switch ($action) {

    case 'list':
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
        $rows  = fetchAll(
            "SELECT * FROM notifications WHERE user_id=?
             ORDER BY created_at DESC LIMIT ?",
            [$uid, $limit]
        );
        $unread = (int)fetchOne(
            "SELECT COUNT(*) c FROM notifications WHERE user_id=? AND is_read=0",
            [$uid]
        )['c'];
        json_out(['success' => true, 'notifications' => $rows, 'unread' => $unread]);

    case 'mark_read':
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            update("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?", [$id, $uid]);
        } else {
            update("UPDATE notifications SET is_read=1 WHERE user_id=?", [$uid]);
        }
        json_out(['success' => true]);

    case 'mark_all_read':
        verify_csrf();
        update("UPDATE notifications SET is_read=1 WHERE user_id=?", [$uid]);
        json_out(['success' => true]);

    case 'delete':
        verify_csrf();
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            delete("DELETE FROM notifications WHERE id=? AND user_id=?", [$id, $uid]);
        }
        json_out(['success' => true]);

    case 'unread_count':
        $c = (int)fetchOne(
            "SELECT COUNT(*) c FROM notifications WHERE user_id=? AND is_read=0",
            [$uid]
        )['c'];
        json_out(['success' => true, 'count' => $c]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}

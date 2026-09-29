<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/activity.php';
require_once '../app/Modules/Photography/PhotoStorage.php';

requireAuth();
verify_csrf();

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');

function ownArt(int $uid, int $id): array {
    $a = fetchOne("SELECT * FROM artworks WHERE id=? AND user_id=?", [$id, $uid]);
    if (!$a) json_out(['success' => false, 'error' => 'Artwork not found.'], 404);
    return $a;
}

try {
    switch ($action) {

        case 'add':
        case 'edit':
            $title = mb_substr(trim(sanitizeInput($_POST['title'] ?? '')), 0, 150);
            if ($title === '') json_out(['success' => false, 'error' => 'Title is required.'], 422);
            $medium = mb_substr(trim(sanitizeInput($_POST['medium'] ?? '')), 0, 60) ?: null;
            $notes  = mb_substr(trim(sanitizeInput($_POST['notes'] ?? '')), 0, 500) ?: null;
            $status = ($_POST['status'] ?? '') === 'completed' ? 'completed' : 'in_progress';
            if ($action === 'edit') {
                $a = ownArt($uid, (int)($_POST['item_id'] ?? 0));
                update("UPDATE artworks SET title=?, medium=?, notes=?, status=? WHERE id=? AND user_id=?", [$title, $medium, $notes, $status, $a['id'], $uid]);
                $id = (int)$a['id'];
            } else {
                $id = (int)insert("INSERT INTO artworks (user_id,title,medium,status,notes) VALUES (?,?,?,?,?)", [$uid, $title, $medium, $status, $notes]);
            }
            // Optional image in the same request (multipart).
            if (!empty($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $old = fetchOne("SELECT image_file, image_thumb FROM artworks WHERE id=?", [$id]);
                $st = PhotoStorage::store($uid, $_FILES['image']);
                update("UPDATE artworks SET image_file=?, image_thumb=? WHERE id=? AND user_id=?", [$st['file'], $st['thumb'], $id, $uid]);
                if ($old && $old['image_file']) PhotoStorage::delete($uid, $old['image_file'], (string)$old['image_thumb']);
            }
            json_out(['success' => true, 'id' => $id]);

        case 'get':
            $a = ownArt($uid, (int)($_POST['item_id'] ?? 0));
            $a['sessions'] = fetchAll("SELECT id, session_date, minutes, notes FROM art_sessions WHERE user_id=? AND artwork_id=? ORDER BY session_date DESC LIMIT 20", [$uid, $a['id']]);
            json_out(['success' => true, 'artwork' => $a]);

        case 'update_status':
            $status = sanitizeInput($_POST['status'] ?? '');
            if (!in_array($status, ['in_progress', 'completed'], true)) json_out(['success' => false, 'error' => 'Invalid status.'], 422);
            update("UPDATE artworks SET status=? WHERE id=? AND user_id=?", [$status, (int)($_POST['item_id'] ?? 0), $uid]);
            json_out(['success' => true]);

        case 'delete':
            $a = fetchOne("SELECT image_file, image_thumb FROM artworks WHERE id=? AND user_id=?", [(int)($_POST['item_id'] ?? 0), $uid]);
            if ($a) {
                delete("DELETE FROM artworks WHERE id=? AND user_id=?", [(int)$_POST['item_id'], $uid]);
                if ($a['image_file']) PhotoStorage::delete($uid, $a['image_file'], (string)$a['image_thumb']);
            }
            json_out(['success' => true]);

        case 'session_log':
            $min = (int)($_POST['minutes'] ?? 0);
            if ($min < 1 || $min > 1440) json_out(['success' => false, 'error' => 'Minutes must be 1–1,440.'], 422);
            $date = (string)($_POST['session_date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
            if ($date > date('Y-m-d')) json_out(['success' => false, 'error' => "A session can't be in the future."], 422);
            $aid = (int)($_POST['artwork_id'] ?? 0) ?: null;
            if ($aid) ownArt($uid, $aid);
            $sid = (int)insert("INSERT INTO art_sessions (user_id,artwork_id,session_date,minutes,notes) VALUES (?,?,?,?,?)",
                [$uid, $aid, $date, $min, mb_substr(trim(sanitizeInput($_POST['notes'] ?? '')), 0, 500) ?: null]);
            $xp = recordActivity($uid, 'art_session', 'art_day', (int)date('Ymd', strtotime($date)), ['date' => $date]);
            json_out(['success' => true, 'id' => $sid, 'xp' => $xp]);

        case 'session_delete':
            $s = fetchOne("SELECT session_date FROM art_sessions WHERE id=? AND user_id=?", [(int)($_POST['session_id'] ?? 0), $uid]);
            if ($s) {
                delete("DELETE FROM art_sessions WHERE id=? AND user_id=?", [(int)$_POST['session_id'], $uid]);
                if (!fetchOne("SELECT id FROM art_sessions WHERE user_id=? AND session_date=? LIMIT 1", [$uid, $s['session_date']])) {
                    undoActivity($uid, 'art_session', 'art_day', (int)date('Ymd', strtotime($s['session_date'])));
                }
            }
            json_out(['success' => true]);

        default:
            json_out(['success' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (InvalidArgumentException $e) {
    json_out(['success' => false, 'error' => $e->getMessage()], 422);
} catch (RuntimeException $e) {
    error_log('Art: ' . $e->getMessage());
    json_out(['success' => false, 'error' => 'Something went wrong on the server. Try again later.'], 500);
}

<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../app/Modules/Photography/PhotographyService.php';

requireAuth();
verify_csrf();

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');
$svc    = new PhotographyService($uid);

$optInt = static function (string $k): ?int {
    $v = trim((string)($_POST[$k] ?? ''));
    return $v === '' ? null : (int)$v;
};

try {
    switch ($action) {

        /* ── Shoots (add / update_status / delete keep their old contracts) ── */

        case 'add':
        case 'shoot_save':
            $id = (int)($_POST['shoot_id'] ?? 0);
            $newId = $svc->saveShoot($_POST, $id ?: null);
            $xp = $id ? null : $svc->shootXp($newId);
            json_out(['success' => true, 'id' => $newId, 'xp' => $xp]);

        case 'update_status':
            $svc->setShootStatus((int)($_POST['item_id'] ?? 0), sanitizeInput($_POST['status'] ?? ''));
            json_out(['success' => true]);

        case 'delete':
            $svc->deleteShoot((int)($_POST['item_id'] ?? 0));
            json_out(['success' => true]);

        /* ── Photos ── */

        case 'photo_upload':
            if (empty($_FILES['photo'])) {
                // PHP drops the whole body when it exceeds post_max_size.
                json_out(['success' => false, 'error' => 'No photo received — it may be larger than the server allows.'], 422);
            }
            $res = $svc->upload($_FILES['photo'], $_POST);
            json_out(['success' => true] + $res);

        case 'photo_get':
            $p = $svc->photo((int)($_POST['photo_id'] ?? 0));
            if (!$p) json_out(['success' => false, 'error' => 'Photo not found.'], 404);
            json_out(['success' => true, 'photo' => $p]);

        case 'photo_update':
            $fields = array_intersect_key($_POST, array_flip(['title', 'caption', 'shoot_id', 'project_id', 'favorite', 'edit_status']));
            $svc->updatePhoto((int)($_POST['photo_id'] ?? 0), $fields);
            json_out(['success' => true, 'photo' => $svc->photo((int)($_POST['photo_id'] ?? 0))]);

        case 'photo_delete':
            $svc->deletePhoto((int)($_POST['photo_id'] ?? 0));
            json_out(['success' => true]);

        /* ── Projects ── */

        case 'project_save':
            $id = (int)($_POST['project_id'] ?? 0);
            json_out(['success' => true, 'id' => $svc->saveProject($_POST, $id ?: null)]);

        case 'project_delete':
            $svc->deleteProject((int)($_POST['project_id'] ?? 0));
            json_out(['success' => true]);

        /* ── Gear ── */

        case 'gear_add':
            json_out(['success' => true, 'id' => $svc->addGear((string)($_POST['kind'] ?? ''), $_POST['name'] ?? '', $_POST['notes'] ?? '')]);

        case 'gear_delete':
            $svc->deleteGear((int)($_POST['gear_id'] ?? 0));
            json_out(['success' => true]);

        /* ── Goals + stats ── */

        case 'goals_save':
            $svc->saveGoals($optInt('photos_per_month'), $optInt('shoots_per_month'));
            json_out(['success' => true]);

        case 'stats':
            $year = (int)($_POST['year'] ?? date('Y'));
            if ($year < 1990 || $year > (int)date('Y')) $year = (int)date('Y');
            json_out(['success' => true, 'stats' => $svc->stats($year)]);

        default:
            json_out(['success' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (InvalidArgumentException $e) {
    json_out(['success' => false, 'error' => $e->getMessage()], 422);
} catch (RuntimeException $e) {
    error_log('Photography: ' . $e->getMessage());
    json_out(['success' => false, 'error' => 'Something went wrong on the server. Try again later.'], 500);
}

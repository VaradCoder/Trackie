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
const CARE_KINDS = ['water', 'fertilize', 'repot', 'prune', 'note'];

function ownPlant(int $uid, int $id): array {
    $p = fetchOne("SELECT * FROM plants WHERE id=? AND user_id=?", [$id, $uid]);
    if (!$p) json_out(['success' => false, 'error' => 'Plant not found.'], 404);
    return $p;
}

/** Record a care action (water / fertilize / … / note). Watering also updates last_watered. */
function careLog(int $uid, array $plant, string $kind, ?string $note, string $date): ?array {
    insert("INSERT INTO plant_logs (user_id,plant_id,log_date,kind,note) VALUES (?,?,?,?,?)", [$uid, $plant['id'], $date, $kind, $note]);
    if ($kind === 'water' && ($plant['last_watered'] === null || $date >= $plant['last_watered'])) {
        update("UPDATE plants SET last_watered=? WHERE id=?", [$date, $plant['id']]);
    }
    // Journal notes don't count as care; real care does (once per day).
    return $kind === 'note' ? null : recordActivity($uid, 'garden_care', 'garden_day', (int)date('Ymd', strtotime($date)), ['date' => $date]);
}

function plantInput(): array {
    $name = mb_substr(trim(sanitizeInput($_POST['name'] ?? '')), 0, 100);
    if ($name === '') json_out(['success' => false, 'error' => 'Name is required.'], 422);
    $freq = (int)($_POST['water_frequency_days'] ?? 7);
    if ($freq < 1 || $freq > 365) json_out(['success' => false, 'error' => 'Watering interval must be 1–365 days.'], 422);
    return [$name, mb_substr(trim(sanitizeInput($_POST['species'] ?? '')), 0, 100) ?: null,
            mb_substr(trim(sanitizeInput($_POST['location'] ?? '')), 0, 80) ?: null, $freq,
            mb_substr(trim(sanitizeInput($_POST['notes'] ?? '')), 0, 500) ?: null];
}

switch ($action) {

    case 'add':
        [$name, $species, $loc, $freq, $notes] = plantInput();
        $id = insert("INSERT INTO plants (user_id,name,species,location,water_frequency_days,last_watered,notes) VALUES (?,?,?,?,?,?,?)",
                     [$uid, $name, $species, $loc, $freq, date('Y-m-d'), $notes]);
        json_out(['success' => true, 'id' => $id]);

    case 'edit':
        $p = ownPlant($uid, (int)($_POST['item_id'] ?? 0));
        [$name, $species, $loc, $freq, $notes] = plantInput();
        update("UPDATE plants SET name=?,species=?,location=?,water_frequency_days=?,notes=? WHERE id=? AND user_id=?", [$name, $species, $loc, $freq, $notes, $p['id'], $uid]);
        json_out(['success' => true]);

    case 'get':
        $p = ownPlant($uid, (int)($_POST['item_id'] ?? 0));
        $p['logs'] = fetchAll("SELECT id, log_date, kind, note FROM plant_logs WHERE plant_id=? AND user_id=? ORDER BY log_date DESC, id DESC LIMIT 50", [$p['id'], $uid]);
        json_out(['success' => true, 'plant' => $p]);

    case 'water':        // legacy one-tap watering
    case 'care':
        $p = ownPlant($uid, (int)($_POST['item_id'] ?? 0));
        $kind = $action === 'water' ? 'water' : (string)($_POST['kind'] ?? '');
        if (!in_array($kind, CARE_KINDS, true)) json_out(['success' => false, 'error' => 'Unknown care type.'], 422);
        $note = mb_substr(trim(sanitizeInput($_POST['note'] ?? '')), 0, 500) ?: null;
        if ($kind === 'note' && !$note) json_out(['success' => false, 'error' => 'Write a note first.'], 422);
        $date = (string)($_POST['log_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
        if ($date > date('Y-m-d')) json_out(['success' => false, 'error' => "Can't log care in the future."], 422);
        $xp = careLog($uid, $p, $kind, $note, $date);
        json_out(['success' => true, 'xp' => $xp]);

    case 'log_delete':
        delete("DELETE FROM plant_logs WHERE id=? AND user_id=?", [(int)($_POST['log_id'] ?? 0), $uid]);
        json_out(['success' => true]);

    case 'update_status':
        $status = sanitizeInput($_POST['status'] ?? '');
        if (!in_array($status, ['healthy', 'needs_attention', 'dormant'], true)) json_out(['success' => false, 'error' => 'Invalid status.'], 422);
        update("UPDATE plants SET status=? WHERE id=? AND user_id=?", [$status, (int)($_POST['item_id'] ?? 0), $uid]);
        json_out(['success' => true]);

    case 'delete':
        delete("DELETE FROM plants WHERE id=? AND user_id=?", [(int)($_POST['item_id'] ?? 0), $uid]);
        json_out(['success' => true]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}

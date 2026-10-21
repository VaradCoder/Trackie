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
const RECIPE_STATUS = ['want_to_try', 'tried', 'favorite'];
const MEALS = ['breakfast', 'lunch', 'dinner'];

const MEALDB = 'https://www.themealdb.com/api/json/v1/1/';

/** TheMealDB meal → [title, category, ingredient lines, step lines], sized to the recipes columns. */
function mealDbRecipe(array $m): array {
    $ing = [];
    for ($i = 1; $i <= 20; $i++) {
        $name = trim((string)($m["strIngredient$i"] ?? ''));
        if ($name === '') continue;
        $ing[] = trim(trim((string)($m["strMeasure$i"] ?? '')) . ' ' . $name);
    }
    $steps = array_values(array_filter(array_map('trim', preg_split('/\R+/', (string)($m['strInstructions'] ?? ''))),
        static fn($l) => $l !== '' && !preg_match('/^(step\s*)?\d+\.?$/i', $l)));
    $cat = implode(' · ', array_filter([trim((string)($m['strCategory'] ?? '')), trim((string)($m['strArea'] ?? ''))]));
    return [mb_substr(trim((string)$m['strMeal']), 0, 150), mb_substr($cat, 0, 60) ?: null,
            mb_substr(implode("\n", $ing), 0, 8000) ?: null, mb_substr(implode("\n", $steps), 0, 8000) ?: null];
}

function ownRecipe(int $uid, int $id): array {
    $r = fetchOne("SELECT * FROM recipes WHERE id=? AND user_id=?", [$id, $uid]);
    if (!$r) json_out(['success' => false, 'error' => 'Recipe not found.'], 404);
    return $r;
}

function recipeInput(): array {
    $title = mb_substr(trim(sanitizeInput($_POST['title'] ?? '')), 0, 150);
    if ($title === '') json_out(['success' => false, 'error' => 'Title is required.'], 422);
    $time = trim((string)($_POST['cook_time_min'] ?? '')) === '' ? null : (int)$_POST['cook_time_min'];
    if ($time !== null && ($time < 1 || $time > 2880)) json_out(['success' => false, 'error' => 'Cook time must be 1–2,880 minutes.'], 422);
    $serv = trim((string)($_POST['servings'] ?? '')) === '' ? null : (int)$_POST['servings'];
    if ($serv !== null && ($serv < 1 || $serv > 100)) json_out(['success' => false, 'error' => 'Servings must be 1–100.'], 422);
    $lines = static fn(string $k) => mb_substr(trim(implode("\n", array_filter(array_map('trim', explode("\n", str_replace("\r", '', (string)($_POST[$k] ?? ''))))))), 0, 8000) ?: null;
    return [$title, mb_substr(trim(sanitizeInput($_POST['category'] ?? '')), 0, 60) ?: null, $time, $serv,
            $lines('ingredients'), $lines('steps'), mb_substr(trim(sanitizeInput($_POST['notes'] ?? '')), 0, 500) ?: null];
}

switch ($action) {

    case 'add':
        [$title, $cat, $time, $serv, $ing, $steps, $notes] = recipeInput();
        $status = in_array($_POST['status'] ?? '', RECIPE_STATUS, true) ? $_POST['status'] : 'want_to_try';
        $id = insert("INSERT INTO recipes (user_id,title,category,cook_time_min,servings,ingredients,steps,status,notes) VALUES (?,?,?,?,?,?,?,?,?)",
                     [$uid, $title, $cat, $time, $serv, $ing, $steps, $status, $notes]);
        json_out(['success' => true, 'id' => $id]);

    case 'edit':
        $r = ownRecipe($uid, (int)($_POST['item_id'] ?? 0));
        [$title, $cat, $time, $serv, $ing, $steps, $notes] = recipeInput();
        update("UPDATE recipes SET title=?,category=?,cook_time_min=?,servings=?,ingredients=?,steps=?,notes=? WHERE id=? AND user_id=?",
               [$title, $cat, $time, $serv, $ing, $steps, $notes, $r['id'], $uid]);
        json_out(['success' => true]);

    case 'get':
        $r = ownRecipe($uid, (int)($_POST['item_id'] ?? 0));
        $r['cooks'] = fetchAll("SELECT id, cooked_on, rating, notes FROM cook_logs WHERE recipe_id=? AND user_id=? ORDER BY cooked_on DESC, id DESC LIMIT 20", [$r['id'], $uid]);
        json_out(['success' => true, 'recipe' => $r]);

    case 'update_status':
        $status = sanitizeInput($_POST['status'] ?? '');
        if (!in_array($status, RECIPE_STATUS, true)) json_out(['success' => false, 'error' => 'Invalid status.'], 422);
        update("UPDATE recipes SET status=? WHERE id=? AND user_id=?", [$status, (int)($_POST['item_id'] ?? 0), $uid]);
        json_out(['success' => true]);

    case 'rate':
        $rating = (int)($_POST['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) json_out(['success' => false, 'error' => 'Rating must be 1-5.'], 422);
        update("UPDATE recipes SET rating=? WHERE id=? AND user_id=?", [$rating, (int)($_POST['item_id'] ?? 0), $uid]);
        json_out(['success' => true]);

    case 'delete':
        delete("DELETE FROM recipes WHERE id=? AND user_id=?", [(int)($_POST['item_id'] ?? 0), $uid]);
        json_out(['success' => true]);

    case 'cook':
        $r = ownRecipe($uid, (int)($_POST['item_id'] ?? 0));
        $date = (string)($_POST['cooked_on'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
        if ($date > date('Y-m-d')) json_out(['success' => false, 'error' => "Can't log cooking in the future."], 422);
        $rating = (int)($_POST['rating'] ?? 0);
        $id = (int)insert("INSERT INTO cook_logs (user_id,recipe_id,cooked_on,rating,notes) VALUES (?,?,?,?,?)",
            [$uid, $r['id'], $date, ($rating >= 1 && $rating <= 5) ? $rating : null, mb_substr(trim(sanitizeInput($_POST['notes'] ?? '')), 0, 500) ?: null]);
        if ($r['status'] === 'want_to_try') update("UPDATE recipes SET status='tried' WHERE id=?", [$r['id']]);
        if ($rating >= 1 && $rating <= 5) update("UPDATE recipes SET rating=? WHERE id=?", [$rating, $r['id']]);
        // Once per recipe per day.
        $xp = recordActivity($uid, 'recipe_cooked', 'cook:' . $date, (int)$r['id'], ['date' => $date]);
        json_out(['success' => true, 'id' => $id, 'xp' => $xp]);

    case 'cook_delete':
        $c = fetchOne("SELECT recipe_id, cooked_on FROM cook_logs WHERE id=? AND user_id=?", [(int)($_POST['log_id'] ?? 0), $uid]);
        if ($c) {
            delete("DELETE FROM cook_logs WHERE id=? AND user_id=?", [(int)$_POST['log_id'], $uid]);
            if (!fetchOne("SELECT id FROM cook_logs WHERE user_id=? AND recipe_id=? AND cooked_on=? LIMIT 1", [$uid, $c['recipe_id'], $c['cooked_on']])) {
                undoActivity($uid, 'recipe_cooked', 'cook:' . $c['cooked_on'], (int)$c['recipe_id']);
            }
        }
        json_out(['success' => true]);

    case 'plan_set':
        $date = (string)($_POST['plan_date'] ?? '');
        $meal = (string)($_POST['meal'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !in_array($meal, MEALS, true)) json_out(['success' => false, 'error' => 'Invalid slot.'], 422);
        $rid = (int)($_POST['recipe_id'] ?? 0) ?: null;
        if ($rid) ownRecipe($uid, $rid);
        $note = mb_substr(trim(sanitizeInput($_POST['note'] ?? '')), 0, 120) ?: null;
        if (!$rid && !$note) {
            delete("DELETE FROM meal_plan WHERE user_id=? AND plan_date=? AND meal=?", [$uid, $date, $meal]);
        } else {
            update("INSERT INTO meal_plan (user_id,plan_date,meal,recipe_id,note) VALUES (?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE recipe_id=VALUES(recipe_id), note=VALUES(note)", [$uid, $date, $meal, $rid, $note]);
        }
        json_out(['success' => true]);

    // ── Discover: TheMealDB (free, no key), fetched server-side and cached ──
    case 'discover':
        require_once '../includes/http.php';
        $q = mb_substr(trim((string)($_POST['q'] ?? '')), 0, 60);
        $cat = mb_substr(trim((string)($_POST['category'] ?? '')), 0, 40);
        if (($_POST['random'] ?? '') === '1') {
            $meals = [];   // three distinct random picks; random.php isn't cached
            for ($i = 0; $i < 6 && count($meals) < 3; $i++) {
                $m = httpJson(MEALDB . 'random.php', 0)['meals'][0] ?? null;
                if ($m) $meals[$m['idMeal']] = $m;
            }
            $meals = array_values($meals);
        } elseif ($cat !== '') {
            $meals = httpJson(MEALDB . 'filter.php?' . http_build_query(['c' => $cat]), 86400)['meals'] ?? [];
            $meals = array_slice($meals ?: [], 0, 24);
        } else {
            $meals = $q === '' ? [] : (httpJson(MEALDB . 'search.php?' . http_build_query(['s' => $q]), 86400)['meals'] ?? []);
            $meals = array_slice($meals ?: [], 0, 24);
        }
        $saved = [];
        foreach (fetchAll("SELECT id, notes FROM recipes WHERE user_id=? AND notes LIKE 'From TheMealDB #%'", [$uid]) as $r) {
            if (preg_match('/^From TheMealDB #(\d+)/', (string)$r['notes'], $m)) $saved[$m[1]] = (int)$r['id'];
        }
        $out = array_map(static fn($m) => [
            'id'    => (string)$m['idMeal'],
            'title' => (string)$m['strMeal'],
            'meta'  => implode(' · ', array_filter([$m['strCategory'] ?? ($cat ?: null), $m['strArea'] ?? null])),
            'thumb' => str_starts_with((string)($m['strMealThumb'] ?? ''), 'https://') ? $m['strMealThumb'] . '/preview' : null,
            'saved' => $saved[(string)$m['idMeal']] ?? null,
        ], array_filter($meals, static fn($m) => isset($m['idMeal'], $m['strMeal']) && ctype_digit((string)$m['idMeal'])));
        json_out(['success' => true, 'meals' => array_values($out)]);

    case 'import':
        require_once '../includes/http.php';
        $mid = (string)($_POST['mealdb_id'] ?? '');
        if (!ctype_digit($mid) || strlen($mid) > 10) json_out(['success' => false, 'error' => 'Invalid recipe.'], 422);
        $dupe = fetchOne("SELECT id FROM recipes WHERE user_id=? AND notes LIKE ?", [$uid, "From TheMealDB #$mid %"]);
        if ($dupe) json_out(['success' => true, 'id' => (int)$dupe['id'], 'existing' => true]);
        $m = httpJson(MEALDB . 'lookup.php?i=' . $mid, 7 * 86400)['meals'][0] ?? null;
        if (!$m) json_out(['success' => false, 'error' => 'TheMealDB is unreachable right now — try again in a moment.'], 502);
        [$title, $cat, $ing, $steps] = mealDbRecipe($m);
        $src = trim((string)($m['strSource'] ?: $m['strYoutube'] ?: ''));
        $notes = mb_substr("From TheMealDB #$mid · " . ($src && preg_match('~^https?://~', $src) ? $src : 'themealdb.com'), 0, 500);
        $id = (int)insert("INSERT INTO recipes (user_id,title,category,cook_time_min,servings,ingredients,steps,status,notes) VALUES (?,?,?,?,?,?,?,?,?)",
                          [$uid, $title, $cat, null, null, $ing, $steps, 'want_to_try', $notes]);
        json_out(['success' => true, 'id' => $id]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}

<?php
/**
 * Finance API — transactions, subscriptions, budgets.
 */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();
verify_csrf();

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');

/** Validate + collect transaction fields. Exits 422 on bad input. */
function txInput(): array {
    $type   = $_POST['type'] ?? 'expense';
    $title  = sanitizeInput($_POST['title'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $cat    = sanitizeInput($_POST['category'] ?? 'Other') ?: 'Other';
    $class  = $_POST['expense_class'] ?? null;
    $date   = sanitizeInput($_POST['tx_date'] ?? '');
    $notes  = sanitizeInput($_POST['notes'] ?? '');

    if (!in_array($type, ['income', 'expense'], true)) $type = 'expense';
    if (!$title) json_out(['success' => false, 'error' => 'Title is required.'], 422);
    if ($amount <= 0 || $amount > 99999999) json_out(['success' => false, 'error' => 'Enter a valid amount.'], 422);
    if (!$date || !strtotime($date)) $date = date('Y-m-d');

    if ($type === 'expense') {
        if (!in_array($class, ['need', 'want'], true)) $class = 'want';
    } else {
        $class = null;
    }

    return compact('type', 'title', 'amount', 'cat', 'class', 'date', 'notes');
}

switch ($action) {

    // ── Transactions ──────────────────────────────────────────
    case 'add_tx':
        $in = txInput();
        $id = insert(
            "INSERT INTO transactions (user_id,type,title,amount,category,expense_class,tx_date,notes)
             VALUES (?,?,?,?,?,?,?,?)",
            [$uid, $in['type'], $in['title'], $in['amount'], $in['cat'], $in['class'], $in['date'], $in['notes']]
        );
        // Keeping your books counts toward the Trackie streak (no XP).
        require_once '../includes/activity.php';
        recordActivity($uid, 'finance_log', 'finance_day', (int)date('Ymd', strtotime($in['date'])), ['date' => $in['date']]);
        json_out(['success' => true, 'id' => $id]);

    case 'edit_tx':
        $id = (int)($_POST['tx_id'] ?? 0);
        if (!$id || !fetchOne("SELECT id FROM transactions WHERE id=? AND user_id=?", [$id, $uid])) {
            json_out(['success' => false, 'error' => 'Transaction not found.'], 404);
        }
        $in = txInput();
        update(
            "UPDATE transactions SET type=?,title=?,amount=?,category=?,expense_class=?,tx_date=?,notes=?
             WHERE id=? AND user_id=?",
            [$in['type'], $in['title'], $in['amount'], $in['cat'], $in['class'], $in['date'], $in['notes'], $id, $uid]
        );
        json_out(['success' => true]);

    case 'get_tx':
        $id = (int)($_POST['tx_id'] ?? 0);
        $t  = fetchOne("SELECT * FROM transactions WHERE id=? AND user_id=?", [$id, $uid]);
        if (!$t) json_out(['success' => false, 'error' => 'Transaction not found.'], 404);
        json_out(['success' => true, 'tx' => $t]);

    case 'delete_tx':
        $id = (int)($_POST['tx_id'] ?? 0);
        delete("DELETE FROM transactions WHERE id=? AND user_id=?", [$id, $uid]);
        json_out(['success' => true]);

    // ── Subscriptions ─────────────────────────────────────────
    case 'add_sub':
    case 'edit_sub':
        $name   = sanitizeInput($_POST['name'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $cycle  = in_array($_POST['billing_cycle'] ?? '', ['monthly', 'yearly'], true)
                ? $_POST['billing_cycle'] : 'monthly';
        $renew  = sanitizeInput($_POST['next_renewal'] ?? '');

        if (!$name) json_out(['success' => false, 'error' => 'Name is required.'], 422);
        if ($amount <= 0) json_out(['success' => false, 'error' => 'Enter a valid amount.'], 422);
        if (!$renew || !strtotime($renew)) json_out(['success' => false, 'error' => 'Pick the next renewal date.'], 422);

        if ($action === 'add_sub') {
            $id = insert(
                "INSERT INTO subscriptions (user_id,name,amount,billing_cycle,next_renewal) VALUES (?,?,?,?,?)",
                [$uid, $name, $amount, $cycle, $renew]
            );
            json_out(['success' => true, 'id' => $id]);
        }
        $id = (int)($_POST['sub_id'] ?? 0);
        if (!$id || !fetchOne("SELECT id FROM subscriptions WHERE id=? AND user_id=?", [$id, $uid])) {
            json_out(['success' => false, 'error' => 'Subscription not found.'], 404);
        }
        update(
            "UPDATE subscriptions SET name=?,amount=?,billing_cycle=?,next_renewal=? WHERE id=? AND user_id=?",
            [$name, $amount, $cycle, $renew, $id, $uid]
        );
        json_out(['success' => true]);

    case 'get_sub':
        $id = (int)($_POST['sub_id'] ?? 0);
        $s  = fetchOne("SELECT * FROM subscriptions WHERE id=? AND user_id=?", [$id, $uid]);
        if (!$s) json_out(['success' => false, 'error' => 'Subscription not found.'], 404);
        json_out(['success' => true, 'sub' => $s]);

    case 'toggle_sub':
        $id = (int)($_POST['sub_id'] ?? 0);
        $s  = fetchOne("SELECT active FROM subscriptions WHERE id=? AND user_id=?", [$id, $uid]);
        if (!$s) json_out(['success' => false, 'error' => 'Subscription not found.'], 404);
        update("UPDATE subscriptions SET active=? WHERE id=?", [$s['active'] ? 0 : 1, $id]);
        json_out(['success' => true, 'active' => $s['active'] ? 0 : 1]);

    case 'delete_sub':
        $id = (int)($_POST['sub_id'] ?? 0);
        delete("DELETE FROM subscriptions WHERE id=? AND user_id=?", [$id, $uid]);
        json_out(['success' => true]);

    // ── Budgets ───────────────────────────────────────────────
    case 'set_budget':
        $cat   = sanitizeInput($_POST['category'] ?? '');
        $limit = (float)($_POST['monthly_limit'] ?? 0);
        if (!$cat) json_out(['success' => false, 'error' => 'Category is required.'], 422);
        if ($limit <= 0) json_out(['success' => false, 'error' => 'Enter a valid limit.'], 422);
        insert(
            "INSERT INTO budgets (user_id,category,monthly_limit) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE monthly_limit=VALUES(monthly_limit)",
            [$uid, $cat, $limit]
        );
        json_out(['success' => true]);

    case 'delete_budget':
        $id = (int)($_POST['budget_id'] ?? 0);
        delete("DELETE FROM budgets WHERE id=? AND user_id=?", [$id, $uid]);
        json_out(['success' => true]);

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}

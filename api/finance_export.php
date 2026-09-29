<?php
/**
 * CSV export of the user's transactions: ?m=YYYY-MM (one month) or ?all=1.
 * Read-only GET, scoped to the signed-in user. Cells that start with = + - @
 * are prefixed with ' so spreadsheet apps never execute them as formulas.
 */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();
$uid = currentUserId();

if (!empty($_GET['all'])) {
    $rows  = fetchAll("SELECT tx_date, type, title, category, expense_class, amount, notes FROM transactions WHERE user_id=? ORDER BY tx_date, id", [$uid]);
    $label = 'all';
} else {
    $m = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
    $rows  = fetchAll("SELECT tx_date, type, title, category, expense_class, amount, notes FROM transactions
                       WHERE user_id=? AND tx_date BETWEEN ? AND ? ORDER BY tx_date, id", [$uid, "$m-01", date('Y-m-t', strtotime("$m-01"))]);
    $label = $m;
}

$safe = static function ($v): string {
    $v = (string)($v ?? '');
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
};

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="trackie-transactions-' . $label . '.csv"');
header('Cache-Control: private, no-store');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // BOM so Excel reads UTF-8 (₹, €…)
fputcsv($out, ['Date', 'Type', 'Title', 'Category', 'Need/Want', 'Amount', 'Notes']);
foreach ($rows as $r) {
    fputcsv($out, [$r['tx_date'], $r['type'], $safe($r['title']), $safe($r['category']), $r['expense_class'], $r['amount'], $safe($r['notes'])]);
}
fclose($out);

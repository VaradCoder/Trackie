<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Finance';
$currentPage = 'finance';

// Tables may not exist yet on an un-migrated database — degrade gracefully.
if (!tableExists('transactions')) renderSetupNeeded('Finance');

require_once '../includes/settings.php';
$curSym = CURRENCIES[userSetting($uid, 'currency')][0] ?? '₹';

/** Amount in the user's currency (Settings → Preferences). Name kept for the many call sites. */
function rupee(float $n): string {
    return money($n, currentUserId());
}

// ── Month selection (?m=YYYY-MM) ───────────────────────────────
$m = $_GET['m'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) $m = date('Y-m');
$monthStart = "$m-01";
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$prevM      = date('Y-m', strtotime("$monthStart -1 month"));
$nextM      = date('Y-m', strtotime("$monthStart +1 month"));
$monthLabel = date('F Y', strtotime($monthStart));
$isCurrent  = $m === date('Y-m');

// ── Auto-advance lapsed subscription renewals ──────────────────
$lapsed = fetchAll(
    "SELECT id, billing_cycle, next_renewal FROM subscriptions
     WHERE user_id=? AND active=1 AND next_renewal < CURDATE()", [$uid]
);
foreach ($lapsed as $s) {
    $next = strtotime($s['next_renewal']);
    $step = $s['billing_cycle'] === 'yearly' ? '+1 year' : '+1 month';
    while ($next < strtotime('today')) $next = strtotime($step, $next);
    update("UPDATE subscriptions SET next_renewal=? WHERE id=?", [date('Y-m-d', $next), $s['id']]);
}

// ── This month's summary ───────────────────────────────────────
$sum = fetchOne(
    "SELECT
        COALESCE(SUM(CASE WHEN type='income'  THEN amount END),0) income,
        COALESCE(SUM(CASE WHEN type='expense' THEN amount END),0) expense,
        COALESCE(SUM(CASE WHEN type='expense' AND expense_class='need' THEN amount END),0) needs,
        COALESCE(SUM(CASE WHEN type='expense' AND expense_class='want' THEN amount END),0) wants
     FROM transactions WHERE user_id=? AND tx_date BETWEEN ? AND ?",
    [$uid, $monthStart, $monthEnd]
);
$income  = (float)$sum['income'];
$expense = (float)$sum['expense'];
$needs   = (float)$sum['needs'];
$wants   = (float)$sum['wants'];
$saved   = $income - $expense;

// ── AI-style insight: this month's spend vs last month ──
$prevMonthStart = date('Y-m-01', strtotime("$monthStart -1 month"));
$prevMonthEnd   = date('Y-m-t',  strtotime("$monthStart -1 month"));
$prevExpense = (float)fetchOne(
    "SELECT COALESCE(SUM(amount),0) e FROM transactions
     WHERE user_id=? AND type='expense' AND tx_date BETWEEN ? AND ?",
    [$uid, $prevMonthStart, $prevMonthEnd]
)['e'];
$financeInsight = '';
if ($expense > 0 && $prevExpense > 0) {
    $delta = (int)round(($expense - $prevExpense) / $prevExpense * 100);
    if ($delta < 0)      $financeInsight = "You've spent " . abs($delta) . "% less than last month — savings are trending up. 📉";
    elseif ($delta > 0)  $financeInsight = "Spending is up {$delta}% vs last month. Your biggest category is worth a look.";
    else                 $financeInsight = "Spending is flat vs last month.";
} elseif ($income > 0 && $expense > 0) {
    $rate = (int)round($saved / $income * 100);
    if ($rate > 0) $financeInsight = "You're saving {$rate}% of your income this month. Aim for 20%+ to build a cushion.";
}

// ── Per-category spend: this month + previous month ────────────
$catRows = fetchAll(
    "SELECT category, SUM(amount) total FROM transactions
     WHERE user_id=? AND type='expense' AND tx_date BETWEEN ? AND ?
     GROUP BY category ORDER BY total DESC",
    [$uid, $monthStart, $monthEnd]
);
$prevStart = "$prevM-01";
$prevEnd   = date('Y-m-t', strtotime($prevStart));
$prevCatRows = fetchAll(
    "SELECT category, SUM(amount) total FROM transactions
     WHERE user_id=? AND type='expense' AND tx_date BETWEEN ? AND ?
     GROUP BY category",
    [$uid, $prevStart, $prevEnd]
);
$prevByCat = array_column($prevCatRows, 'total', 'category');

// ── Budgets joined with spend ──────────────────────────────────
$budgets = fetchAll("SELECT * FROM budgets WHERE user_id=? ORDER BY category", [$uid]);
$spentByCat = array_column($catRows, 'total', 'category');

// ── Subscriptions ──────────────────────────────────────────────
$subs = fetchAll(
    "SELECT * FROM subscriptions WHERE user_id=? ORDER BY active DESC, next_renewal ASC", [$uid]
);
$subMonthly = 0;
foreach ($subs as $s) {
    if ($s['active']) $subMonthly += $s['billing_cycle'] === 'yearly' ? $s['amount'] / 12 : $s['amount'];
}
$upcoming = array_filter($subs, fn($s) =>
    $s['active'] && strtotime($s['next_renewal']) <= strtotime('+30 days'));

// ── Savings goals (link Finance ↔ Goals) ───────────────────────
$savingGoals = fetchAll(
    "SELECT * FROM goals WHERE user_id=? AND progress < target_value
     ORDER BY deadline IS NULL, deadline ASC LIMIT 3", [$uid]
);

// ── Smart insights ─────────────────────────────────────────────
$insights = [];

foreach (array_slice($catRows, 0, 3) as $c) {
    $prev = (float)($prevByCat[$c['category']] ?? 0);
    if ($prev > 0) {
        $delta = (int)round(($c['total'] - $prev) / $prev * 100);
        if (abs($delta) >= 15) {
            $insights[] = [
                'icon' => $delta > 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down',
                'tone' => $delta > 0 ? 'warn' : 'ok',
                'text' => 'You spent ' . rupee((float)$c['total']) . ' on ' . $c['category'] . ' — '
                        . abs($delta) . '% ' . ($delta > 0 ? 'higher' : 'lower') . ' than last month.',
            ];
        }
    }
}

if ($income > 0 && $subMonthly > 0) {
    $pct = (int)round($subMonthly / $income * 100);
    $insights[] = [
        'icon' => 'fa-rotate',
        'tone' => $pct >= 15 ? 'warn' : 'info',
        'text' => 'Subscriptions consume ' . $pct . '% of this month\'s income ('
                . rupee($subMonthly) . '/mo).',
    ];
}

if ($wants > $needs && $wants > 0 && $needs > 0) {
    $insights[] = [
        'icon' => 'fa-scale-unbalanced',
        'tone' => 'warn',
        'text' => 'Wants (' . rupee($wants) . ') outweigh needs (' . rupee($needs) . ') this month.',
    ];
}

foreach ($budgets as $b) {
    $spent = (float)($spentByCat[$b['category']] ?? 0);
    if ($spent > (float)$b['monthly_limit']) {
        $insights[] = [
            'icon' => 'fa-triangle-exclamation',
            'tone' => 'bad',
            'text' => $b['category'] . ' budget exceeded by ' . rupee($spent - (float)$b['monthly_limit']) . '.',
        ];
    }
}

if ($saved > 0 && $income > 0) {
    $insights[] = [
        'icon' => 'fa-piggy-bank',
        'tone' => 'ok',
        'text' => 'You\'ve saved ' . rupee($saved) . ' (' . (int)round($saved / $income * 100) . '% of income) this month. 🎉',
    ];
}

// ── Transactions list (selected month) ─────────────────────────
$txFilter = in_array($_GET['t'] ?? '', ['income', 'expense']) ? $_GET['t'] : 'all';
$txSql    = "SELECT * FROM transactions WHERE user_id=? AND tx_date BETWEEN ? AND ?";
$txParams = [$uid, $monthStart, $monthEnd];
if ($txFilter !== 'all') { $txSql .= " AND type=?"; $txParams[] = $txFilter; }
$txSql .= " ORDER BY tx_date DESC, id DESC";
$transactions = fetchAll($txSql, $txParams);

// Category lists for the modal
$incomeCats  = ['Pocket Money', 'Freelance', 'Internship', 'Salary', 'Gift', 'Other'];
$expenseCats = ['Food', 'Food Delivery', 'Transport', 'College Fees', 'Books & Study', 'Coaching',
                'Rent', 'Electricity', 'Internet', 'Mobile Recharge', 'Entertainment',
                'Shopping', 'Subscriptions', 'Health', 'Other'];

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<!-- Toolbar -->
<div class="page-toolbar">
  <div style="display:flex;align-items:center;gap:.625rem">
    <h1 style="font-size:1.125rem;font-weight:600;margin:0;color:var(--text)"><i class="fas fa-wallet" style="color:var(--accent);margin-right:.375rem" aria-hidden="true"></i>Finance</h1>
    <div style="display:flex;align-items:center;gap:.25rem">
      <a href="?m=<?= $prevM ?>" class="btn btn-icon btn-ghost btn-sm" title="Previous month"><i class="fas fa-chevron-left"></i></a>
      <span style="font-size:.875rem;font-weight:600;color:var(--muted);min-width:7.5rem;text-align:center"><?= $monthLabel ?></span>
      <?php if (!$isCurrent): ?>
        <a href="?m=<?= $nextM ?>" class="btn btn-icon btn-ghost btn-sm" title="Next month"><i class="fas fa-chevron-right"></i></a>
      <?php endif; ?>
    </div>
  </div>
  <div style="display:flex;gap:.5rem">
    <button class="btn btn-secondary btn-sm" onclick="openAddSub()">
      <i class="fas fa-rotate"></i> Subscription
    </button>
    <button class="btn btn-primary btn-sm" onclick="openAddTx()">
      <i class="fas fa-plus"></i> Transaction
    </button>
  </div>
</div>

<!-- Stat row -->
<div class="grid-stats" id="financeStatsWrap">
  <div class="stat-card">
    <div class="stat-val" style="color:var(--ok)"><?= rupee($income) ?></div>
    <div class="stat-label"><i class="fas fa-arrow-down" style="font-size:.7rem"></i> Income</div>
  </div>
  <div class="stat-card">
    <div class="stat-val" style="color:var(--accent)"><?= rupee($expense) ?></div>
    <div class="stat-label"><i class="fas fa-arrow-up" style="font-size:.7rem"></i> Expenses</div>
  </div>
  <div class="stat-card">
    <div class="stat-val" style="color:var(--info)"><?= rupee($needs) ?></div>
    <div class="stat-label">Needs</div>
  </div>
  <div class="stat-card">
    <div class="stat-val" style="color:var(--warn)"><?= rupee($wants) ?></div>
    <div class="stat-label">Wants</div>
  </div>
  <div class="stat-card">
    <div class="stat-val" style="color:<?= $saved >= 0 ? 'var(--ok)' : 'var(--accent)' ?>"><?= rupee($saved) ?></div>
    <div class="stat-label">Saved</div>
  </div>
</div>

<?= renderInsight($financeInsight) ?>

<div class="grid-2" style="margin-bottom:1.25rem">

  <!-- Left column -->
  <div style="display:flex;flex-direction:column;gap:1.25rem">

    <!-- Monthly breakdown -->
    <div class="card card-body" id="breakdownCard">
      <div style="font-size:.9375rem;font-weight:600;margin-bottom:1rem">Monthly breakdown</div>
      <?php if ($expense == 0 && $income == 0): ?>
        <div class="empty-state" style="padding:1.5rem 1rem">
          <p>No transactions for <?= $monthLabel ?> yet.</p>
        </div>
      <?php else: ?>
        <div style="display:flex;align-items:center;gap:1.25rem;flex-wrap:wrap">
          <div style="width:160px;height:160px;flex-shrink:0">
            <canvas id="breakdownChart" data-needs="<?= $needs ?>" data-wants="<?= $wants ?>" data-savings="<?= max(0, $saved) ?>"></canvas>
          </div>
          <div style="flex:1;min-width:160px">
            <?php
            $rows = [
              ['Needs',   $needs, '#3b82f6'],
              ['Wants',   $wants, '#f59e0b'],
              ['Savings', max(0, $saved), '#22c55e'],
            ];
            $denom = max(1, $needs + $wants + max(0, $saved));
            foreach ($rows as [$lbl, $val, $col]): ?>
              <div style="margin-bottom:.625rem">
                <div style="display:flex;justify-content:space-between;font-size:.8125rem;margin-bottom:.25rem">
                  <span style="display:flex;align-items:center;gap:.375rem">
                    <span style="width:8px;height:8px;border-radius:50%;background:<?= $col ?>"></span><?= $lbl ?>
                  </span>
                  <strong><?= rupee($val) ?></strong>
                </div>
                <div class="progress-track" style="height:.375rem">
                  <div class="progress-fill" style="width:<?= round($val / $denom * 100) ?>%;background:<?= $col ?>"></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Budgets -->
    <div class="card card-body" id="budgetsCard">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">
        <span style="font-size:.9375rem;font-weight:600">Budgets</span>
        <button class="btn btn-ghost btn-sm" onclick="openModal('budgetModal')">
          <i class="fas fa-plus"></i> Set budget
        </button>
      </div>
      <?php if (empty($budgets)): ?>
        <p style="font-size:.875rem;color:var(--muted);margin:0">
          Set monthly limits per category — Trackie warns you when you cross them.
        </p>
      <?php else: ?>
        <?php foreach ($budgets as $b):
          $spent = (float)($spentByCat[$b['category']] ?? 0);
          $limit = (float)$b['monthly_limit'];
          $pct   = min(100, (int)round($spent / $limit * 100));
          $over  = $spent > $limit;
        ?>
          <div style="margin-bottom:.75rem" id="budget-<?= $b['id'] ?>">
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:.8125rem;margin-bottom:.25rem">
              <span style="font-weight:500"><?= h($b['category']) ?></span>
              <span style="display:flex;align-items:center;gap:.5rem">
                <?php if ($over): ?>
                  <span class="badge badge-red">Over by <?= rupee($spent - $limit) ?></span>
                <?php endif; ?>
                <span style="color:var(--muted)"><?= rupee($spent) ?> / <?= rupee($limit) ?></span>
                <button class="btn btn-icon btn-ghost btn-sm" style="width:1.5rem;height:1.5rem" title="Remove budget"
                        onclick="deleteBudget(<?= $b['id'] ?>)"><i class="fas fa-times" style="font-size:.7rem"></i></button>
              </span>
            </div>
            <div class="progress-track" style="height:.4rem">
              <div class="progress-fill <?= $over ? '' : 'green' ?>" style="width:<?= $pct ?>%"></div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Savings goals -->
    <div class="card card-body">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">
        <span style="font-size:.9375rem;font-weight:600">Savings goals</span>
        <a href="<?= APP_BASE ?>/pages/goals.php" class="d-card-link">Manage goals</a>
      </div>
      <?php if (empty($savingGoals)): ?>
        <p style="font-size:.875rem;color:var(--muted);margin:0">
          Create a goal (e.g. "Buy laptop — 70000") and track your saving progress here.
        </p>
      <?php else: foreach ($savingGoals as $g):
        $pct = $g['target_value'] > 0 ? min(100, (int)round($g['progress'] / $g['target_value'] * 100)) : 0; ?>
        <div style="margin-bottom:.75rem">
          <div style="display:flex;justify-content:space-between;font-size:.8125rem;margin-bottom:.25rem">
            <span style="font-weight:500"><?= h($g['goal_name']) ?></span>
            <span style="color:var(--muted)"><?= rupee((float)$g['progress']) ?> / <?= rupee((float)$g['target_value']) ?> · <?= $pct ?>%</span>
          </div>
          <div class="progress-track" style="height:.4rem">
            <div class="progress-fill green" style="width:<?= $pct ?>%"></div>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- Right column -->
  <div style="display:flex;flex-direction:column;gap:1.25rem">

    <!-- Smart insights -->
    <div class="card card-body">
      <div style="font-size:.9375rem;font-weight:600;margin-bottom:1rem">💡 Smart insights</div>
      <?php if (empty($insights)): ?>
        <p style="font-size:.875rem;color:var(--muted);margin:0">
          Add a few transactions and Trackie will start spotting patterns.
        </p>
      <?php else:
        $toneColor = ['ok' => 'var(--ok)', 'warn' => 'var(--warn)', 'bad' => 'var(--accent)', 'info' => 'var(--info)'];
        foreach ($insights as $i): ?>
        <div style="display:flex;gap:.625rem;align-items:flex-start;padding:.5rem 0;border-bottom:1px solid var(--border);font-size:.875rem">
          <i class="fas <?= $i['icon'] ?>" style="color:<?= $toneColor[$i['tone']] ?>;margin-top:.2rem;width:1rem;text-align:center"></i>
          <span style="color:var(--text)"><?= h($i['text']) ?></span>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- Subscriptions -->
    <div class="card card-body" id="subsCard">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">
        <span style="font-size:.9375rem;font-weight:600">Subscriptions</span>
        <span class="badge badge-gray"><?= rupee($subMonthly) ?>/mo</span>
      </div>

      <?php if (empty($subs)): ?>
        <p style="font-size:.875rem;color:var(--muted);margin:0">
          Track Spotify, Netflix, coaching apps — see what they really cost you.
        </p>
      <?php else: ?>
        <?php foreach ($subs as $s): ?>
          <div style="display:flex;align-items:center;gap:.625rem;padding:.5rem 0;border-bottom:1px solid var(--border);<?= $s['active'] ? '' : 'opacity:.5' ?>"
               id="sub-<?= $s['id'] ?>">
            <div style="flex:1;min-width:0">
              <div style="font-size:.875rem;font-weight:500"><?= h($s['name']) ?></div>
              <div style="font-size:.75rem;color:var(--muted)">
                <?= rupee((float)$s['amount']) ?>/<?= $s['billing_cycle'] === 'yearly' ? 'yr' : 'mo' ?>
                <?php if ($s['active']): ?> · renews <?= formatDate($s['next_renewal']) ?><?php else: ?> · paused<?php endif; ?>
              </div>
            </div>
            <button class="btn btn-icon btn-ghost btn-sm" title="<?= $s['active'] ? 'Pause' : 'Resume' ?>"
                    aria-label="<?= $s['active'] ? 'Pause' : 'Resume' ?> subscription <?= h($s['name']) ?>"
                    onclick="toggleSub(<?= $s['id'] ?>)"><i class="fas <?= $s['active'] ? 'fa-pause' : 'fa-play' ?>"></i></button>
            <button class="btn btn-icon btn-ghost btn-sm" title="Edit" aria-label="Edit" onclick="editSub(<?= $s['id'] ?>)"><i class="fas fa-pen"></i></button>
            <button class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent)" title="Delete"
                    onclick="deleteSub(<?= $s['id'] ?>)"><i class="fas fa-trash"></i></button>
          </div>
        <?php endforeach; ?>

        <?php if (!empty($upcoming)): ?>
          <div style="margin-top:.875rem">
            <div class="section-label" style="font-size:.6875rem;font-weight:700;color:var(--subtle);text-transform:uppercase;letter-spacing:.08em;margin-bottom:.375rem">
              Upcoming renewals
            </div>
            <?php foreach ($upcoming as $s): ?>
              <div style="display:flex;justify-content:space-between;font-size:.8125rem;padding:.25rem 0">
                <span><strong><?= date('j M', strtotime($s['next_renewal'])) ?></strong> · <?= h($s['name']) ?></span>
                <span style="color:var(--muted)"><?= rupee((float)$s['amount']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Transactions -->
<div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-bottom:.75rem">
  <span style="font-size:.9375rem;font-weight:600">Transactions — <?= $monthLabel ?></span>
  <div class="filter-tabs">
    <?php foreach (['all' => 'All', 'income' => 'Income', 'expense' => 'Expenses'] as $k => $lbl): ?>
      <a class="filter-tab <?= $txFilter === $k ? 'active' : '' ?>" href="?m=<?= $m ?>&t=<?= $k ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="card" id="txListWrap">
  <?php if (empty($transactions)): ?>
    <div class="empty-state">
      <div class="empty-state-icon"><i class="fas fa-wallet"></i></div>
      <div class="empty-state-title">No transactions</div>
      <p>Log your pocket money, freelance income, and expenses.</p>
      <button class="btn btn-primary" style="margin-top:.75rem" onclick="openAddTx()">
        <i class="fas fa-plus"></i> Add transaction
      </button>
    </div>
  <?php else: ?>
    <?php foreach ($transactions as $t): $isInc = $t['type'] === 'income'; ?>
      <div class="todo-row" id="tx-<?= $t['id'] ?>">
        <div style="width:34px;height:34px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;
                    background:<?= $isInc ? 'rgba(34,197,94,.12)' : 'rgba(239,68,68,.1)' ?>;
                    color:<?= $isInc ? 'var(--ok)' : 'var(--accent)' ?>">
          <i class="fas <?= $isInc ? 'fa-arrow-down' : 'fa-arrow-up' ?>" style="font-size:.8125rem"></i>
        </div>
        <div style="flex:1;min-width:0">
          <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
            <span class="todo-title"><?= h($t['title']) ?></span>
            <span class="category-badge"><?= h($t['category']) ?></span>
            <?php if ($t['expense_class']): ?>
              <span class="badge <?= $t['expense_class'] === 'need' ? 'badge-blue' : 'badge-yellow' ?>">
                <?= ucfirst($t['expense_class']) ?>
              </span>
            <?php endif; ?>
          </div>
          <div class="todo-meta" style="margin-top:.125rem">
            <span><?= formatDate($t['tx_date']) ?></span>
            <?php if ($t['notes']): ?><span><?= h($t['notes']) ?></span><?php endif; ?>
          </div>
        </div>
        <strong style="color:<?= $isInc ? 'var(--ok)' : 'var(--text)' ?>;white-space:nowrap">
          <?= $isInc ? '+' : '−' ?><?= rupee((float)$t['amount']) ?>
        </strong>
        <div class="todo-actions">
          <button class="btn btn-icon btn-ghost btn-sm" title="Edit" aria-label="Edit" onclick="editTx(<?= $t['id'] ?>)"><i class="fas fa-pen"></i></button>
          <button class="btn btn-icon btn-ghost btn-sm" style="color:var(--accent)" title="Delete"
                  onclick="deleteTx(<?= $t['id'] ?>)"><i class="fas fa-trash"></i></button>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ── Transaction modal ── -->
<div id="txModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title" id="txModalTitle">New Transaction</span>
      <button class="btn btn-icon btn-ghost btn-sm" data-close-modal="txModal" aria-label="Close dialog">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="txId">

      <div class="form-group">
        <div class="filter-tabs">
          <button type="button" class="filter-tab active" data-tx-type="expense">Expense</button>
          <button type="button" class="filter-tab" data-tx-type="income">Income</button>
        </div>
      </div>

      <div class="form-grid-2">
        <div class="form-group">
          <label for="txTitle" class="form-label">Title <span style="color:var(--accent)">*</span></label>
          <input id="txTitle" class="form-input" placeholder="e.g. Swiggy, Pocket money" maxlength="150">
        </div>
        <div class="form-group">
          <label for="txAmount" class="form-label">Amount (<?= h(trim($curSym)) ?>) <span style="color:var(--accent)">*</span></label>
          <input id="txAmount" class="form-input" type="number" min="1" step="0.01" placeholder="0">
        </div>
      </div>

      <div class="form-grid-2">
        <div class="form-group">
          <label for="txCategory" class="form-label">Category</label>
          <select id="txCategory" class="form-input"></select>
        </div>
        <div class="form-group">
          <label for="txDate" class="form-label">Date</label>
          <input id="txDate" class="form-input" type="date">
        </div>
      </div>

      <div class="form-group" id="txClassWrap">
        <label class="form-label" id="lbl-tx-class">Need or Want?</label>
        <div class="filter-tabs" role="group" aria-labelledby="lbl-tx-class">
          <button type="button" class="filter-tab" data-tx-class="need">🧾 Need</button>
          <button type="button" class="filter-tab active" data-tx-class="want">✨ Want</button>
        </div>
      </div>

      <div class="form-group">
        <label for="txNotes" class="form-label">Notes <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
        <input id="txNotes" class="form-input" maxlength="500">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="txModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveTx()"><i class="fas fa-save"></i> Save</button>
    </div>
  </div>
</div>

<!-- ── Subscription modal ── -->
<div id="subModal" class="modal-backdrop hidden">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title" id="subModalTitle">New Subscription</span>
      <button class="btn btn-icon btn-ghost btn-sm" data-close-modal="subModal" aria-label="Close dialog">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="subId">
      <div class="form-grid-2">
        <div class="form-group">
          <label for="subName" class="form-label">Name <span style="color:var(--accent)">*</span></label>
          <input id="subName" class="form-input" placeholder="e.g. Spotify" maxlength="100">
        </div>
        <div class="form-group">
          <label for="subAmount" class="form-label">Amount (<?= h(trim($curSym)) ?>) <span style="color:var(--accent)">*</span></label>
          <input id="subAmount" class="form-input" type="number" min="1" step="0.01" placeholder="119">
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label for="subCycle" class="form-label">Billing cycle</label>
          <select id="subCycle" class="form-input">
            <option value="monthly">Monthly</option>
            <option value="yearly">Yearly</option>
          </select>
        </div>
        <div class="form-group">
          <label for="subRenewal" class="form-label">Next renewal <span style="color:var(--accent)">*</span></label>
          <input id="subRenewal" class="form-input" type="date">
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="subModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveSub()"><i class="fas fa-save"></i> Save</button>
    </div>
  </div>
</div>

<!-- ── Budget modal ── -->
<div id="budgetModal" class="modal-backdrop hidden">
  <div class="modal-box" style="max-width:380px">
    <div class="modal-header">
      <span class="modal-title">Set Budget</span>
      <button class="btn btn-icon btn-ghost btn-sm" data-close-modal="budgetModal" aria-label="Close dialog">&times;</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label for="budgetCategory" class="form-label">Category</label>
        <select id="budgetCategory" class="form-input">
          <?php foreach ($expenseCats as $c): ?>
            <option value="<?= h($c) ?>"><?= h($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="budgetLimit" class="form-label">Monthly limit (<?= h(trim($curSym)) ?>)</label>
        <input id="budgetLimit" class="form-input" type="number" min="1" placeholder="3000">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary btn-sm" data-close-modal="budgetModal">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveBudget()"><i class="fas fa-save"></i> Save</button>
    </div>
  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const API_BASE     = '<?= APP_BASE ?>/api';
const INCOME_CATS  = <?= json_encode($incomeCats) ?>;
const EXPENSE_CATS = <?= json_encode($expenseCats) ?>;
let txType = 'expense', txClass = 'want';

/* Breakdown donut — reads data-* attrs so it can be re-run after a fragment
   refresh (the canvas element is replaced by fresh server-rendered markup;
   re-reading from PHP-literal closure values would show stale numbers). */
function initBreakdownChart() {
  const el = document.getElementById('breakdownChart');
  if (!el || typeof Chart === 'undefined') return;
  new Chart(el, {
    type: 'doughnut',
    data: {
      labels: ['Needs', 'Wants', 'Savings'],
      datasets: [{
        data: [+el.dataset.needs, +el.dataset.wants, +el.dataset.savings],
        backgroundColor: ['#3b82f6', '#f59e0b', '#22c55e'],
        borderWidth: 0,
      }],
    },
    options: {
      cutout: '68%',
      plugins: { legend: { display: false } },
    },
  });
}
initBreakdownChart();

/* Type / class tabs */
document.querySelectorAll('[data-tx-type]').forEach(b =>
  b.addEventListener('click', () => setTxType(b.dataset.txType)));
document.querySelectorAll('[data-tx-class]').forEach(b =>
  b.addEventListener('click', () => setTxClass(b.dataset.txClass)));

function setTxType(t) {
  txType = t;
  document.querySelectorAll('[data-tx-type]').forEach(b =>
    b.classList.toggle('active', b.dataset.txType === t));
  document.getElementById('txClassWrap').classList.toggle('hidden', t !== 'expense');
  fillCategories(t === 'income' ? INCOME_CATS : EXPENSE_CATS);
}

function setTxClass(c) {
  txClass = c;
  document.querySelectorAll('[data-tx-class]').forEach(b =>
    b.classList.toggle('active', b.dataset.txClass === c));
}

function fillCategories(list, selected) {
  const sel = document.getElementById('txCategory');
  sel.innerHTML = list.map(c => `<option value="${c}">${c}</option>`).join('');
  if (selected && list.includes(selected)) sel.value = selected;
}

function openAddTx() {
  document.getElementById('txId').value     = '';
  document.getElementById('txTitle').value  = '';
  document.getElementById('txAmount').value = '';
  document.getElementById('txDate').value   = '<?= date('Y-m-d') ?>';
  document.getElementById('txNotes').value  = '';
  document.getElementById('txModalTitle').textContent = 'New Transaction';
  setTxType('expense'); setTxClass('want');
  Trackie.openModal('txModal');
}

async function editTx(id) {
  try {
    const res = await Trackie.API.post(`${API_BASE}/finance.php`, { action: 'get_tx', tx_id: id });
    if (!res.success) { Trackie.Toast.error('Could not load.'); return; }
    const t = res.tx;
    document.getElementById('txId').value     = t.id;
    document.getElementById('txTitle').value  = t.title;
    document.getElementById('txAmount').value = t.amount;
    document.getElementById('txDate').value   = t.tx_date;
    document.getElementById('txNotes').value  = t.notes || '';
    document.getElementById('txModalTitle').textContent = 'Edit Transaction';
    setTxType(t.type);
    fillCategories(t.type === 'income' ? INCOME_CATS : EXPENSE_CATS, t.category);
    setTxClass(t.expense_class || 'want');
    Trackie.openModal('txModal');
  } catch { Trackie.Toast.error('Network error.'); }
}

async function saveTx() {
  const id     = document.getElementById('txId').value;
  const title  = document.getElementById('txTitle').value.trim();
  const amount = document.getElementById('txAmount').value;
  if (!title)       { Trackie.Toast.warning('Title is required.'); return; }
  if (!amount || amount <= 0) { Trackie.Toast.warning('Enter a valid amount.'); return; }

  const payload = {
    action: id ? 'edit_tx' : 'add_tx',
    tx_id:  id || '',
    type:   txType, title, amount,
    category:      document.getElementById('txCategory').value,
    expense_class: txClass,
    tx_date:       document.getElementById('txDate').value,
    notes:         document.getElementById('txNotes').value,
  };
  try {
    const res = await Trackie.API.post(`${API_BASE}/finance.php`, payload);
    if (res.success) {
      Trackie.Toast.success('Saved.');
      Trackie.closeModal('txModal');
      await Trackie.refreshFragments(['financeStatsWrap', 'breakdownCard', 'budgetsCard', 'txListWrap']);
      initBreakdownChart();
    }
    else Trackie.Toast.error(res.error || 'Save failed.');
  } catch { Trackie.Toast.error('Network error.'); }
}

async function deleteTx(id) {
  const ok = await Trackie.confirmDialog('Delete this transaction?', { confirmText: 'Delete', danger: true });
  if (!ok) return;
  const res = await Trackie.API.post(`${API_BASE}/finance.php`, { action: 'delete_tx', tx_id: id });
  if (res.success) {
    Trackie.Toast.success('Deleted.');
    await Trackie.refreshFragments(['financeStatsWrap', 'breakdownCard', 'budgetsCard', 'txListWrap']);
    initBreakdownChart();
  }
}

/* Subscriptions */
function openAddSub() {
  document.getElementById('subId').value      = '';
  document.getElementById('subName').value    = '';
  document.getElementById('subAmount').value  = '';
  document.getElementById('subCycle').value   = 'monthly';
  document.getElementById('subRenewal').value = '';
  document.getElementById('subModalTitle').textContent = 'New Subscription';
  Trackie.openModal('subModal');
}

async function editSub(id) {
  const res = await Trackie.API.post(`${API_BASE}/finance.php`, { action: 'get_sub', sub_id: id });
  if (!res.success) { Trackie.Toast.error('Could not load.'); return; }
  const s = res.sub;
  document.getElementById('subId').value      = s.id;
  document.getElementById('subName').value    = s.name;
  document.getElementById('subAmount').value  = s.amount;
  document.getElementById('subCycle').value   = s.billing_cycle;
  document.getElementById('subRenewal').value = s.next_renewal;
  document.getElementById('subModalTitle').textContent = 'Edit Subscription';
  Trackie.openModal('subModal');
}

async function saveSub() {
  const id = document.getElementById('subId').value;
  const payload = {
    action: id ? 'edit_sub' : 'add_sub',
    sub_id: id || '',
    name:          document.getElementById('subName').value.trim(),
    amount:        document.getElementById('subAmount').value,
    billing_cycle: document.getElementById('subCycle').value,
    next_renewal:  document.getElementById('subRenewal').value,
  };
  if (!payload.name)   { Trackie.Toast.warning('Name is required.'); return; }
  if (!payload.amount) { Trackie.Toast.warning('Enter the amount.'); return; }
  if (!payload.next_renewal) { Trackie.Toast.warning('Pick the next renewal date.'); return; }
  const res = await Trackie.API.post(`${API_BASE}/finance.php`, payload);
  if (res.success) {
    Trackie.Toast.success('Saved.');
    Trackie.closeModal('subModal');
    await Trackie.refreshFragments(['subsCard']);
  }
  else Trackie.Toast.error(res.error || 'Save failed.');
}

async function toggleSub(id) {
  const res = await Trackie.API.post(`${API_BASE}/finance.php`, { action: 'toggle_sub', sub_id: id });
  if (res.success) await Trackie.refreshFragments(['subsCard']);
}

async function deleteSub(id) {
  const ok = await Trackie.confirmDialog('Delete this subscription?', { confirmText: 'Delete', danger: true });
  if (!ok) return;
  const res = await Trackie.API.post(`${API_BASE}/finance.php`, { action: 'delete_sub', sub_id: id });
  if (res.success) { document.getElementById(`sub-${id}`)?.remove(); Trackie.Toast.success('Deleted.'); }
}

/* Budgets */
async function saveBudget() {
  const payload = {
    action:        'set_budget',
    category:      document.getElementById('budgetCategory').value,
    monthly_limit: document.getElementById('budgetLimit').value,
  };
  if (!payload.monthly_limit || payload.monthly_limit <= 0) { Trackie.Toast.warning('Enter a valid limit.'); return; }
  const res = await Trackie.API.post(`${API_BASE}/finance.php`, payload);
  if (res.success) {
    Trackie.Toast.success('Budget saved.');
    Trackie.closeModal('budgetModal');
    await Trackie.refreshFragments(['budgetsCard']);
  }
  else Trackie.Toast.error(res.error || 'Save failed.');
}

async function deleteBudget(id) {
  const ok = await Trackie.confirmDialog('Remove this budget?', { confirmText: 'Remove', danger: true });
  if (!ok) return;
  const res = await Trackie.API.post(`${API_BASE}/finance.php`, { action: 'delete_budget', budget_id: id });
  if (res.success) { document.getElementById(`budget-${id}`)?.remove(); Trackie.Toast.success('Removed.'); }
}
</script>

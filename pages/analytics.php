<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
if (is_file(__DIR__ . '/../includes/gamification.php')) require_once __DIR__ . '/../includes/gamification.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Analytics';
$currentPage = 'analytics';
$score       = function_exists('trackieScore') ? trackieScore($uid) : ['score'=>0,'band'=>'','has_data'=>false];

// ── Habits ────────────────────────────────────────────────────
$habitStats = fetchOne(
    "SELECT COUNT(*) total,
            SUM(frequency='daily')  daily,
            SUM(frequency='weekly') weekly
     FROM habits WHERE user_id=?",
    [$uid]
);

// All log dates for streak calc
$logDates = array_column(
    fetchAll("SELECT DISTINCT l.date_completed FROM logs l JOIN habits h ON h.id=l.habit_id WHERE h.user_id=?", [$uid]),
    'date_completed'
);
$streaks = calculateStreaks($logDates, userNeutralDates($uid));

// Last-7-days daily completions (for chart)
$completionRows = fetchAll(
    "SELECT DATE(date_completed) d, COUNT(*) cnt
     FROM logs l JOIN habits h ON h.id=l.habit_id
     WHERE h.user_id=? AND date_completed >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY d ORDER BY d",
    [$uid]
);
$byDate = [];
foreach ($completionRows as $r) $byDate[$r['d']] = (int)$r['cnt'];

$chartLabels = [];
$chartData   = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[] = date('D j', strtotime($d));
    $chartData[]   = $byDate[$d] ?? 0;
}

// Previous 7 days (for the week-over-week insight sentence)
$prevWeekCnt = (int)fetchOne(
    "SELECT COUNT(*) c FROM logs l JOIN habits h ON h.id=l.habit_id
     WHERE h.user_id=? AND date_completed >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
       AND date_completed <  DATE_SUB(CURDATE(), INTERVAL 6 DAY)",
    [$uid]
)['c'];
$thisWeekCnt = array_sum($chartData);

// ── Insight sentence (rule-based, plain language) ───────────────
$habitInsight = null;
if ($thisWeekCnt > 0) {
    $bestIdx = array_keys($chartData, max($chartData))[0];
    $bestDay = date('l', strtotime(date('Y-m-d', strtotime("-" . (6 - $bestIdx) . " days"))));
    if ($prevWeekCnt > 0) {
        $delta = round(($thisWeekCnt - $prevWeekCnt) / $prevWeekCnt * 100);
        if ($delta > 0)      $habitInsight = "You logged habits most on {$bestDay}s — up {$delta}% vs last week. Keep it going!";
        elseif ($delta < 0)  $habitInsight = "You logged habits most on {$bestDay}s — down " . abs($delta) . "% vs last week.";
        else                 $habitInsight = "You logged habits most on {$bestDay}s — same pace as last week.";
    } else {
        $habitInsight = "You logged habits most on {$bestDay}s this week.";
    }
} elseif ($prevWeekCnt > 0) {
    $habitInsight = "No habit logs this week yet — last week you logged {$prevWeekCnt}.";
}

// Top habits
$topHabits = fetchAll(
    "SELECT h.name, COUNT(l.id) cnt FROM habits h
     LEFT JOIN logs l ON l.habit_id=h.id
     WHERE h.user_id=? GROUP BY h.id ORDER BY cnt DESC LIMIT 5",
    [$uid]
);

// ── Todos ─────────────────────────────────────────────────────
$todoStats = fetchOne(
    "SELECT COUNT(*) total,
            SUM(completed=1) done,
            SUM(completed=0) pending,
            SUM(completed=0 AND due_date < CURDATE()) overdue
     FROM todos WHERE user_id=? AND deleted_at IS NULL",
    [$uid]
);
$todoPct = ($todoStats['total'] ?? 0) > 0
    ? round($todoStats['done'] / $todoStats['total'] * 100) : 0;

// ── Goals ─────────────────────────────────────────────────────
$goalStats = fetchOne(
    "SELECT COUNT(*) total,
            SUM(progress>=target_value) completed,
            ROUND(AVG(CASE WHEN target_value>0 THEN (progress/target_value)*100 ELSE 0 END),1) avg_pct
     FROM goals WHERE user_id=?",
    [$uid]
);

// ── Study Plan ────────────────────────────────────────────────
$studyStats = fetchOne(
    "SELECT COUNT(*) total, SUM(completed=1) done FROM study_plan WHERE user_id=?",
    [$uid]
);
$studyPct = ($studyStats['total'] ?? 0) > 0
    ? round($studyStats['done'] / $studyStats['total'] * 100) : 0;

// ── Routine stats ─────────────────────────────────────────────
$routineStats = fetchOne(
    "SELECT COUNT(*) total,
            SUM(category='Fitness') fitness,
            SUM(category='Work')    work,
            SUM(category='Study')   study,
            SUM(category='Personal') personal,
            SUM(category='Health')  health
     FROM routines WHERE user_id=?",
    [$uid]
);

// ── 12-week heatmap data ─────────────────────────────────────
$heatmapRows = fetchAll(
    "SELECT DATE(date_completed) d, COUNT(*) cnt
     FROM logs l JOIN habits h ON h.id=l.habit_id
     WHERE h.user_id=? AND date_completed >= DATE_SUB(CURDATE(), INTERVAL 83 DAY)
     GROUP BY d",
    [$uid]
);
$heatmap = [];
foreach ($heatmapRows as $r) $heatmap[$r['d']] = (int)$r['cnt'];
$maxHeat = $heatmap ? max(array_values($heatmap)) : 1;

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<?= renderPageHeader('Analytics', [
  'icon' => 'fa-chart-bar',
  'sub'  => 'Trends, patterns, and insights across your activity.',
]) ?>

<!-- Trackie Score banner -->
<?php
$scCol = $score['score'] >= 75 ? 'var(--ok)' : ($score['score'] >= 50 ? '#f59e0b' : 'var(--accent)');
?>
<div class="card card-body" style="margin-bottom:1.25rem;display:flex;align-items:center;gap:1.25rem;flex-wrap:wrap">
  <div style="width:84px;height:84px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;
              background:conic-gradient(<?= $scCol ?> <?= (int)$score['score'] * 3.6 ?>deg, var(--border) 0);">
    <div style="width:66px;height:66px;border-radius:50%;background:var(--surface);display:flex;align-items:center;justify-content:center;
                font-size:1.5rem;font-weight:800;color:<?= $score['has_data'] ? $scCol : 'var(--muted)' ?>">
      <?= $score['has_data'] ? (int)$score['score'] : '—' ?>
    </div>
  </div>
  <div style="flex:1;min-width:160px">
    <div style="font-size:1.0625rem;font-weight:700;color:var(--text)">Trackie Score<?= $score['has_data'] ? ' · '.h($score['band']) : '' ?></div>
    <div style="font-size:.8125rem;color:var(--muted);margin-top:.25rem">
      <?= $score['has_data']
          ? 'Your daily productivity index from todos, habits, streak, focus &amp; goals.'
          : 'Log habits, complete todos or run a focus session to generate your score.' ?>
    </div>
  </div>
</div>

<!-- Overview cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:1.5rem">
  <?php
  $oc = [
    ['l'=>'Habits',         'v'=>$habitStats['total']   ?? 0, 'i'=>'fa-heart',         'c'=>'#8b5cf6'],
    ['l'=>'Current streak', 'v'=>$streaks['current'].' d','i'=>'fa-fire',            'c'=>'#f59e0b'],
    ['l'=>'Best streak',    'v'=>$streaks['best'].' d',  'i'=>'fa-trophy',           'c'=>'#eab308'],
    ['l'=>'Todo rate',      'v'=>$todoPct.'%',            'i'=>'fa-check-square',     'c'=>'#22c55e'],
    ['l'=>'Goal avg',       'v'=>($goalStats['avg_pct'] ?? 0).'%', 'i'=>'fa-bullseye','c'=>'#3b82f6'],
    ['l'=>'Study done',     'v'=>$studyPct.'%',           'i'=>'fa-book-open',        'c'=>'#ef4444'],
  ];
  foreach ($oc as $s): ?>
    <div class="stat-card">
      <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.375rem">
        <i class="fas <?= $s['i'] ?>" style="color:<?= $s['c'] ?>;font-size:1rem"></i>
        <span class="stat-label"><?= $s['l'] ?></span>
      </div>
      <div class="stat-val" style="font-size:1.5rem;color:<?= $s['c'] ?>"><?= $s['v'] ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="grid-2" style="margin-bottom:1.5rem">

  <!-- Habit chart -->
  <div class="card card-body">
    <div style="font-size:.9375rem;font-weight:600;margin-bottom:.375rem">Habit completions — last 7 days</div>
    <?php if ($habitInsight): ?>
      <div style="font-size:.8125rem;color:var(--accent);margin-bottom:.875rem;display:flex;align-items:center;gap:.375rem">
        <i class="fas fa-lightbulb" style="font-size:.75rem"></i> <?= h($habitInsight) ?>
      </div>
    <?php endif; ?>
    <?php if (array_sum($chartData) === 0): ?>
      <div class="empty-state">
        <div class="empty-state-icon"><i class="fas fa-chart-line"></i></div>
        <p>Start logging habits to see your chart.</p>
      </div>
    <?php else: ?>
      <div class="chart-wrap"><canvas id="habitChart"></canvas></div>
    <?php endif; ?>
  </div>

  <!-- Top habits -->
  <div class="card card-body">
    <div style="font-size:.9375rem;font-weight:600;margin-bottom:1rem">Top habits (all time)</div>
    <?php if (empty($topHabits) || $topHabits[0]['cnt'] == 0): ?>
      <div class="empty-state"><p style="color:var(--muted)">No logs yet.</p></div>
    <?php else:
      $maxCnt = max(array_column($topHabits, 'cnt'));
      foreach ($topHabits as $i => $h): $pct = $maxCnt > 0 ? round($h['cnt'] / $maxCnt * 100) : 0; ?>
        <div style="margin-bottom:.875rem">
          <div style="display:flex;justify-content:space-between;font-size:.875rem;margin-bottom:.25rem">
            <span style="font-weight:500;color:var(--text)"><?= h($h['name']) ?></span>
            <span style="color:var(--muted)"><?= $h['cnt'] ?> logs</span>
          </div>
          <div class="progress-track" style="height:.4rem">
            <div class="progress-fill" style="width:<?= $pct ?>%"></div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

<!-- Progress bars row -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem">
  <?php
  $bars = [
    ['l'=>'Todos completed','v'=>$todoPct,'ok'=>$todoPct>=80],
    ['l'=>'Goals average',  'v'=>($goalStats['avg_pct']??0),'ok'=>($goalStats['avg_pct']??0)>=75],
    ['l'=>'Study tasks done','v'=>$studyPct,'ok'=>$studyPct>=80],
  ];
  foreach ($bars as $b): ?>
    <div class="card card-body">
      <div style="display:flex;justify-content:space-between;font-size:.875rem;margin-bottom:.5rem">
        <span style="color:var(--muted)"><?= $b['l'] ?></span>
        <strong><?= $b['v'] ?>%</strong>
      </div>
      <div class="progress-track">
        <div class="progress-fill <?= $b['ok']?'green':'' ?>" style="width:<?= $b['v'] ?>%"></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- 12-week habit heatmap -->
<div class="card card-body" style="margin-bottom:1.5rem">
  <div style="font-size:.9375rem;font-weight:600;margin-bottom:1rem">12-week habit heatmap</div>
  <?php
  // Start from the Sunday 83 days ago
  $start = strtotime('last sunday', strtotime('-82 days'));
  if (date('N', strtotime('-82 days')) == 7) $start = strtotime('-82 days');
  ?>
  <div class="heatmap-scroll">
  <div style="display:grid;grid-template-columns:repeat(12,18px);gap:3px;width:max-content">
    <?php for ($w = 0; $w < 12; $w++): ?>
      <div style="display:flex;flex-direction:column;gap:3px">
        <?php for ($d = 0; $d < 7; $d++):
          $ts   = strtotime("+{$d} days", $start + $w * 7 * 86400);
          $date = date('Y-m-d', $ts);
          if ($ts > time()) { echo '<div style="width:18px;height:18px"></div>'; continue; }
          $cnt   = $heatmap[$date] ?? 0;
          $level = $cnt === 0 ? 0 : ($cnt <= 1 ? 1 : ($cnt <= 3 ? 2 : ($cnt <= 5 ? 3 : 4)));
          $title = $date . ($cnt ? ": {$cnt} log" . ($cnt > 1 ? 's' : '') : ': no logs');
        ?>
          <div class="heatmap-cell" data-level="<?= $level ?>" title="<?= $title ?>"></div>
        <?php endfor; ?>
      </div>
    <?php endfor; ?>
  </div>
  </div><!-- /.heatmap-scroll -->
  <div style="display:flex;align-items:center;gap:.375rem;margin-top:.75rem;font-size:.75rem;color:var(--muted)">
    Less
    <?php for ($l = 0; $l <= 4; $l++): ?>
      <div class="heatmap-cell" data-level="<?= $l ?>" style="flex-shrink:0"></div>
    <?php endfor; ?>
    More
  </div>
</div>

<!-- Routines by category -->
<?php if (($routineStats['total'] ?? 0) > 0): ?>
<div class="card card-body">
  <div style="font-size:.9375rem;font-weight:600;margin-bottom:1rem">Routines by category</div>
  <div style="display:flex;flex-direction:column;gap:.625rem">
    <?php
    $catData = [
      'Fitness'  => ['cnt'=>$routineStats['fitness']  ?? 0,'c'=>'#ef4444'],
      'Work'     => ['cnt'=>$routineStats['work']     ?? 0,'c'=>'#3b82f6'],
      'Study'    => ['cnt'=>$routineStats['study']    ?? 0,'c'=>'#8b5cf6'],
      'Personal' => ['cnt'=>$routineStats['personal'] ?? 0,'c'=>'#f59e0b'],
      'Health'   => ['cnt'=>$routineStats['health']   ?? 0,'c'=>'#22c55e'],
    ];
    $maxCat = max(array_column($catData, 'cnt')) ?: 1;
    foreach ($catData as $cat => $data):
      if ($data['cnt'] === 0) continue;
      $pct = round($data['cnt'] / $maxCat * 100);
    ?>
      <div style="display:flex;align-items:center;gap:.75rem">
        <span style="width:70px;font-size:.875rem;color:var(--muted)"><?= $cat ?></span>
        <div class="progress-track" style="flex:1">
          <div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $data['c'] ?>"></div>
        </div>
        <span style="width:20px;text-align:right;font-size:.875rem;font-weight:600"><?= $data['cnt'] ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

</div>
<?php include '../includes/footer.php'; ?>

<?php if (array_sum($chartData) > 0): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const ctx = document.getElementById('habitChart').getContext('2d');
new Chart(ctx, {
  type: 'bar',
  data: {
    labels: <?= json_encode($chartLabels) ?>,
    datasets: [{
      label: 'Completions',
      data:  <?= json_encode($chartData) ?>,
      backgroundColor: 'rgba(239,68,68,.8)',
      borderRadius: 4,
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { stepSize: 1 } }
    }
  }
});
</script>
<?php endif; ?>

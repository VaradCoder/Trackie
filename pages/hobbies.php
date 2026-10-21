<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/activity.php';
require_once '../includes/dashboard_sections.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Hobbies';
$currentPage = 'hobbies';
$today       = date('Y-m-d');

$picked = array_values(array_filter(array_map('trim', explode(',', (string)(fetchOne("SELECT hobbies FROM users WHERE id=?", [$uid])['hobbies'] ?? '')))));
$meta = allHobbiesMeta();

/* ════════════════════════════════════════════════════════════════
   Hobbies hub
     · a live card per picked hobby — the same numbers as the dashboard
       tiles (dashboardSections), plus best streak and last activity
     · the last 14 days of hobby activity, stacked by hobby (Chart.js)
     · an inline picker: tap a hobby to add/remove it, saved at once
   ════════════════════════════════════════════════════════════════ */
$space = dashboardSections($uid, [], $today);
$byHref = [];
foreach (array_merge($space['sections']['life']['tiles'], $space['sections']['hobbies']['tiles']) as $t) $byHref[$t['href']] = $t;
$cards = [];
foreach ($picked as $hobby) {
    if (!isset($meta[$hobby], $byHref[$meta[$hobby]['module']])) continue;
    $cards[] = ['hobby' => $hobby, 'm' => $meta[$hobby], 't' => $byHref[$meta[$hobby]['module']]];
}

// 14 days of activity per picked hobby (activity_log), for the stacked chart.
$days = [];
for ($i = 13; $i >= 0; $i--) $days[] = date('Y-m-d', strtotime("-$i day"));
$series = []; $total = 0; $activeHobbies = 0;
if ($cards && activityReady()) {
    $mods = array_values(array_filter(array_map(static fn($c) => $c['t']['module'], $cards)));
    $rows = $mods ? fetchAll("SELECT module, occurred_on d, COUNT(*) n FROM activity_log WHERE user_id=? AND occurred_on >= ? AND module IN (" . implode(',', array_fill(0, count($mods), '?')) . ") GROUP BY module, occurred_on",
        array_merge([$uid, $days[0]], $mods)) : [];
    $by = [];
    foreach ($rows as $r) $by[$r['module']][$r['d']] = (int)$r['n'];
    foreach ($cards as $c) {
        $mod = $c['t']['module'];
        $vals = array_map(static fn($d) => (int)($by[$mod][$d] ?? 0), $days);
        $series[] = ['label' => $c['hobby'], 'color' => $c['m']['color'], 'data' => $vals];
        $total += array_sum(array_slice($vals, 7));
        if (array_sum(array_slice($vals, 7))) $activeHobbies++;
    }
}
$chart = ['labels' => array_map(static fn($d) => date('D j', strtotime($d)), $days), 'series' => $series];

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<?= renderPageHeader('Your Hobbies', [
  'icon' => 'fa-star',
  'sub'  => 'The things you love doing — each one you pick gets its own tracker, dashboard tile and sidebar link.',
]) ?>

<div id="hobbiesListWrap">
<?php if ($cards): ?>
  <p class="pg-strip">
    <span><strong><?= $total ?></strong> <?= $total === 1 ? 'entry' : 'entries' ?> this week</span>
    <span><strong><?= $activeHobbies ?></strong> of <?= count($cards) ?> hobbies active</span>
    <?php $top = $cards; usort($top, static fn($a, $b) => $b['t']['streak'] <=> $a['t']['streak']); if ($top[0]['t']['streak'] > 1): ?>
      <span>Longest streak: <strong><?= h($top[0]['hobby']) ?> · <?= $top[0]['t']['streak'] ?> days</strong></span>
    <?php endif; ?>
  </p>

  <div class="hh-grid">
    <?php foreach ($cards as $i => $c): $t = $c['t']; $max = $t['spark'] ? max(1, max($t['spark'])) : 1; ?>
    <a class="hh-card" href="<?= APP_BASE ?>/pages/<?= h($c['m']['module']) ?>" data-hobby="<?= h($c['hobby']) ?>" style="--c:<?= h($c['m']['color']) ?>;--i:<?= $i ?>">
      <span class="hh-top">
        <span class="hh-ic" aria-hidden="true"><i class="fas <?= h($c['m']['icon']) ?>"></i></span>
        <span class="hh-name"><strong><?= h($c['hobby']) ?></strong><small><?= h($c['m']['moduleLabel']) ?></small></span>
        <span class="hh-streak<?= $t['streak'] > 0 ? ' is-on' : '' ?>" title="Best: <?= $t['best'] ?> days">
          <i class="fas fa-fire" aria-hidden="true"></i><?= $t['streak'] ?><span class="sr-only">-day streak</span>
        </span>
      </span>
      <span class="hh-value"><strong><?= h((string)$t['value']) ?></strong> <small><?= h($t['unit']) ?></small></span>
      <span class="hh-sub"><?= h($t['sub'] ?: 'Nothing logged yet') ?></span>
      <?php if ($t['spark'] !== null): ?>
      <span class="ys-spark hh-spark" role="img" aria-label="<?= array_sum($t['spark']) ?> entries in the last 7 days">
        <?php foreach ($t['spark'] as $v): ?><i style="--h:<?= $v ? max(14, (int)round($v / $max * 100)) : 0 ?>%"></i><?php endforeach; ?>
      </span>
      <?php endif; ?>
      <span class="hh-foot">
        <span><?= $t['last'] ? 'Last active ' . h($t['last']) : 'Start your first session' ?><?= $t['best'] > $t['streak'] ? ' · best ' . $t['best'] . 'd' : '' ?></span>
        <span class="hh-go" aria-hidden="true">Open <i class="fas fa-arrow-right"></i></span>
      </span>
    </a>
    <?php endforeach; ?>
  </div>

  <section class="hh-chart-card" aria-labelledby="hhChartTitle">
    <header class="hh-chart-head">
      <h2 id="hhChartTitle">Last 14 days</h2>
      <span class="u-c-muted u-fs-13">Sessions and entries per hobby</span>
    </header>
    <?php if ($total || array_sum(array_map(static fn($s) => array_sum($s['data']), $series))): ?>
      <div class="hh-chart"><canvas id="hhChart" role="img" aria-label="Hobby activity over the last 14 days"></canvas></div>
    <?php else: ?>
      <p class="u-c-muted u-fs-14 u-m-0">Nothing logged in the last two weeks — open a hobby above and log a session; it shows up here.</p>
    <?php endif; ?>
    <script type="application/json" id="hhChartData"><?= json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  </section>
<?php else: ?>
  <div class="hh-empty">
    <span class="hh-empty-ic" aria-hidden="true"><i class="fas fa-star"></i></span>
    <h2>Pick what you love doing</h2>
    <p>Each hobby you pick below gets its own tracker, a tile on your dashboard and a link in the sidebar.</p>
  </div>
<?php endif; ?>
</div>

<!-- Inline picker: toggles save immediately -->
<section class="hh-pick" aria-labelledby="hhPickTitle">
  <header class="hh-chart-head">
    <h2 id="hhPickTitle">Choose your hobbies</h2>
    <span class="u-c-muted u-fs-13" id="hhPickCount"><?= count($picked) ?> of 8 picked</span>
  </header>
  <?php foreach (['health' => 'Body & mind — shown under Life', 'lifestyle' => 'Lifestyle — shown under Hobbies'] as $grp => $label): ?>
  <h3 class="hh-pick-label"><?= $label ?></h3>
  <div class="hh-pick-grid" role="group" aria-label="<?= h($label) ?>">
    <?php foreach ($meta as $name => $hm): if (($hm['navGroup'] ?? 'lifestyle') !== $grp) continue; $on = in_array($name, $picked, true); ?>
    <button type="button" class="hh-opt" data-hobby="<?= h($name) ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>" style="--c:<?= h($hm['color']) ?>">
      <span class="hh-ic" aria-hidden="true"><i class="fas <?= h($hm['icon']) ?>"></i></span>
      <span class="hh-opt-text"><strong><?= h($name) ?></strong><small><?= h($hm['desc']) ?></small></span>
      <span class="hh-opt-check" aria-hidden="true"><i class="fas fa-check"></i></span>
    </button>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
</section>

</div>
<?php include '../includes/footer.php'; ?>

<script>
(() => {
  const CHART_SRC = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js';
  const CHART_SRI = 'sha384-bs/nf9FbdNouRbMiFcrcZfLXYPKiPaGVGplVbv7dLGECccEXDW+S3zjqSKR5ZEaD';
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  let chart = null;

  const loadChart = () => window.Chart ? Promise.resolve(window.Chart) : new Promise((ok, fail) => {
    const s = document.createElement('script');
    s.src = CHART_SRC; s.integrity = CHART_SRI; s.crossOrigin = 'anonymous';
    s.onload = () => ok(window.Chart); s.onerror = fail;
    document.head.appendChild(s);
  });

  async function drawChart() {
    const canvas = document.getElementById('hhChart');
    const raw = document.getElementById('hhChartData');
    if (chart) { chart.destroy(); chart = null; }
    if (!canvas || !raw) return;
    let Chart;
    try { Chart = await loadChart(); } catch { canvas.closest('.hh-chart').hidden = true; return; }
    const d = JSON.parse(raw.textContent);
    const css = getComputedStyle(document.documentElement);
    const muted = css.getPropertyValue('--muted').trim() || '#888';
    const grid = css.getPropertyValue('--border').trim() || 'rgba(0,0,0,.1)';
    chart = new Chart(canvas, {
      type: 'bar',
      data: { labels: d.labels, datasets: d.series.map(s => ({ label: s.label, data: s.data, backgroundColor: s.color, borderRadius: 4, borderSkipped: false, maxBarThickness: 26 })) },
      options: {
        responsive: true, maintainAspectRatio: false, animation: reduce ? false : { duration: 700, easing: 'easeOutQuart' },
        interaction: { mode: 'index', intersect: false },
        scales: {
          x: { stacked: true, grid: { display: false }, ticks: { color: muted, maxRotation: 0, autoSkip: true, autoSkipPadding: 8 } },
          y: { stacked: true, beginAtZero: true, grid: { color: grid }, border: { display: false }, ticks: { color: muted, precision: 0 } },
        },
        plugins: { legend: { position: 'bottom', labels: { color: muted, usePointStyle: true, pointStyle: 'rectRounded', boxWidth: 10 } },
                   tooltip: { filter: i => i.raw > 0 } },
      },
    });
  }
  drawChart();

  // Picker: optimistic toggle, saved straight away; the cards, chart and sidebar refresh.
  const pick = document.querySelector('.hh-pick');
  const count = document.getElementById('hhPickCount');
  let saving = Promise.resolve();
  pick?.addEventListener('click', e => {
    const btn = e.target.closest('.hh-opt'); if (!btn) return;
    const on = btn.getAttribute('aria-pressed') === 'true';
    const chosen = () => [...pick.querySelectorAll('.hh-opt[aria-pressed="true"]')].map(b => b.dataset.hobby);
    if (!on && chosen().length >= 8) { Trackie.Toast.warning('Pick up to 8 hobbies.'); return; }
    btn.setAttribute('aria-pressed', on ? 'false' : 'true');
    if (!on) Trackie.Delight?.tap?.(btn);
    const list = chosen();
    count.textContent = `${list.length} of 8 picked`;
    saving = saving.then(async () => {
      try {
        const res = await Trackie.API.post('<?= APP_BASE ?>/api/profile.php', { action: 'save_hobbies', hobbies: list.join(',') });
        if (!res.success) throw new Error(res.error || 'Could not save.');
        await Trackie.refreshFragments(['hobbiesListWrap', 'sidebarNav']);
        drawChart();
      } catch (err) {
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        count.textContent = `${chosen().length} of 8 picked`;
        Trackie.UI?.toastError ? Trackie.UI.toastError(err) : Trackie.Toast.error('Could not save your hobbies.');
      }
    });
  });
})();
</script>

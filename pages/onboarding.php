<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid  = currentUserId();
$user = fetchOne("SELECT name, hobbies, onboarding_completed_at FROM users WHERE id=?", [$uid]);

// Already onboarded → dashboard, unless the user chose to redo setup
// (Settings → Getting started links here with ?again=1).
$again = isset($_GET['again']);
if ($user['onboarding_completed_at'] && !$again) {
    redirect(APP_BASE . '/pages/dashboard.php');
}
// A redo starts from what the user already has, so finishing it never
// silently drops hobbies they had picked before.
$currentHobbies = array_filter(array_map('trim', explode(',', (string)($user['hobbies'] ?? ''))));

$pageTitle  = 'Welcome';
$firstName  = trim(explode(' ', $user['name'] ?? '')[0] ?? 'there');
$allHobbies = allHobbiesMeta();

require_once '../includes/head.php';
?>
<style>body{overflow:hidden auto}</style>

<div class="auth-wrap">
  <div style="width:100%;max-width:560px">

    <div class="text-center" style="margin-bottom:1.5rem">
      <div class="auth-logo-ring" style="overflow:hidden;padding:0;background:none">
        <img src="<?= APP_BASE ?>/assets/images/logo.png" alt="Trackie" style="width:56px;height:56px;border-radius:50%;object-fit:cover">
      </div>
      <h1 style="font-size:1.5rem;font-weight:800;color:var(--text);margin:.5rem 0 0">Trackie</h1>
    </div>

    <div class="auth-card" style="padding:2rem;position:relative">
      <?php if ($again): ?>
        <a href="<?= APP_BASE ?>/pages/settings.php" class="ob-skip">Cancel</a>
      <?php else: ?>
        <button type="button" class="ob-skip" onclick="obSkipAll()">Skip setup</button>
      <?php endif; ?>

      <!-- Step dots -->
      <div id="obDots" style="display:flex;justify-content:center;gap:.4rem;margin-bottom:1.5rem">
        <span class="ob-dot is-active" data-dot="1"></span>
        <span class="ob-dot" data-dot="2"></span>
        <span class="ob-dot" data-dot="3"></span>
        <span class="ob-dot" data-dot="4"></span>
        <span class="ob-dot" data-dot="5"></span>
        <span class="ob-dot" data-dot="6"></span>
      </div>

      <!-- Step 1: Welcome -->
      <div class="ob-step" id="obStep1">
        <div style="text-align:center">
          <div style="font-size:2rem;margin-bottom:.5rem" aria-hidden="true">👋</div>
          <h2 style="font-size:1.25rem;font-weight:700;margin:0 0 .5rem">Welcome, <?= h($firstName) ?></h2>
          <p style="color:var(--muted);font-size:.9375rem;max-width:38ch;margin:0 auto 1.75rem">
            Trackie tracks your habits, tasks, focus, finances — and the hobbies you actually care about, all in one place.
            Let's set it up around you. Takes about a minute.
          </p>
          <button class="btn btn-primary" style="width:100%;justify-content:center" onclick="obGoto(2)">
            Get started <i class="fas fa-arrow-right"></i>
          </button>
        </div>
      </div>

      <!-- Step 2: What are you here to change? (drives dashboard emphasis) -->
      <div class="ob-step hidden" id="obStep2">
        <h2 style="font-size:1.125rem;font-weight:700;margin:0 0 .375rem">What matters most right now?</h2>
        <p style="color:var(--muted);font-size:.875rem;margin:0 0 1.25rem">
          Pick one. Trackie leads with this on your dashboard — you can change it any time.
        </p>
        <div class="ob-choice-grid" id="obFocusChoices" role="radiogroup" aria-label="Primary focus">
          <?php
          $focusOpts = [
            'discipline' => ['🧭', 'Build discipline', 'Show up consistently, every day'],
            'fitness'    => ['💪', 'Get fitter',       'Movement, workouts, energy'],
            'wellbeing'  => ['🌿', 'Feel better',      'Sleep, calm, mental health'],
            'study'      => ['📚', 'Study better',     'Focus sessions and steady progress'],
            'money'      => ['💰', 'Spend smarter',    'Awareness of where money goes'],
            'creativity' => ['🎨', 'Make more',        'Writing, art, side projects'],
          ];
          foreach ($focusOpts as $key => [$emoji, $title, $sub]): ?>
            <button type="button" class="ob-choice" role="radio" aria-checked="false" data-focus="<?= h($key) ?>">
              <span class="ob-choice-emoji" aria-hidden="true"><?= $emoji ?></span>
              <span class="ob-choice-title"><?= h($title) ?></span>
              <span class="ob-choice-sub"><?= h($sub) ?></span>
            </button>
          <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:.625rem;margin-top:1.5rem">
          <button class="btn btn-secondary" style="flex:1;justify-content:center" onclick="obGoto(1)">Back</button>
          <button class="btn btn-primary" style="flex:2;justify-content:center" onclick="obGoto(3)">
            Continue <i class="fas fa-arrow-right"></i>
          </button>
        </div>
        <button class="btn btn-ghost btn-sm" style="width:100%;justify-content:center;margin-top:.625rem" onclick="obGoto(3)">Skip</button>
      </div>

      <!-- Step 3: Hobby / goal selection -->
      <div class="ob-step hidden" id="obStep3">
        <h2 style="font-size:1.125rem;font-weight:700;margin:0 0 .375rem">What do you want Trackie to help with?</h2>
        <p style="color:var(--muted);font-size:.875rem;margin:0 0 1.25rem">
          Pick a few — Trackie will build starter habits and unlock matching modules for each.
        </p>
        <div id="obHobbyChips" style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1.75rem">
          <?php foreach ($allHobbies as $hb => $meta): ?>
            <button type="button" class="hobby-chip<?= in_array($hb, $currentHobbies, true) ? ' is-active' : '' ?>" data-hobby="<?= h($hb) ?>" aria-pressed="<?= in_array($hb, $currentHobbies, true) ? 'true' : 'false' ?>">
              <i class="fas <?= h($meta['icon']) ?>" style="margin-right:.375rem;color:<?= h($meta['color']) ?>"></i><?= h($hb) ?>
            </button>
          <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:.625rem">
          <button class="btn btn-secondary" style="flex:1;justify-content:center" onclick="obGoto(2)">Back</button>
          <button class="btn btn-primary" style="flex:2;justify-content:center" onclick="obGoto(4)">
            Continue <i class="fas fa-arrow-right"></i>
          </button>
        </div>
        <button class="btn btn-ghost btn-sm" style="width:100%;justify-content:center;margin-top:.625rem" onclick="obGoto(4)">
          Skip for now
        </button>
      </div>

      <!-- Step 4: Daily rhythm -->
      <div class="ob-step hidden" id="obStep4">
        <h2 style="font-size:1.125rem;font-weight:700;margin:0 0 .375rem">Your daily rhythm</h2>
        <p style="color:var(--muted);font-size:.875rem;margin:0 0 1.25rem">
          Used for your daily nudge and to set sensible targets. Rough numbers are fine.
        </p>

        <div class="form-group">
          <label class="form-label" for="obReminderTime">When should Trackie check in with you?</label>
          <input type="time" id="obReminderTime" class="form-input" value="08:00">
          <div class="form-hint">A single daily prompt to log your habits — not a stream of notifications.</div>
        </div>

        <div class="form-grid-2">
          <div class="form-group">
            <label class="form-label" for="obSleepGoal">Sleep target (hours)</label>
            <input type="number" id="obSleepGoal" class="form-input" min="3" max="14" step="0.5" value="8">
          </div>
          <div class="form-group">
            <label class="form-label" for="obWaterGoal">Water target (ml)</label>
            <input type="number" id="obWaterGoal" class="form-input" min="250" max="6000" step="250" value="2000">
          </div>
        </div>

        <?php require_once __DIR__ . '/../includes/settings.php'; ?>
        <div class="form-grid-2">
          <div class="form-group">
            <label class="form-label" for="obCurrency">Currency</label>
            <select id="obCurrency" class="form-input">
              <?php foreach (CURRENCIES as $code => [$sym, $label]): ?>
                <option value="<?= $code ?>" <?= $code === SETTINGS_DEFAULTS['currency'] ? 'selected' : '' ?>><?= h("$sym  $label") ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="obWeekStart">Week starts on</label>
            <select id="obWeekStart" class="form-input">
              <option value="1" <?= SETTINGS_DEFAULTS['week_start'] === 1 ? 'selected' : '' ?>>Monday</option>
              <option value="0" <?= SETTINGS_DEFAULTS['week_start'] === 0 ? 'selected' : '' ?>>Sunday</option>
            </select>
          </div>
        </div>

        <div style="display:flex;gap:.625rem;margin-top:.75rem">
          <button class="btn btn-secondary" style="flex:1;justify-content:center" onclick="obGoto(3)">Back</button>
          <button class="btn btn-primary" style="flex:2;justify-content:center" onclick="obGoto(5)">
            Continue <i class="fas fa-arrow-right"></i>
          </button>
        </div>
        <button class="btn btn-ghost btn-sm" style="width:100%;justify-content:center;margin-top:.625rem" onclick="obSkipRhythm()">Skip</button>
      </div>

      <!-- Step 5: Experience level -->
      <div class="ob-step hidden" id="obStep5">
        <h2 style="font-size:1.125rem;font-weight:700;margin:0 0 .375rem">Have you tracked habits before?</h2>
        <p style="color:var(--muted);font-size:.875rem;margin:0 0 1.25rem">
          This only changes how much guidance Trackie offers. No wrong answer.
        </p>
        <div class="ob-choice-grid ob-choice-grid-1" id="obExpChoices" role="radiogroup" aria-label="Experience level">
          <?php
          $expOpts = [
            'new'         => ['🌱', 'First time',  'Start me with a couple of easy habits'],
            'some'        => ['🌿', "I've tried",  "I've used apps before but didn't stick with them"],
            'experienced' => ['🌳', 'Experienced', 'I know what I want — get out of my way'],
          ];
          foreach ($expOpts as $key => [$emoji, $title, $sub]): ?>
            <button type="button" class="ob-choice" role="radio" aria-checked="false" data-exp="<?= h($key) ?>">
              <span class="ob-choice-emoji" aria-hidden="true"><?= $emoji ?></span>
              <span class="ob-choice-title"><?= h($title) ?></span>
              <span class="ob-choice-sub"><?= h($sub) ?></span>
            </button>
          <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:.625rem;margin-top:1.5rem">
          <button class="btn btn-secondary" style="flex:1;justify-content:center" onclick="obGoto(4)">Back</button>
          <button class="btn btn-primary" style="flex:2;justify-content:center" onclick="obSubmit()" id="obSubmitBtn">
            Finish <i class="fas fa-check"></i>
          </button>
        </div>
        <button class="btn btn-ghost btn-sm" style="width:100%;justify-content:center;margin-top:.625rem" onclick="obSubmit()">Skip</button>
      </div>

      <!-- Step 6: Ready -->
      <div class="ob-step hidden" id="obStep6">
        <div style="text-align:center">
          <div style="font-size:2rem;margin-bottom:.5rem" aria-hidden="true">🎉</div>
          <h2 style="font-size:1.25rem;font-weight:700;margin:0 0 .5rem">You're all set</h2>
          <div id="obSummary" style="color:var(--muted);font-size:.9375rem;max-width:38ch;margin:0 auto 1.25rem"></div>
          <ul class="ob-map" aria-label="Where things live">
            <li><i class="fas fa-sun" aria-hidden="true"></i><span><b>Today</b> your day at a glance: due habits, tasks, reminders</span></li>
            <li><i class="fas fa-check-square" aria-hidden="true"></i><span><b>Todos &amp; Habits</b> tasks with subtasks, habits on the days you choose</span></li>
            <li><i class="fas fa-bullseye" aria-hidden="true"></i><span><b>Goals &amp; Focus</b> long-term targets and a focus timer</span></li>
            <li><i class="fas fa-calendar" aria-hidden="true"></i><span><b>Calendar</b> everything with a date, in one place</span></li>
            <li><i class="fas fa-star" aria-hidden="true"></i><span><b>Hobbies</b> the modules for the hobbies you picked</span></li>
            <li><i class="fas fa-chart-line" aria-hidden="true"></i><span><b>Progress</b> streaks, XP, analytics and a weekly review</span></li>
            <li><i class="fas fa-bell" aria-hidden="true"></i><span><b>Notifications</b> the bell, plus push in Settings → This device</span></li>
          </ul>
          <button class="btn btn-primary" style="width:100%;justify-content:center" onclick="obFinish()">
            Go to my dashboard <i class="fas fa-arrow-right"></i>
          </button>
        </div>
      </div>

    </div>
  </div>
</div>

<style>
  .ob-dot { width: 6px; height: 6px; border-radius: 999px; background: var(--border); transition: background .2s ease, width .2s ease; }
  .ob-dot.is-active { width: 18px; background: var(--accent); }
  .ob-dot.is-done { background: var(--ok); }

  /* Choice cards (focus + experience steps) */
  .ob-choice-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .625rem;
  }
  .ob-choice-grid-1 { grid-template-columns: 1fr; }
  @media (max-width: 420px) {
    .ob-choice-grid { grid-template-columns: 1fr; }
  }
  .ob-choice {
    display: flex;
    flex-direction: column;
    gap: .1875rem;
    text-align: left;
    padding: .875rem;
    min-height: 44px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm, 10px);
    background: var(--surface2);
    cursor: pointer;
    transition: border-color .18s ease, background .18s ease, transform .15s ease;
  }
  .ob-choice:hover { border-color: var(--accent); transform: translateY(-1px); }
  .ob-choice:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
  .ob-choice.is-active {
    border-color: var(--accent);
    background: var(--accent-bg);
  }
  .ob-choice-emoji { font-size: 1.25rem; line-height: 1; }
  .ob-choice-title { font-weight: 600; font-size: .875rem; color: var(--text); }
  .ob-choice-sub   { font-size: .75rem; color: var(--muted); line-height: 1.35; }

  @media (prefers-reduced-motion: reduce) {
    .ob-choice { transition: none; }
    .ob-choice:hover { transform: none; }
  }
</style>

<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';
let obStep = 1;

function obGoto(step) {
  document.querySelectorAll('.ob-step').forEach(el => el.classList.add('hidden'));
  document.getElementById('obStep' + step).classList.remove('hidden');
  document.querySelectorAll('.ob-dot').forEach(d => {
    const n = +d.dataset.dot;
    d.classList.toggle('is-active', n === step);
    d.classList.toggle('is-done', n < step);
  });
  obStep = step;
}

document.getElementById('obHobbyChips').addEventListener('click', e => {
  const chip = e.target.closest('.hobby-chip');
  if (chip) chip.setAttribute('aria-pressed', chip.classList.toggle('is-active') ? 'true' : 'false');
});

/* Single-select card groups (focus, experience). role=radio means exactly one
   may be checked, so selecting clears the rest — and aria-checked has to move
   with the visual state or a screen reader reports every option as unselected. */
function wireChoiceGroup(containerId) {
  const box = document.getElementById(containerId);
  box?.addEventListener('click', e => {
    const btn = e.target.closest('.ob-choice');
    if (!btn) return;
    box.querySelectorAll('.ob-choice').forEach(b => {
      b.classList.remove('is-active');
      b.setAttribute('aria-checked', 'false');
    });
    btn.classList.add('is-active');
    btn.setAttribute('aria-checked', 'true');
  });
}
wireChoiceGroup('obFocusChoices');
wireChoiceGroup('obExpChoices');

// "Skip" on the rhythm step must mean "don't record targets", not "record the
// prefilled defaults" — otherwise skipping silently opts you into 8h/2000ml.
let obRhythmSkipped = false;
function obSkipRhythm() { obRhythmSkipped = true; obGoto(5); }

async function obSubmit() {
  const chosen = Array.from(document.querySelectorAll('#obHobbyChips .hobby-chip.is-active')).map(c => c.dataset.hobby);
  const btn = document.getElementById('obSubmitBtn');
  if (btn) btn.disabled = true;

  const payload = { action: 'complete_onboarding', hobbies: chosen.join(',') };

  const focus = document.querySelector('#obFocusChoices .ob-choice.is-active')?.dataset.focus;
  if (focus) payload.primary_focus = focus;

  const exp = document.querySelector('#obExpChoices .ob-choice.is-active')?.dataset.exp;
  if (exp) payload.experience_level = exp;

  if (!obRhythmSkipped) {
    const t = document.getElementById('obReminderTime')?.value;
    const s = document.getElementById('obSleepGoal')?.value;
    const w = document.getElementById('obWaterGoal')?.value;
    if (t) payload.daily_reminder_time = t;
    if (s) payload.sleep_goal_hours    = s;
    if (w) payload.water_goal_ml       = w;
  }

  try {
    const res = await Trackie.API.post(`${API_BASE}/profile.php`, payload);
    if (res.success && !obRhythmSkipped) {
      // Preferences (Settings → Preferences) — best effort; defaults stand if this fails.
      await Trackie.API.post(`${API_BASE}/settings.php`, {
        action: 'save',
        currency:   document.getElementById('obCurrency')?.value || 'INR',
        week_start: document.getElementById('obWeekStart')?.value ?? '1',
      }).catch(() => null);
    }
    if (res.success) {
      const created = res.habits_created || [];
      document.getElementById('obSummary').innerHTML = created.length
        ? `Added <b>${created.length}</b> starter habit${created.length !== 1 ? 's' : ''} and a first task to get you going.`
        : `Your dashboard is ready — add a habit or task whenever you're ready.`;
      obGoto(6);
    } else {
      Trackie.Toast.error(res.error || 'Something went wrong — try again.');
    }
  } catch { Trackie.Toast.error('Network error.'); }
  if (btn) btn.disabled = false;
}

// Skip everything: finish with nothing chosen (every answer is optional).
async function obSkipAll() {
  obRhythmSkipped = true;
  document.querySelectorAll('#obHobbyChips .hobby-chip.is-active').forEach(c => c.classList.remove('is-active'));
  await obSubmit();
}

function obFinish() {
  Trackie.PageSkeleton?.show();
  location.href = `<?= APP_BASE ?>/pages/dashboard.php?onboarded=1`;
}
</script>

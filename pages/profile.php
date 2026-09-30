<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAuth();

$uid         = currentUserId();
$pageTitle   = 'Profile';
$currentPage = 'profile';

$user = fetchOne("SELECT * FROM users WHERE id=?", [$uid]);

$picSrc = $user['profile_pic'] && file_exists(ROOT_PATH . '/' . $user['profile_pic'])
    ? APP_BASE . '/' . $user['profile_pic']
    : APP_BASE . '/assets/images/default-user.png';

// Account stats
$stats = [
    'todos'    => fetchOne("SELECT COUNT(*) c FROM todos WHERE user_id=? AND deleted_at IS NULL", [$uid])['c'],
    'habits'   => fetchOne("SELECT COUNT(*) c FROM habits WHERE user_id=?",  [$uid])['c'],
    'goals'    => fetchOne("SELECT COUNT(*) c FROM goals WHERE user_id=?",   [$uid])['c'],
    'routines' => fetchOne("SELECT COUNT(*) c FROM routines WHERE user_id=?",[$uid])['c'],
];

$bioHobbies = array_filter(array_map('trim', explode(',', $user['hobbies'] ?? '')));
$hobbyMetaAll = allHobbiesMeta();

require_once '../includes/head.php';
?>
<div class="app-shell">
<?php include '../includes/sidebar.php'; ?>
<div class="main-wrap">
<?php include '../includes/header.php'; ?>
<div class="page-content" id="page-main">

<h1 style="font-size:1.125rem;font-weight:600;margin:0 0 1.25rem"><i class="fas fa-user" style="color:var(--accent);margin-right:.375rem" aria-hidden="true"></i>My Profile</h1>

<div class="profile-grid">

  <!-- Left: Avatar + stats -->
  <div>
    <div class="card card-body" style="text-align:center;margin-bottom:1rem" id="profileAvatarCard">
      <img src="<?= h($picSrc) ?>" alt="Profile picture"
           style="width:96px;height:96px;border-radius:50%;object-fit:cover;margin:0 auto .875rem;border:3px solid var(--border)">
      <div style="font-size:1rem;font-weight:700;color:var(--text)"><?= h($user['name']) ?></div>
      <div style="font-size:.875rem;color:var(--muted)"><?= h($user['email']) ?></div>
      <div style="font-size:.8125rem;color:var(--subtle);margin-top:.25rem">
        Member since <?= formatDate($user['created_at'], 'M Y') ?>
      </div>
      <div id="bioHobbiesWrap">
        <?php if (!empty($bioHobbies)): ?>
          <div style="display:flex;flex-wrap:wrap;gap:.375rem;justify-content:center;margin-top:.875rem;padding-top:.875rem;border-top:1px solid var(--border)">
            <?php foreach ($bioHobbies as $bh):
              if (!isset($hobbyMetaAll[$bh])) continue;
              $bm = $hobbyMetaAll[$bh];
            ?>
              <span class="badge" style="background:color-mix(in srgb, <?= $bm['color'] ?> 15%, transparent);color:<?= $bm['color'] ?>">
                <i class="fas <?= $bm['icon'] ?>" style="font-size:.65rem;margin-right:.25rem"></i><?= h($bh) ?>
              </span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="card card-body">
      <div style="font-size:.8125rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:.75rem">
        Your stats
      </div>
      <?php foreach ([
        ['l'=>'Todos',    'v'=>$stats['todos'],    'i'=>'fa-check-square'],
        ['l'=>'Habits',   'v'=>$stats['habits'],   'i'=>'fa-heart'],
        ['l'=>'Goals',    'v'=>$stats['goals'],    'i'=>'fa-bullseye'],
        ['l'=>'Routines', 'v'=>$stats['routines'], 'i'=>'fa-clock'],
      ] as $s): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;font-size:.875rem;padding:.375rem 0;border-bottom:1px solid var(--border)">
          <span style="color:var(--muted)"><i class="fas <?= $s['i'] ?>" style="width:1rem"></i> <?= $s['l'] ?></span>
          <strong style="color:var(--text)"><?= $s['v'] ?></strong>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Right: Edit forms -->
  <div style="display:flex;flex-direction:column;gap:1rem">

    <!-- Profile info form -->
    <div class="card card-body">
      <div style="font-size:.9375rem;font-weight:600;margin-bottom:1rem">Personal information</div>
      <form id="profileForm" enctype="multipart/form-data" data-loading>
        <div style="margin-bottom:1rem">
          <label for="pf-profile_pic" class="form-label">Profile picture</label>
          <input id="pf-profile_pic" type="file" name="profile_pic" accept="image/*" class="form-input"
                 style="padding:.375rem">
          <p class="form-hint">JPEG, PNG, GIF or WebP · max 5 MB</p>
        </div>
        <div class="form-grid-2">
          <div class="form-group">
            <label for="pf-name" class="form-label">Full name</label>
            <input id="pf-name" name="name" class="form-input" value="<?= h($user['name']) ?>" required>
          </div>
          <div class="form-group">
            <label for="pf-email" class="form-label">Email</label>
            <input id="pf-email" name="email" type="email" class="form-input" value="<?= h($user['email']) ?>" required>
          </div>
        </div>
        <div class="form-group">
          <label for="pf-phone" class="form-label">Phone <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
          <input id="pf-phone" name="phone" type="tel" class="form-input" value="<?= h($user['phone'] ?? '') ?>">
        </div>
        <div style="display:flex;justify-content:flex-end;margin-top:.5rem">
          <button type="submit" class="btn btn-primary btn-sm" id="profileSaveBtn">
            <i class="fas fa-save"></i> Save changes
          </button>
        </div>
        <div id="profileMsg" style="margin-top:.75rem;font-size:.875rem"></div>
      </form>
    </div>

    <!-- Onboarding answers, editable. These were being written and never shown,
         which meant a user could not see or correct what they had told us. -->
    <?php $pf = userPrefs($uid); ?>
    <div class="card card-body">
      <div style="font-size:.9375rem;font-weight:600;margin-bottom:.25rem">Your setup</div>
      <p class="form-hint" style="margin-bottom:.875rem">
        From onboarding. Trackie uses your focus to order the dashboard and your
        check-in time for the daily nudge.
      </p>
      <form id="prefsForm" class="form-grid-2">
        <div class="form-group">
          <label class="form-label" for="pfFocus">Main focus</label>
          <select id="pfFocus" class="form-input">
            <option value="">Not set</option>
            <?php foreach (['discipline','fitness','wellbeing','study','money','creativity'] as $fk): ?>
              <option value="<?= $fk ?>" <?= $pf['primary_focus'] === $fk ? 'selected' : '' ?>>
                <?= h(focusLabel($fk)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" for="pfExp">Guidance level</label>
          <select id="pfExp" class="form-input">
            <option value="">Not set</option>
            <option value="new"         <?= $pf['experience_level']==='new'?'selected':'' ?>>New — show me the ropes</option>
            <option value="some"        <?= $pf['experience_level']==='some'?'selected':'' ?>>Some experience</option>
            <option value="experienced" <?= $pf['experience_level']==='experienced'?'selected':'' ?>>Experienced — no hand-holding</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" for="pfTime">Daily check-in</label>
          <input id="pfTime" type="time" class="form-input"
                 value="<?= h(substr((string)$pf['daily_reminder_time'], 0, 5)) ?>">
        </div>
        <div class="form-group">
          <label class="form-label" for="pfSleep">Sleep target (hours)</label>
          <input id="pfSleep" type="number" min="3" max="14" step="0.5" class="form-input"
                 value="<?= $pf['sleep_goal_hours'] !== null ? h((string)(float)$pf['sleep_goal_hours']) : '' ?>">
        </div>
        <div class="form-group">
          <label class="form-label" for="pfWater">Water target (ml)</label>
          <input id="pfWater" type="number" min="250" max="6000" step="250" class="form-input"
                 value="<?= $pf['water_goal_ml'] !== null ? (int)$pf['water_goal_ml'] : '' ?>">
        </div>
      </form>
      <button class="btn btn-primary btn-sm" style="margin-top:.75rem" onclick="savePrefs(this)">
        <i class="fas fa-save"></i> Save setup
      </button>
      <p class="form-hint" style="margin-top:.5rem">
        Sleep and water targets are stored now; daily logging against them is coming next.
      </p>
    </div>

    <!-- Hobbies (personalizes habit suggestions) -->
    <div class="card card-body">
      <div style="font-size:.9375rem;font-weight:600;margin-bottom:.25rem">Your hobbies</div>
      <p class="form-hint" style="margin-bottom:.875rem">Pick a few — Trackie suggests starter habits that match, right on the Habits page.</p>
      <?php
        $selectedHobbies = array_filter(array_map('trim', explode(',', $user['hobbies'] ?? '')));
        $allHobbies = array_keys(allHobbiesMeta());
      ?>
      <div id="hobbyChips" style="display:flex;flex-wrap:wrap;gap:.5rem">
        <?php foreach ($allHobbies as $hb): $active = in_array($hb, $selectedHobbies, true); ?>
          <button type="button" class="hobby-chip<?= $active ? ' is-active' : '' ?>" data-hobby="<?= h($hb) ?>">
            <?= h($hb) ?>
          </button>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;justify-content:flex-end;margin-top:1rem">
        <button type="button" class="btn btn-primary btn-sm" id="hobbySaveBtn" onclick="saveHobbies()">
          <i class="fas fa-save"></i> Save hobbies
        </button>
      </div>
    </div>

    <!-- Password form -->
    <div class="card card-body">
      <div style="font-size:.9375rem;font-weight:600;margin-bottom:1rem">Change password</div>
      <form id="pwForm">
        <div class="form-group">
          <label for="pf-current_password" class="form-label">Current password</label>
          <input id="pf-current_password" name="current_password" type="password" class="form-input" autocomplete="current-password">
        </div>
        <div class="form-grid-2">
          <div class="form-group">
            <label for="pf-new_password" class="form-label">New password</label>
            <input id="pf-new_password" name="new_password" type="password" class="form-input" autocomplete="new-password">
          </div>
          <div class="form-group">
            <label for="pf-confirm_password" class="form-label">Confirm new</label>
            <input id="pf-confirm_password" name="confirm_password" type="password" class="form-input" autocomplete="new-password">
          </div>
        </div>
        <p class="form-hint">At least 8 characters.</p>
        <div style="display:flex;justify-content:flex-end;margin-top:.5rem">
          <button type="submit" class="btn btn-secondary btn-sm">
            <i class="fas fa-lock"></i> Change password
          </button>
        </div>
        <div id="pwMsg" style="margin-top:.75rem;font-size:.875rem"></div>
      </form>
    </div>

  </div>
</div>

</div>
<?php include '../includes/footer.php'; ?>

<script>
const API_BASE = '<?= APP_BASE ?>/api';

document.getElementById('hobbyChips').addEventListener('click', function (e) {
  const chip = e.target.closest('.hobby-chip');
  if (!chip) return;
  const active = document.querySelectorAll('.hobby-chip.is-active').length;
  if (!chip.classList.contains('is-active') && active >= 8) {
    Trackie.Toast.warning('Pick up to 8 hobbies.');
    return;
  }
  chip.classList.toggle('is-active');
});

async function saveHobbies() {
  const chosen = Array.from(document.querySelectorAll('.hobby-chip.is-active')).map(c => c.dataset.hobby);
  const btn = document.getElementById('hobbySaveBtn');
  btn.disabled = true;
  try {
    const res = await Trackie.API.post(`${API_BASE}/profile.php`, { action: 'save_hobbies', hobbies: chosen.join(',') });
    if (res.success) {
      Trackie.Toast.success('Hobbies saved!');
      await Trackie.refreshFragments(['sidebarNav', 'bioHobbiesWrap']);
    } else Trackie.Toast.error(res.error || 'Failed.');
  } catch { Trackie.Toast.error('Network error.'); }
  btn.disabled = false;
}

/**
 * Downscale an image File to fit within maxPx on its longest edge and
 * re-encode as JPEG. Returns a Blob, or null if the browser can't do it.
 * Transparency is flattened onto white — avatars are always displayed on a
 * solid surface, and JPEG has no alpha channel.
 */
function shrinkImage(file, maxPx, quality) {
  return new Promise((resolve) => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
      URL.revokeObjectURL(url);
      const scale = Math.min(1, maxPx / Math.max(img.width, img.height));
      // Already small enough — don't re-encode and lose quality for nothing.
      if (scale === 1 && file.size < 300 * 1024) { resolve(null); return; }

      const canvas = document.createElement('canvas');
      canvas.width  = Math.round(img.width  * scale);
      canvas.height = Math.round(img.height * scale);
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
      canvas.toBlob(b => resolve(b), 'image/jpeg', quality);
    };
    img.onerror = () => { URL.revokeObjectURL(url); resolve(null); };
    img.src = url;
  });
}

document.getElementById('profileForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const btn = document.getElementById('profileSaveBtn');
  const msg = document.getElementById('profileMsg');
  btn.disabled = true;
  const fd = new FormData(this);

  /* Downscale the avatar in the browser before uploading.
     Production had 2–2.6 MB phone photos stored verbatim and then served as a
     44px sidebar avatar — wasted upload bandwidth for the user and wasted
     transfer for every page view afterwards. Doing this client-side works
     regardless of whether the host has the GD extension enabled.
     If anything goes wrong we simply send the original file, so a resize
     failure can never block someone from setting a picture. */
  const picField = this.querySelector('input[type="file"][name="profile_pic"]');
  const picFile  = picField?.files?.[0];
  if (picFile && picFile.type.startsWith('image/')) {
    try {
      const shrunk = await shrinkImage(picFile, 512, 0.82);
      if (shrunk && shrunk.size < picFile.size) {
        fd.set('profile_pic', shrunk, 'avatar.jpg');
      }
    } catch { /* keep the original */ }
  }

  fd.append('action', 'update');
  fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
  try {
    const res = await fetch(`${API_BASE}/profile.php`, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': fd.get('csrf_token'), 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    });
    const data = await res.json();
    if (data.success) {
      Trackie.Toast.success(data.message || 'Profile saved!');
      msg.innerHTML = '';
      // Live-refresh the avatar card + the persistent topbar chip — no reload.
      await Trackie.refreshFragments(['profileAvatarCard', 'topbarUserChip']);
    } else {
      msg.innerHTML = `<span style="color:var(--accent)">${escHtml(data.error || 'Something went wrong.')}</span>`;
    }
  } catch { Trackie.Toast.error('Network error.'); }
  btn.disabled = false;
});

document.getElementById('pwForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const msg = document.getElementById('pwMsg');
  const payload = {
    action:           'change_password',
    current_password: this.current_password.value,
    new_password:     this.new_password.value,
    confirm_password: this.confirm_password.value,
  };
  try {
    const res = await Trackie.API.post(`${API_BASE}/profile.php`, payload);
    if (res.success) {
      Trackie.Toast.success(res.message || 'Password changed.');
      msg.innerHTML = '';
      this.reset();
    } else {
      msg.innerHTML = `<span style="color:var(--accent)">${escHtml(res.error || 'Something went wrong.')}</span>`;
    }
  } catch { Trackie.Toast.error('Network error.'); }
});

/* Onboarding answers, editable here. Empty inputs are sent as empty strings,
   which savePersonalisation() with allowClear=true stores as NULL — that is how
   a user resets a field back to "not set". Focus changes affect the dashboard's
   widget order, so the sidebar/dash are refreshed rather than left stale. */
async function savePrefs(btn) {
  const original = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
  try {
    const res = await Trackie.API.post(`${API_BASE}/profile.php`, {
      action: 'save_prefs',
      primary_focus:       document.getElementById('pfFocus').value,
      experience_level:    document.getElementById('pfExp').value,
      daily_reminder_time: document.getElementById('pfTime').value,
      sleep_goal_hours:    document.getElementById('pfSleep').value,
      water_goal_ml:       document.getElementById('pfWater').value,
    });
    if (res.queued) {
      Trackie.Toast.info('Saved offline — will sync when you reconnect.');
    } else if (res.success) {
      Trackie.Toast.success('Setup saved.');
    } else {
      Trackie.Toast.error(res.error || "Couldn't save your setup.");
    }
  } catch {
    Trackie.Toast.error("Couldn't save your setup — please try again.");
  }
  btn.disabled = false;
  btn.innerHTML = original;
}
</script>

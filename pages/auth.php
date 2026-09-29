<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Already signed in (session or remember-me token) → straight to the app.
if (tryRememberLogin()) redirect(APP_BASE . '/pages/dashboard.php');

$pageTitle = 'Sign in';
$metaIndex = true;
$metaDescription = 'Sign in to Trackie or create a free account to track habits, goals, fitness and hobbies.';
$startTab  = ($_GET['tab'] ?? '') === 'register' ? 'register' : 'login';
require_once '../includes/head.php';
?>
<style>body{overflow:hidden auto}</style>

<div class="auth-wrap">
  <div style="width:100%;max-width:440px">

    <div class="text-center" style="margin-bottom:1.5rem">
      <div class="auth-logo-ring" style="overflow:hidden;padding:0;background:none">
        <img src="<?= APP_BASE ?>/assets/images/logo.png" alt="Trackie" style="width:56px;height:56px;border-radius:50%;object-fit:cover">
      </div>
      <h1 style="font-size:1.5rem;font-weight:800;color:var(--text);margin:.5rem 0 0">Trackie</h1>
      <p style="color:var(--muted);font-size:.875rem;margin:.25rem 0 0">Your personal productivity OS</p>
    </div>

    <div class="auth-card">
      <!-- Tabs -->
      <div class="auth-tabs" role="tablist">
        <button class="auth-tab <?= $startTab==='login'?'active':'' ?>" data-tab="login" role="tab">Sign In</button>
        <button class="auth-tab <?= $startTab==='register'?'active':'' ?>" data-tab="register" role="tab">Create Account</button>
      </div>

      <div id="authError" class="alert alert-error" style="display:none"></div>

      <!-- Sign In -->
      <form id="loginForm" class="auth-form <?= $startTab==='login'?'':'hidden' ?>" autocomplete="on">
        <div class="form-group">
          <label id="authlbl-1" class="form-label">Email</label>
          <input aria-labelledby="authlbl-1" name="email" type="email" class="form-input" required autocomplete="email" placeholder="you@example.com">
        </div>
        <div class="form-group">
          <label id="authlbl-2" class="form-label">Password</label>
          <div style="position:relative">
            <input aria-labelledby="authlbl-2" name="password" type="password" class="form-input" required autocomplete="current-password" placeholder="••••••••" style="padding-right:2.5rem">
            <button type="button" data-toggle-pw aria-label="Show or hide password" style="position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted)"><i class="fas fa-eye"></i></button>
          </div>
        </div>
        <label style="display:flex;align-items:center;gap:.4rem;font-size:.875rem;color:var(--muted);cursor:pointer;margin-bottom:1rem">
          <input name="remember" type="checkbox" checked style="accent-color:var(--accent)"> Keep me signed in
        </label>
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">Sign in</button>
        <p style="text-align:center;margin-top:.875rem;font-size:.8125rem">
          <a href="<?= APP_BASE ?>/pages/forgot_password.php" style="color:var(--muted)">Forgot password?</a>
        </p>
      </form>

      <!-- Create Account -->
      <form id="registerForm" class="auth-form <?= $startTab==='register'?'':'hidden' ?>" autocomplete="on">
        <div class="form-group">
          <label id="authlbl-3" class="form-label">Full name</label>
          <input aria-labelledby="authlbl-3" name="name" type="text" class="form-input" required autocomplete="name" placeholder="Jane Doe">
        </div>
        <div class="form-group">
          <label id="authlbl-4" class="form-label">Email</label>
          <input aria-labelledby="authlbl-4" name="email" type="email" class="form-input" required autocomplete="email" placeholder="you@example.com">
        </div>
        <div class="form-grid-2">
          <div class="form-group">
            <label id="authlbl-5" class="form-label">Password</label>
            <input aria-labelledby="authlbl-5" id="regPassword" name="password" type="password" class="form-input" required autocomplete="new-password" placeholder="8+ characters">
          </div>
          <div class="form-group">
            <label id="authlbl-6" class="form-label">Confirm</label>
            <input aria-labelledby="authlbl-6" id="regConfirm" name="confirm_password" type="password" class="form-input" required autocomplete="new-password" placeholder="Repeat">
          </div>
        </div>

        <?php /* Strength meter. aria-live=polite so screen-reader users get
                 the verdict as they type, without the bar itself being read
                 (it is decorative — the text carries the meaning). */ ?>
        <div class="pw-meter" id="pwMeter" hidden>
          <div class="pw-meter-track" aria-hidden="true">
            <div class="pw-meter-fill" id="pwMeterFill"></div>
          </div>
          <div class="pw-meter-text" id="pwMeterText" aria-live="polite"></div>
        </div>
        <div class="form-hint" id="pwMatchHint" aria-live="polite" style="margin-top:.25rem"></div>
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:.25rem">Create account &amp; continue</button>
        <p class="form-hint" style="text-align:center;margin:.75rem 0 0">
          By creating an account you agree to the <a href="<?= APP_BASE ?>/pages/terms.php" data-no-spa>Terms</a>
          and <a href="<?= APP_BASE ?>/pages/privacy.php" data-no-spa>Privacy Policy</a>.
        </p>
      </form>
    </div>
    <p class="legal-links">
      <a href="<?= APP_BASE ?>/pages/privacy.php" data-no-spa>Privacy</a> ·
      <a href="<?= APP_BASE ?>/pages/terms.php" data-no-spa>Terms</a>
    </p>
  </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
(function () {
  const API   = '<?= APP_BASE ?>/api/auth.php';
  const csrf  = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const errEl = document.getElementById('authError');

  /* Password strength — a JS mirror of passwordStrength() in functions.php.
     The two MUST agree: a meter that says "Strong" for a password the server
     then rejects is worse than showing nothing. If you change one, change the
     other; the server stays the authority and re-checks on submit. */
  const COMMON = ['password','qwerty','111111','123456','letmein','welcome',
                  'admin','iloveyou','abc123','trackie','monkey','dragon'];

  function strength(pw) {
    const issues = [];
    if (pw.length < 8)      issues.push('at least 8 characters');
    if (!/[a-z]/.test(pw))  issues.push('a lowercase letter');
    if (!/[A-Z]/.test(pw))  issues.push('an uppercase letter');
    if (!/\d/.test(pw))     issues.push('a number');
    const lower = pw.toLowerCase();
    if (COMMON.some(c => lower.includes(c))) issues.push('something less guessable');

    let score = 0;
    if (pw.length >= 8)  score++;
    if (pw.length >= 12) score++;
    if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) score++;
    if (/\d/.test(pw) && /[^A-Za-z0-9]/.test(pw)) score++;
    if (issues.length) score = Math.min(score, 2);

    return { score, label: ['Very weak','Weak','Fair','Good','Strong'][score], issues };
  }

  const pwInput = document.getElementById('regPassword');
  const pwConf  = document.getElementById('regConfirm');
  const meter   = document.getElementById('pwMeter');
  const fill    = document.getElementById('pwMeterFill');
  const text    = document.getElementById('pwMeterText');
  const matchEl = document.getElementById('pwMatchHint');

  pwInput?.addEventListener('input', () => {
    const pw = pwInput.value;
    if (!pw) { meter.hidden = true; return; }
    meter.hidden = false;
    const s = strength(pw);
    fill.style.width = ((s.score / 4) * 100) + '%';
    fill.dataset.score = s.score;
    text.textContent = s.issues.length
      ? `${s.label} — needs ${s.issues.join(', ')}`
      : `${s.label} password`;
    checkMatch();
  });

  function checkMatch() {
    if (!pwConf?.value) { matchEl.textContent = ''; return; }
    const same = pwConf.value === pwInput.value;
    matchEl.textContent = same ? '✓ Passwords match' : '✗ Passwords do not match';
    matchEl.style.color = same ? 'var(--ok)' : 'var(--accent)';
  }
  pwConf?.addEventListener('input', checkMatch);

  // Tab switching (no reload)
  document.querySelectorAll('.auth-tab').forEach(t =>
    t.addEventListener('click', () => switchTab(t.dataset.tab)));
  function switchTab(tab) {
    document.querySelectorAll('.auth-tab').forEach(x => x.classList.toggle('active', x.dataset.tab === tab));
    document.getElementById('loginForm').classList.toggle('hidden', tab !== 'login');
    document.getElementById('registerForm').classList.toggle('hidden', tab !== 'register');
    errEl.style.display = 'none';
  }

  // Show-password toggle
  document.querySelectorAll('[data-toggle-pw]').forEach(b => b.addEventListener('click', () => {
    const inp = b.previousElementSibling, icon = b.querySelector('i');
    const show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    icon.className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
  }));

  async function submit(form, action) {
    errEl.style.display = 'none';
    const btn = form.querySelector('[type=submit]');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Please wait…';

    const body = new FormData(form);
    body.append('action', action);
    body.append('csrf_token', csrf);
    try {
      const res = await fetch(API, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body });
      const data = await res.json();
      if (data.success) {
        btn.innerHTML = '<i class="fas fa-check"></i> ' + (data.welcome ? 'Welcome to Trackie!' : 'Signed in!');
        if (window.Trackie?.PageSkeleton) Trackie.PageSkeleton.show();
        location.href = data.redirect;
      } else {
        errEl.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Something went wrong.');
        errEl.style.display = 'flex';
        btn.disabled = false; btn.innerHTML = orig;
      }
    } catch {
      errEl.innerHTML = '<i class="fas fa-wifi"></i> You appear to be offline. Connect to sign in.';
      errEl.style.display = 'flex';
      btn.disabled = false; btn.innerHTML = orig;
    }
  }

  document.getElementById('loginForm').addEventListener('submit', e => { e.preventDefault(); submit(e.target, 'login'); });
  document.getElementById('registerForm').addEventListener('submit', e => { e.preventDefault(); submit(e.target, 'register'); });
})();
</script>

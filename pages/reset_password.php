<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

if (isLoggedIn()) redirect(APP_BASE . '/pages/dashboard.php');

$token     = sanitizeInput($_GET['token'] ?? '');
$tokenHash = $token ? hash('sha256', $token) : '';
$error     = '';
$success   = '';
$validToken = null;

if ($tokenHash) {
    $validToken = fetchOne(
        "SELECT pr.*, u.name, u.email FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.token_hash=? AND pr.used=0 AND pr.expires_at > NOW()
         LIMIT 1",
        [$tokenHash]
    );
}

if (!$token || !$validToken) {
    $error = 'This reset link is invalid or has expired. Please request a new one.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    verify_csrf();
    $password = $_POST['password']         ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        // Claim the token first and atomically: a double-submit (or a second
        // tab) finds used=1 and changes nothing.
        $claimed = update("UPDATE password_resets SET used=1 WHERE token_hash=? AND used=0 AND expires_at > NOW()", [$tokenHash]);
        if ($claimed !== 1) {
            $error = 'This reset link has already been used. Please request a new one.';
        } else {
            update("UPDATE users SET password=? WHERE id=?",
                   [hashPassword($password), $validToken['user_id']]);
            // Anyone holding a "remember me" cookie for this account is signed out.
            delete("DELETE FROM remember_tokens WHERE user_id=?", [$validToken['user_id']]);

            flash('success', 'Password reset! Please sign in with your new password.');
            redirect(APP_BASE . '/pages/login.php');
        }
    }
}
?>
<?php require_once '../includes/head.php'; ?>
<style>body{overflow:hidden auto}</style>

<div class="auth-wrap">
  <div style="width:100%;max-width:420px">

    <div class="text-center" style="margin-bottom:1.5rem">
      <div class="auth-logo-ring"><i class="fas fa-rocket"></i></div>
      <h1 style="font-size:1.5rem;font-weight:700;color:var(--text);margin:0">Trackie</h1>
    </div>

    <div class="auth-card">
      <h2 style="font-size:1.125rem;font-weight:600;margin:0 0 1.25rem;color:var(--text)">
        Set new password
      </h2>

      <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?= h($error) ?>
          <?php if (!$validToken): ?>
            <br><a href="<?= APP_BASE ?>/pages/forgot_password.php" style="color:inherit;font-weight:600">
              Request a new link →
            </a>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($validToken && !$error): ?>
        <p style="font-size:.875rem;color:var(--muted);margin:0 0 1.25rem">
          Setting new password for <strong><?= h($validToken['email']) ?></strong>
        </p>
        <form method="POST" data-loading>
          <?= csrf_field() ?>
          <div class="form-group">
            <label class="form-label" for="password">New password</label>
            <input id="password" name="password" type="password" class="form-input" required autofocus
                   placeholder="At least 8 characters">
          </div>
          <div class="form-group">
            <label class="form-label" for="confirm_password">Confirm new password</label>
            <input id="confirm_password" name="confirm_password" type="password" class="form-input" required
                   placeholder="Repeat password">
          </div>
          <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:.25rem">
            Reset password
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
</body></html>

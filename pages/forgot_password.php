<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Signed-in users may use this too: Google-only accounts set their first password here.

$error   = '';
$success = '';
$devLink = '';  // local development only — see isLocalDevRequest()

/**
 * The reset link may be shown on screen ONLY on a developer's own machine:
 * APP_ENV must say so AND the request must come from loopback to a loopback
 * host. On any real server this is false, so a failed email can never hand
 * someone else's reset link to whoever typed their address.
 */
function isLocalDevRequest(): bool {
    $env  = defined('APP_ENV') ? strtolower((string)APP_ENV) : 'production';
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '';
    $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    return in_array($env, ['local', 'development', 'dev'], true)
        && in_array($ip, ['127.0.0.1', '::1'], true)
        && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    require_once '../includes/mailer.php';
    $email = sanitizeInput($_POST['email'] ?? '');

    if (!$email || !validateEmail($email)) {
        $error = 'Please enter a valid email address.';
    } elseif (!rateLimit('password_reset', $_SERVER['REMOTE_ADDR'] ?? 'cli', 3, 900)) {
        $error = 'Too many reset requests. Please wait 15 minutes.';
    } elseif (!mailConfigured() && !isLocalDevRequest()) {
        // Same answer for every address, so this reveals nothing about accounts.
        error_log('Password reset requested but no mail transport is configured (set BREVO_API_KEY or SMTP_* in env.php).');
        $error = "Password reset by email isn't available right now. Please try again later.";
    } else {
        $user = fetchOne("SELECT id, name FROM users WHERE email=?", [$email]);

        // Always the same message, to prevent email enumeration.
        $success = 'If that email is registered, a reset link is on its way. Check your inbox and spam folder.';

        if ($user) {
            $resetUrl = createPasswordReset((int)$user['id']);
            $res = mailConfigured() ? sendPasswordResetEmail($email, (string)$user['name'], $resetUrl)
                                    : ['ok' => false, 'error' => 'no transport'];
            if (!$res['ok']) {
                // Never log the token or URL on a real server.
                error_log('Password reset email failed for user ' . (int)$user['id'] . ': ' . ($res['error'] ?? 'unknown'));
                if (isLocalDevRequest()) $devLink = $resetUrl;
            }
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
      <p style="color:var(--muted);font-size:.875rem;margin:.25rem 0 0">Password recovery</p>
    </div>

    <div class="auth-card">
      <h2 style="font-size:1.125rem;font-weight:600;margin:0 0 .5rem;color:var(--text)">
        Forgot your password?
      </h2>
      <p style="font-size:.875rem;color:var(--muted);margin:0 0 1.25rem">
        Enter your email and we'll send a reset link.
      </p>

      <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?= h($error) ?></div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i><?= h($success) ?></div>
      <?php endif; ?>

      <?php if ($devLink): ?>
        <div style="margin-bottom:1rem;padding:.75rem 1rem;background:#fffbeb;border:1px solid #fde68a;border-radius:.5rem;font-size:.8125rem;color:#92400e">
          <strong>⚠️ Local development only — email not sent.</strong><br>
          Use this link to reset the password:<br>
          <a href="<?= h($devLink) ?>" style="word-break:break-all"><?= h($devLink) ?></a>
        </div>
      <?php endif; ?>

      <?php if (!$success): ?>
        <form method="POST" data-loading>
          <?= csrf_field() ?>
          <div class="form-group">
            <label class="form-label" for="email">Email address</label>
            <input id="email" name="email" type="email" class="form-input" required autofocus
                   value="<?= h($_POST['email'] ?? '') ?>" placeholder="you@example.com">
          </div>
          <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
            Send reset link
          </button>
        </form>
      <?php endif; ?>

      <p style="text-align:center;margin-top:1.25rem;font-size:.875rem;color:var(--muted)">
        Remembered it? <a href="<?= APP_BASE ?>/pages/login.php">Back to login</a>
      </p>
    </div>
  </div>
</div>
</body></html>

<?php
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

if (isLoggedIn()) redirect(APP_BASE . '/pages/dashboard.php');

$error   = '';
$success = '';
$devLink = '';  // shown in dev mode when mail() unavailable

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = sanitizeInput($_POST['email'] ?? '');

    if (!$email || !validateEmail($email)) {
        $error = 'Please enter a valid email address.';
    } elseif (!rateLimit('password_reset', $_SERVER['REMOTE_ADDR'] ?? 'cli', 3, 900)) {
        $error = 'Too many reset requests. Please wait 15 minutes.';
    } else {
        $user = fetchOne("SELECT id, name FROM users WHERE email=?", [$email]);

        // Always show success to prevent email enumeration
        $success = 'If that email is registered, a reset link has been sent.';

        if ($user) {
            $token     = generateToken();
            $tokenHash = hash('sha256', $token);
            $expires   = date('Y-m-d H:i:s', time() + 3600);

            // Invalidate old tokens for this user
            update("UPDATE password_resets SET used=1 WHERE user_id=?", [$user['id']]);
            insert(
                "INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?,?,?)",
                [$user['id'], $tokenHash, $expires]
            );

            $resetUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
                      . '://' . $_SERVER['HTTP_HOST']
                      . APP_BASE . '/pages/reset_password.php?token=' . $token;

            $subject = 'Reset your Trackie password';
            $body    = "Hi {$user['name']},\n\n"
                     . "Click the link below to reset your password (expires in 1 hour):\n\n"
                     . $resetUrl . "\n\n"
                     . "If you didn't request this, ignore this email.";

            $sent = @mail($email, $subject, $body, "From: noreply@trackie.app\r\nContent-Type: text/plain");

            if (!$sent) {
                // Development fallback — log to file, show link in UI
                $logFile = ROOT_PATH . '/logs/password_resets.log';
                if (!is_dir(dirname($logFile))) mkdir(dirname($logFile), 0755, true);
                file_put_contents($logFile,
                    date('[Y-m-d H:i:s]') . " Reset link for {$email}: {$resetUrl}\n",
                    FILE_APPEND
                );
                $devLink = $resetUrl;  // shown below in dev banner
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
          <strong>⚠️ Dev mode — mail() unavailable.</strong><br>
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

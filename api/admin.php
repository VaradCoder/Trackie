<?php
/**
 * Admin API — restricted to admins. CSRF-protected.
 *
 *   toggle_admin   make / revoke admin (never yourself)
 *   profile        one user's details for the profile panel
 *   reset_link     one-hour reset link; emailed when mail is configured, and
 *                  always returned to the admin so it can be shared by hand
 *   delete_user    permanently delete a user and everything they own
 *                  (FK cascades + their uploaded files); requires typing the email
 *   save_config    non-secret settings (app_config): sender, support email, URL
 */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

requireAdmin();
verify_csrf();

$uid    = currentUserId();
$action = sanitizeInput($_POST['action'] ?? '');
$target = (int)($_POST['user_id'] ?? 0);
$user   = $target ? fetchOne("SELECT id, name, email, profile_pic, is_admin, password, created_at, onboarding_completed_at, hobbies, primary_focus FROM users WHERE id=?", [$target]) : null;
$needUser = static function () use ($user) { if (!$user) json_out(['success' => false, 'error' => 'User not found.'], 404); };

switch ($action) {

    case 'toggle_admin':
        $needUser();
        if ($target === $uid) json_out(['success' => false, 'error' => "You can't change your own role."], 422);
        $new = $user['is_admin'] ? 0 : 1;
        update("UPDATE users SET is_admin=? WHERE id=?", [$new, $target]);
        json_out(['success' => true, 'is_admin' => $new]);

    case 'profile': {
        $needUser();
        $safe = static function (string $sql, array $p) { try { return fetchOne($sql, $p) ?: []; } catch (Throwable $e) { return []; } };
        $safeAll = static function (string $sql, array $p) { try { return fetchAll($sql, $p); } catch (Throwable $e) { return []; } };
        $pic = $user['profile_pic'] && is_file(ROOT_PATH . '/' . $user['profile_pic']) ? APP_BASE . '/' . $user['profile_pic'] : null;
        $settings = $safe("SELECT timezone FROM user_settings WHERE user_id=?", [$target]);
        $counts = [];
        foreach (['todos' => "todos WHERE user_id=? AND deleted_at IS NULL", 'habits' => 'habits WHERE user_id=?', 'goals' => 'goals WHERE user_id=?',
                  'reminders' => 'reminders WHERE user_id=?', 'focus sessions' => 'focus_sessions WHERE user_id=?', 'games' => 'games WHERE user_id=?',
                  'books' => 'books WHERE user_id=?', 'transactions' => 'transactions WHERE user_id=?', 'photos' => 'photo_images WHERE user_id=?'] as $label => $from) {
            $counts[$label] = (int)($safe("SELECT COUNT(*) n FROM {$from}", [$target])['n'] ?? 0);
        }
        json_out(['success' => true, 'user' => [
            'id' => (int)$user['id'], 'name' => $user['name'], 'email' => $user['email'], 'pic' => $pic,
            'is_admin' => (bool)$user['is_admin'], 'joined' => $user['created_at'],
            'onboarded' => $user['onboarding_completed_at'], 'focus' => $user['primary_focus'], 'hobbies' => $user['hobbies'],
            'timezone' => $settings['timezone'] ?? null,
            'has_password' => ($user['password'] ?? '') !== '' && $user['password'][0] !== '!',
            'google' => $safe("SELECT email, last_login_at FROM user_identities WHERE user_id=? AND provider='google'", [$target]) ?: null,
            'xp' => (int)($safe("SELECT total_xp FROM user_xp WHERE user_id=?", [$target])['total_xp'] ?? 0),
            'counts' => $counts,
            'integrations' => array_column($safeAll("SELECT provider, sync_status, last_sync FROM user_integrations WHERE user_id=?", [$target]), null, 'provider'),
            'devices' => $safeAll("SELECT device_name, app_version, created_at, last_seen_at, fcm_token IS NOT NULL push FROM native_devices WHERE user_id=? AND revoked_at IS NULL ORDER BY last_seen_at DESC", [$target]),
            'presence' => $safeAll("SELECT day, web_hits, app_hits FROM user_presence WHERE user_id=? ORDER BY day DESC LIMIT 14", [$target]),
            'last_seen' => $safe("SELECT MAX(last_at) t FROM user_presence WHERE user_id=?", [$target])['t'] ?? null,
        ]]);
    }

    case 'reset_link': {
        $needUser();
        require_once '../includes/mailer.php';
        $url = createPasswordReset($target);
        $mailed = false; $mailError = null;
        if (mailConfigured()) {
            $r = sendPasswordResetEmail($user['email'], (string)$user['name'], $url);
            $mailed = $r['ok']; $mailError = $r['ok'] ? null : ($r['error'] ?? 'Sending failed.');
        }
        json_out(['success' => true, 'url' => $url, 'mailed' => $mailed,
                  'mail_error' => mailConfigured() ? $mailError : 'Email is not configured on this server.',
                  'expires_in' => 3600]);
    }

    case 'delete_user': {
        $needUser();
        if ($target === $uid) json_out(['success' => false, 'error' => "You can't delete your own account here."], 422);
        if (strcasecmp(trim((string)($_POST['confirm_email'] ?? '')), $user['email']) !== 0) {
            json_out(['success' => false, 'error' => 'Type the user\'s email exactly to confirm.'], 422);
        }
        // Files first (rows are needed to find them), then the user row —
        // every user-owned table cascades from users(id).
        $files = 0;
        $rm = static function (string $path) use (&$files) { if (is_file($path) && @unlink($path)) $files++; };
        if ($user['profile_pic']) $rm(ROOT_PATH . '/' . ltrim($user['profile_pic'], '/'));
        $dir = ROOT_PATH . '/storage/photos/' . $target;
        if (is_dir($dir)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : $rm($f->getPathname()); }
            @rmdir($dir);
        }
        $n = delete("DELETE FROM users WHERE id=?", [$target]);
        error_log("admin {$uid} deleted user {$target}");
        json_out(['success' => $n === 1, 'files' => $files]);
    }

    case 'save_config': {
        if (!tableExists('app_config')) json_out(['success' => false, 'error' => 'Run the app_config migration first.'], 503);
        $rules = [
            'MAIL_FROM'      => static fn($v) => $v === '' || filter_var($v, FILTER_VALIDATE_EMAIL),
            'MAIL_FROM_NAME' => static fn($v) => mb_strlen($v) <= 60,
            'SUPPORT_EMAIL'  => static fn($v) => $v === '' || filter_var($v, FILTER_VALIDATE_EMAIL),
            'APP_URL'        => static fn($v) => $v === '' || preg_match('#^https?://[a-z0-9.-]+(:\d+)?/?$#i', $v),
        ];
        $saved = [];
        foreach ($rules as $key => $ok) {
            if (!array_key_exists($key, $_POST)) continue;
            $v = trim(strip_tags((string)$_POST[$key]));
            if ($key === 'APP_URL') $v = rtrim($v, '/');
            if (!$ok($v)) json_out(['success' => false, 'error' => "That {$key} doesn't look right."], 422);
            update("INSERT INTO app_config (name, value, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value), updated_by=VALUES(updated_by)", [$key, $v, $uid]);
            $saved[$key] = $v;
        }
        json_out(['success' => true, 'saved' => $saved]);
    }

    case 'generate_vapid': {
        // Server-side keypair → keys/vapid.php of THIS site folder. Replacing
        // keys signs every browser out of web push, so it must be asked for.
        require_once '../includes/webpush.php';
        if (is_file(ROOT_PATH . '/keys/vapid.php') && empty($_POST['replace'])) {
            json_out(['success' => false, 'exists' => true, 'error' => 'Push keys already exist. Replacing them turns off push in every browser until it is re-enabled.'], 409);
        }
        $from = env('SUPPORT_EMAIL') ?: (env('MAIL_FROM') ?: 'varadbhole09@gmail.com');
        try { generateVapidKeys('mailto:' . $from); }
        catch (Throwable $e) { json_out(['success' => false, 'error' => $e->getMessage()], 500); }
        json_out(['success' => true]);
    }

    case 'generate_cron': {
        // keys/cron_token.txt (web-blocked) — cron/dispatch.php reads it when
        // CRON_TOKEN isn't set in env.php. Shown to the admin once, here.
        if (strlen((string)env('CRON_TOKEN')) >= 24) json_out(['success' => false, 'error' => 'CRON_TOKEN is set in config/env.php — change it there.'], 409);
        $file = ROOT_PATH . '/keys/cron_token.txt';
        if (is_file($file) && empty($_POST['replace'])) json_out(['success' => false, 'exists' => true, 'error' => 'A cron token already exists. Replacing it breaks the current cron job until you update its URL.'], 409);
        if (!is_dir(ROOT_PATH . '/keys')) mkdir(ROOT_PATH . '/keys', 0700, true);
        if (!is_file(ROOT_PATH . '/keys/.htaccess')) @file_put_contents(ROOT_PATH . '/keys/.htaccess', "Require all denied\n");
        $token = bin2hex(random_bytes(24));
        if (file_put_contents($file, $token) === false) json_out(['success' => false, 'error' => 'Could not write keys/cron_token.txt.'], 500);
        json_out(['success' => true, 'url' => (siteBaseUrl() ?: 'https://' . $_SERVER['HTTP_HOST']) . APP_BASE . '/cron/dispatch.php?token=' . $token]);
    }

    default:
        json_out(['success' => false, 'error' => 'Unknown action.'], 400);
}

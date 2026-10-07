<?php
/**
 * Generate a VAPID keypair for Web Push and write keys/vapid.php.
 *
 * Run ONCE from the command line:
 *   php cron/generate_vapid.php
 *
 * The generated file lives in keys/ (web-blocked + git-ignored). Regenerating
 * it invalidates all existing push subscriptions, so only run it again if you
 * intend to reset push. Set the contact subject below to your own email.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line only.');
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/webpush.php';

$subject = 'mailto:varadbhole09@gmail.com';

try {
    $publicB64 = generateVapidKeys($subject);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "
");
    exit(1);
}
echo "VAPID keys written to keys/vapid.php
";
echo "Public key (base64url):
$publicB64
";

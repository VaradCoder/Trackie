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
    $key = WebPush::newEcKey();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

openssl_pkey_export($key, $pem);
$details = openssl_pkey_get_details($key);

// Raw 65-byte uncompressed public point → base64url (used by the browser as
// applicationServerKey and sent in the VAPID Authorization header).
$pad = fn(string $b) => str_pad($b, 32, "\x00", STR_PAD_LEFT);
$rawPublic = "\x04" . $pad($details['ec']['x']) . $pad($details['ec']['y']);
$publicB64 = WebPush::b64uEncode($rawPublic);

$dir = ROOT_PATH . '/keys';
if (!is_dir($dir)) mkdir($dir, 0700, true);

$out = "<?php\n"
     . "// Auto-generated VAPID keys for Web Push. Keep private — never commit.\n"
     . "return " . var_export([
         'publicKey'     => $publicB64,
         'privateKeyPem' => $pem,
         'subject'       => $subject,
     ], true) . ";\n";

file_put_contents($dir . '/vapid.php', $out);

echo "VAPID keys written to keys/vapid.php\n";
echo "Public key (base64url):\n$publicB64\n";

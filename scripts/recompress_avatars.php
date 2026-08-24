<?php
/**
 * One-off: shrink avatars that were uploaded before resizing existed.
 *
 * Production held four 2-2.6 MB phone photos being served as 44px sidebar
 * avatars on every page view. New uploads are handled (browser-side canvas
 * resize + shrinkImageFile() server-side); this cleans up the backlog.
 *
 * Run from the browser while logged in as an admin:
 *     https://trackie.free.nf/scripts/recompress_avatars.php
 * Add ?apply=1 to actually write. Without it, this is a DRY RUN that only
 * reports what it would do — always look before overwriting user files.
 *
 * Safety:
 *  - Only touches files matching the avatar naming patterns, never anything
 *    else in assets/images (logos, icons and the default avatar are skipped).
 *  - Keeps a .bak copy of every file it rewrites, so a bad result is
 *    recoverable. Delete the .bak files once you're happy.
 *  - Skips anything already small enough — no pointless re-encoding.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireAdmin();

header('Content-Type: text/plain; charset=utf-8');

$apply   = ($_GET['apply'] ?? '') === '1';
$dir     = ROOT_PATH . '/assets/images';
$maxPx   = 512;
$quality = 82;
$minSize = 120 * 1024;   // don't bother below ~120 KB

if (!extension_loaded('gd')) {
    exit("ABORT: the GD extension is not available on this server, so images\n"
       . "cannot be resized here. New uploads are still shrunk in the browser\n"
       . "before they are sent, so this backlog is the only thing affected.\n");
}

echo $apply ? "APPLYING\n\n" : "DRY RUN — nothing will be written. Add ?apply=1 to commit.\n\n";

// Avatars only. Everything else in this folder is app chrome.
$patterns = ['pfp_*.jpg', 'pfp_*.jpeg', 'pfp_*.png', 'pfp_*.webp',
             'profile_*.jpg', 'profile_*.jpeg', 'profile_*.png', 'profile_*.webp'];

$files = [];
foreach ($patterns as $p) {
    foreach (glob("$dir/$p") ?: [] as $f) $files[$f] = true;
}
$files = array_keys($files);
sort($files);

if (!$files) exit("No avatar files found in assets/images.\n");

$before = $after = 0;
$done = $skipped = 0;

foreach ($files as $path) {
    $size = filesize($path);
    $name = basename($path);

    if ($size < $minSize) {
        printf("  skip  %-42s %8s  (already small)\n", $name, fmtKb($size));
        $before += $size; $after += $size; $skipped++;
        continue;
    }

    if (!$apply) {
        printf("  would %-42s %8s\n", $name, fmtKb($size));
        $before += $size; $done++;
        continue;
    }

    // Backup before overwriting — these are irreplaceable user uploads.
    $bak = $path . '.bak';
    if (!is_file($bak) && !@copy($path, $bak)) {
        printf("  FAIL  %-42s could not create backup, skipping\n", $name);
        $before += $size; $after += $size; $skipped++;
        continue;
    }

    if (shrinkImageFile($path, $maxPx, $quality)) {
        clearstatcache(true, $path);
        $new = filesize($path);
        printf("  ok    %-42s %8s -> %8s  (-%d%%)\n",
               $name, fmtKb($size), fmtKb($new),
               $size ? round((1 - $new / $size) * 100) : 0);
        $before += $size; $after += $new; $done++;
    } else {
        printf("  skip  %-42s %8s  (unsupported or no gain)\n", $name, fmtKb($size));
        $before += $size; $after += $size; $skipped++;
    }
}

printf("\n%d processed, %d skipped\n", $done, $skipped);
printf("total %s -> %s", fmtKb($before), $apply ? fmtKb($after) : '(dry run)');
if ($apply && $before > 0) printf("  (-%d%%)", round((1 - $after / $before) * 100));
echo "\n";
if ($apply) echo "\n.bak copies kept alongside each file — delete them once verified.\n";

function fmtKb(int $b): string {
    return $b >= 1048576
        ? number_format($b / 1048576, 2) . ' MB'
        : number_format($b / 1024, 1) . ' KB';
}

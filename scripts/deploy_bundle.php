<?php
/**
 * Build a deploy bundle for FTP-only hosting (InfinityFree): everything that
 * changed in git since the last deploy, ready to upload.
 *
 *   php scripts/deploy_bundle.php             # changes since the last deploy tag
 *   php scripts/deploy_bundle.php <git-ref>   # changes since any commit/tag
 *   php scripts/deploy_bundle.php --mark      # after uploading: tag HEAD as deployed
 *
 * Output in dist/ (git-ignored):
 *   trackie-deploy-<stamp>.zip   changed/added files, paths relative to htdocs
 *   trackie-deploy-<stamp>.sql   new migrations since the base, in order (run in phpMyAdmin first)
 *   trackie-deploy-<stamp>.txt   manifest: files included + files deleted (remove these by hand)
 *
 * Only COMMITTED work is bundled, so what you deploy is always what's in git.
 * Never bundled: config/env.php (live secrets), dev scripts, database/ dumps,
 * docs, tooling. Uploading env.php would overwrite production credentials.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
chdir($root);

function git(string $args): string {
    $out = shell_exec('git ' . $args . ' 2>&1');
    return trim((string)$out);
}
function fail(string $msg): void { fwrite(STDERR, "✖ {$msg}\n"); exit(1); }

if (git('rev-parse --is-inside-work-tree') !== 'true') fail('Not a git repository.');

// ── --mark: record that HEAD is now live ─────────────────────────
if (in_array('--mark', $argv, true)) {
    $tag = 'deploy-' . date('Ymd-His');
    git('tag -a ' . escapeshellarg($tag) . ' -m ' . escapeshellarg('Deployed to production'));
    echo "✔ Tagged HEAD as {$tag}. The next bundle will contain changes since this point.\n";
    exit(0);
}

// ── Base ref: argument, else the latest deploy tag ───────────────
$base = null;
foreach (array_slice($argv, 1) as $a) if ($a[0] !== '-') $base = $a;
if ($base === null) {
    $tags = array_filter(explode("\n", git('tag -l "deploy-*" --sort=-creatordate')));
    $base = $tags ? reset($tags) : null;
}
if ($base === null) {
    fail("No deploy tag yet. Pass the last deployed commit, e.g.:\n  php scripts/deploy_bundle.php bdfb071\n"
       . "(or the first commit to bundle everything: php scripts/deploy_bundle.php \$(git rev-list --max-parents=0 HEAD))");
}
if (git('rev-parse --verify --quiet ' . escapeshellarg($base . '^{commit}')) === '') fail("Unknown git ref: {$base}");

$dirty = git('status --porcelain --untracked-files=no');
if ($dirty !== '') {
    echo "⚠ Uncommitted changes are NOT included (commit them first if they should ship):\n{$dirty}\n\n";
}

// ── Changed files ────────────────────────────────────────────────
$exclude = [
    '#^config/env\.php$#', '#^config/env\.php\.example$#',
    '#^scripts/#', '#^database/#', '#^docs?/#', '#^MD/#', '#^tests?/#', '#^dist/#', '#^\.claude/#', '#^\.github/#',
    '#^mobile/#', '#^node_modules/#',
    '#(^|/)\.gitignore$#', '#(^|/)\.gitkeep$#', '#\.md$#i', '#^package(-lock)?\.json$#', '#^composer\.(json|lock)$#',
];
$changed = $deleted = [];
foreach (explode("\n", git('diff --name-status --no-renames ' . escapeshellarg($base) . ' HEAD')) as $line) {
    if ($line === '' || !preg_match('/^([ACMDT])\t(.+)$/', $line, $m)) continue;
    [$status, $path] = [$m[1], $m[2]];
    $skip = false;
    foreach ($exclude as $re) if (preg_match($re, $path)) { $skip = true; break; }
    if ($skip) continue;
    if ($status === 'D') $deleted[] = $path; else $changed[] = $path;
}
// A new directory needs its .gitkeep so it exists on the server (e.g. storage/photos).
foreach (explode("\n", git('diff --name-only --diff-filter=A ' . escapeshellarg($base) . ' HEAD')) as $p) {
    if (preg_match('#(^|/)\.gitkeep$#', $p) && !preg_match('#^(scripts|database|docs?)/#', $p)) $changed[] = $p;
}
sort($changed);

// ── New migrations since the base, oldest first ──────────────────
$migrations = [];
foreach (explode("\n", git('diff --name-only --diff-filter=AM ' . escapeshellarg($base) . ' HEAD -- database/migrations')) as $p) {
    if ($p !== '' && str_ends_with($p, '.sql')) $migrations[] = $p;
}
sort($migrations);

if (!$changed && !$migrations && !$deleted) { echo "Nothing to deploy since {$base}.\n"; exit(0); }

// ── Write bundle ─────────────────────────────────────────────────
@mkdir($root . '/dist', 0755, true);
$stamp = date('Ymd-His');
$zipPath = "{$root}/dist/trackie-deploy-{$stamp}.zip";

if ($changed) {
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) fail('Cannot create zip.');
    foreach ($changed as $p) {
        // Contents exactly as committed at HEAD, not the working copy.
        $blob = shell_exec('git show ' . escapeshellarg('HEAD:' . $p));
        if ($blob === null) fail("Could not read {$p} from HEAD.");
        $zip->addFromString($p, $blob);
    }
    $zip->close();
}

if ($migrations) {
    $sql = "-- Trackie deploy {$stamp} — migrations since {$base}.\n"
         . "-- Run in phpMyAdmin (SQL tab) on the live database BEFORE uploading the files.\n"
         . "-- Every migration is additive and idempotent (safe to re-run).\n\n";
    foreach ($migrations as $m) {
        $sql .= "-- ===== {$m} =====\n" . rtrim((string)shell_exec('git show ' . escapeshellarg('HEAD:' . $m))) . "\n\n";
    }
    file_put_contents("{$root}/dist/trackie-deploy-{$stamp}.sql", $sql);
}

$head = git('rev-parse --short HEAD');
$manifest = "Trackie deploy {$stamp}\nBase: {$base}  →  HEAD: {$head}\n\n"
          . "1. phpMyAdmin → SQL tab → run trackie-deploy-{$stamp}.sql" . ($migrations ? '' : ' (none this time)') . "\n"
          . "2. File manager → htdocs → upload + extract trackie-deploy-{$stamp}.zip (overwrite)\n"
          . "3. Delete the files listed under DELETED (if any)\n"
          . "4. Smoke-test the live site, then: php scripts/deploy_bundle.php --mark\n\n"
          . "INCLUDED (" . count($changed) . "):\n  " . implode("\n  ", $changed ?: ['—']) . "\n\n"
          . "MIGRATIONS (" . count($migrations) . "):\n  " . implode("\n  ", $migrations ?: ['—']) . "\n\n"
          . "DELETED (" . count($deleted) . ") — remove from the server by hand:\n  " . implode("\n  ", $deleted ?: ['—']) . "\n";
file_put_contents("{$root}/dist/trackie-deploy-{$stamp}.txt", $manifest);

echo $manifest . "\n✔ Bundle written to dist/\n";

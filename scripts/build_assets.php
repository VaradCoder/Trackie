<?php
/**
 * Minify CSS/JS into *.min.css / *.min.js.
 *
 * Trackie has no npm/build pipeline on purpose (InfinityFree is plain PHP
 * hosting), so this is a dependency-free minifier run manually:
 *
 *     php scripts/build_assets.php
 *
 * head.php/footer.php prefer the .min file when it exists AND is newer than
 * its source, so a stale build is ignored rather than shipped. Forgetting to
 * run this degrades to serving the readable original — never a broken page.
 */

/* Guard: this file is reachable over the web (no shell on InfinityFree) and
   rewrites files on disk, so it must never be runnable by an anonymous
   visitor. CLI runs skip the check — there is no session on the command line,
   and shell access already implies server access. */
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../config/app.php';
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/functions.php';
    require_once __DIR__ . '/../includes/auth.php';
    requireAdmin();
    header('Content-Type: text/plain; charset=utf-8');
}

$root = dirname(__DIR__);

/** Conservative CSS minifier: comments, whitespace, last semicolons. */
function minifyCss(string $s): string {
    // Strip /* */ comments, but keep /*! important */ banners.
    $s = preg_replace('#/\*(?!!).*?\*/#s', '', $s);
    $s = preg_replace('/\s+/', ' ', $s);            // collapse whitespace
    $s = preg_replace('/\s*([{}:;,>~+])\s*/', '$1', $s);
    $s = str_replace(';}', '}', $s);                 // drop final semicolons
    $s = preg_replace('/;{2,}/', ';', $s);
    return trim($s);
}

/**
 * Conservative JS minifier: drops full-line // comments, /* * / blocks and
 * indentation only. It deliberately does NOT join lines or rename anything —
 * ASI hazards and template literals make that unsafe without a real parser,
 * and a subtly broken app.js is far worse than a few unsaved KB.
 */
function minifyJs(string $s): string {
    $out = [];
    $inBlock = false;
    foreach (explode("\n", $s) as $line) {
        $t = trim($line);

        if ($inBlock) {                              // inside /* ... */
            if (($p = strpos($t, '*/')) !== false) {
                $inBlock = false;
                $t = trim(substr($t, $p + 2));
                if ($t === '') continue;
            } else {
                continue;
            }
        }
        if ($t === '') continue;
        if (strpos($t, '//') === 0) continue;        // whole-line comment
        if (strpos($t, '/*') === 0) {                // whole-line block comment
            if (strpos($t, '*/') === false) { $inBlock = true; }
            continue;
        }
        $out[] = $t;
    }
    // Newlines are preserved as statement separators — this is what keeps
    // automatic semicolon insertion behaving exactly as in the source.
    return implode("\n", $out);
}

$targets = [
    'assets/css/app.css'       => 'assets/css/app.min.css',
    'assets/css/dashboard.css' => 'assets/css/dashboard.min.css',
    'assets/js/app.js'         => 'assets/js/app.min.js',
    'assets/js/dashboard.js'   => 'assets/js/dashboard.min.js',
    'assets/js/tour.js'        => 'assets/js/tour.min.js',
    'assets/js/capacitor-bridge.js' => 'assets/js/capacitor-bridge.min.js',
];

$totalIn = $totalOut = 0;

foreach ($targets as $src => $dst) {
    $srcPath = "$root/$src";
    if (!is_file($srcPath)) { echo "skip (missing): $src\n"; continue; }

    $raw = file_get_contents($srcPath);
    $min = substr($src, -3) === '.js' ? minifyJs($raw) : minifyCss($raw);

    file_put_contents("$root/$dst", $min);
    // Keep mtime ordering meaningful for the "is the build stale?" check.
    touch("$root/$dst", filemtime($srcPath) + 1);

    $in = strlen($raw); $out = strlen($min);
    $totalIn += $in; $totalOut += $out;
    printf("%-28s %7s B -> %7s B  (-%d%%)\n",
        basename($src), number_format($in), number_format($out),
        $in ? round((1 - $out / $in) * 100) : 0);
}

printf("\nTOTAL  %s B -> %s B  (-%d%%)\n",
    number_format($totalIn), number_format($totalOut),
    $totalIn ? round((1 - $totalOut / $totalIn) * 100) : 0);

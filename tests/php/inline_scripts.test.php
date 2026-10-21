<?php
/**
 * Inline page scripts that run BEFORE app.js must not use Trackie at load.
 *   php tests/php/inline_scripts.test.php
 *
 * On a full page load, a <script> inside #page-main (i.e. before the footer
 * include, which loads app.js / ui.js) runs while window.Trackie doesn't exist
 * yet. A top-level `const NL = Trackie.NL;` there threw, and everything after
 * it in the script — Today's and the Calendar's quick add — silently never
 * attached. In-app navigation hid it (scripts re-run after app.js), so only a
 * full load showed the bug. Using Trackie inside handlers is fine; reading it
 * at the top level of such a script is not.
 */
$pass = $fail = 0;
function ok(bool $c, string $n): void { global $pass, $fail; if ($c) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; } }

$FOOT = "include '../includes/footer.php'";
foreach (glob(__DIR__ . '/../../pages/*.php') as $file) {
    $src = file_get_contents($file);
    $foot = strpos($src, $FOOT);
    if ($foot === false) continue;
    $before = substr($src, 0, $foot);
    preg_match_all('~<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>~s', $before, $m, PREG_OFFSET_CAPTURE);
    $bad = [];
    foreach ($m[1] as [$code, $off]) {
        foreach (explode("\n", $code) as $i => $line) {
            // Top level of the script or of a one-level IIFE: indentation ≤ 2.
            if (preg_match('~^ {0,2}(?:(?:const|let|var)\s+[\w{}\s,]+=\s*)?(?:window\.)?Trackie\.~', $line)) {
                $bad[] = trim($line);
            }
        }
    }
    ok(!$bad, basename($file) . ($bad ? ': uses Trackie before app.js loads → ' . $bad[0] : ''));
}
echo "== $pass passed, $fail failed\n";
exit($fail ? 1 : 0);

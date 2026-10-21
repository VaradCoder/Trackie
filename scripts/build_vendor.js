/**
 * Builds the third-party browser libraries into assets/vendor/ so the web app,
 * the offline PWA and the Android shell never depend on a CDN at runtime.
 *
 *   npm install            (devDependencies: esbuild + the libraries below)
 *   node scripts/build_vendor.js
 *
 * The outputs are committed: the PHP server has no Node step, so production
 * serves exactly what is in the repo. Versions are pinned in package.json.
 */
const path = require('path');
const fs = require('fs');
const esbuild = require('esbuild');

const root = path.join(__dirname, '..');
const out = path.join(root, 'assets', 'vendor');
fs.mkdirSync(out, { recursive: true });

const libs = [
  // window.Motion — animate() / stagger() / spring (motion.dev). Was a CDN <script>.
  { file: 'motion.min.js', contents: "import { animate, stagger, spring, inView, scroll } from 'motion'; window.Motion = { animate, stagger, spring, inView, scroll };" },
  // window.confetti — celebration bursts (finishing the day, streak milestones).
  { file: 'confetti.min.js', entry: 'node_modules/canvas-confetti/dist/confetti.browser.js' },
  // window.chrono — natural-language dates for Quick add ("gym tomorrow 7am").
  // English only (chrono.en), which is what the quick-add parser needs.
  { file: 'chrono.min.js', contents: "import { en } from 'chrono-node'; window.chrono = en;" },
];

(async () => {
  for (const lib of libs) {
    const outfile = path.join(out, lib.file);
    const opts = {
      bundle: true, minify: true, format: 'iife', target: ['es2019'], outfile,
      legalComments: 'inline', logLevel: 'warning',
    };
    if (lib.contents) await esbuild.build({ ...opts, stdin: { contents: lib.contents, resolveDir: root, loader: 'js' } });
    else await esbuild.build({ ...opts, entryPoints: [path.join(root, lib.entry)] });
    console.log(lib.file.padEnd(18), (fs.statSync(outfile).size / 1024).toFixed(1), 'KB');
  }
})().catch(e => { console.error(e); process.exit(1); });

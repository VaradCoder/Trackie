/**
 * Trackie icon set — generated from one original vector design.
 *
 * Replaces the earlier icons, which were a watermarked stock-photo preview
 * (not licensed for use). Run from the repo root:
 *
 *   node mobile/resources/make-icons.js
 *
 * Writes the source images @capacitor/assets turns into every Android/iOS
 * launcher + splash size, and the website's PWA icons.
 */
const fs = require('fs');
const path = require('path');
const sharp = require('sharp');

const RED = '#ef4444', SLATE = '#334155', WHITE = '#ffffff', DARK = '#0f172a';
const root = path.join(__dirname, '..', '..');
const out = p => path.join(root, p);

/** The mark: a red target with an arrow in the bullseye, centred on (512,512). */
function mark({ scale = 1, ring = RED, gap = WHITE, arrow = SLATE } = {}) {
  return `
  <g transform="translate(512 512) scale(${scale}) translate(-512 -512)">
    <circle cx="512" cy="512" r="300" fill="${ring}"/>
    <circle cx="512" cy="512" r="236" fill="${gap}"/>
    <circle cx="512" cy="512" r="172" fill="${ring}"/>
    <circle cx="512" cy="512" r="108" fill="${gap}"/>
    <circle cx="512" cy="512" r="46"  fill="${ring}"/>
    <!-- arrow: shaft from the bullseye up-right, head buried in the centre -->
    <line x1="530" y1="494" x2="756" y2="268" stroke="${arrow}" stroke-width="30" stroke-linecap="round"/>
    <polygon points="512,512 556,500 524,468" fill="${arrow}"/>
    <!-- fletching -->
    <polygon points="742,282 760,196 802,238" fill="${arrow}"/>
    <polygon points="742,282 828,264 786,222" fill="${arrow}"/>
  </g>`;
}
const svg = (body, bg) => Buffer.from(
  `<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="1024" viewBox="0 0 1024 1024">${bg ? `<rect width="1024" height="1024" fill="${bg}"/>` : ''}${body}</svg>`);
const splashSvg = bg => Buffer.from(
  `<svg xmlns="http://www.w3.org/2000/svg" width="2732" height="2732" viewBox="0 0 2732 2732"><rect width="2732" height="2732" fill="${bg}"/>
   <g transform="translate(1366 1366) scale(0.62) translate(-512 -512)">${mark({ gap: bg })}</g></svg>`);

(async () => {
  const jobs = [
    // @capacitor/assets sources
    ['mobile/resources/icon-only.png',       svg(mark({ scale: 1.3 }), WHITE), 1024],
    ['mobile/resources/icon-foreground.png', svg(mark({ scale: 0.95 })), 1024],          // adaptive: keep inside the 66% safe zone
    ['mobile/resources/icon-background.png', svg('', WHITE), 1024],
    ['mobile/resources/splash.png',          splashSvg(WHITE), 2732],
    ['mobile/resources/splash-dark.png',     splashSvg(DARK), 2732],
    // Website / PWA
    ['assets/images/icon-512.png',           svg(mark({ scale: 1.3 }), WHITE), 512],
    ['assets/images/icon-192.png',           svg(mark({ scale: 1.3 }), WHITE), 192],
    ['assets/images/icon-maskable-512.png',  svg(mark({ scale: 1.0 }), WHITE), 512],    // maskable: safe zone
    ['assets/images/apple-touch-icon.png',   svg(mark({ scale: 1.2 }), WHITE), 180],
  ];
  for (const [file, src, size] of jobs) {
    await sharp(src).resize(size, size).png({ compressionLevel: 9 }).toFile(out(file));
    console.log('wrote', file, `${size}x${size}`);
  }
  // Keep a copy of the vector source next to the generator.
  fs.writeFileSync(out('mobile/resources/icon.svg'), svg(mark({ scale: 1.3 }), WHITE));
})().catch(e => { console.error(e); process.exit(1); });

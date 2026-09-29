/**
 * Trackie photo helpers (no dependencies).
 *
 * PhotoExif.read(file)  → camera settings from a JPEG's EXIF block, or {}.
 *   Only tags actually present are returned; nothing is guessed. GPS tags
 *   are never read.
 * PhotoExif.prepare(file) → a ≤2560px JPEG Blob for upload. Re-encoding bakes
 *   in the EXIF rotation and drops all metadata (including GPS) from the
 *   uploaded file — so EXIF must be read from the ORIGINAL first.
 */
(function (global) {
  'use strict';

  const TYPE_SIZE = { 1: 1, 2: 1, 3: 2, 4: 4, 5: 8, 7: 1, 9: 4, 10: 8 };

  function parseTiff(view, tiff) {
    const le = view.getUint16(tiff) === 0x4949;
    const u16 = o => view.getUint16(o, le);
    const u32 = o => view.getUint32(o, le);
    if (u16(tiff + 2) !== 42) return null;

    function readIfd(off) {
      const out = {};
      if (off <= 0 || tiff + off + 2 > view.byteLength) return out;
      const n = u16(tiff + off);
      for (let i = 0; i < n; i++) {
        const e = tiff + off + 2 + i * 12;
        if (e + 12 > view.byteLength) break;
        const tag = u16(e), type = u16(e + 2), count = u32(e + 4);
        const size = (TYPE_SIZE[type] || 1) * count;
        const vo = size > 4 ? tiff + u32(e + 8) : e + 8;
        if (vo + size > view.byteLength) continue;
        let v;
        if (type === 2) {
          v = '';
          for (let k = 0; k < count; k++) { const c = view.getUint8(vo + k); if (!c) break; v += String.fromCharCode(c); }
          v = v.trim();
        } else if (type === 3) v = u16(vo);
        else if (type === 4) v = u32(vo);
        else if (type === 5) { const d = u32(vo + 4); v = d ? u32(vo) / d : null; }
        else if (type === 10) { const d = view.getInt32(vo + 4, le); v = d ? view.getInt32(vo, le) / d : null; }
        out[tag] = v;
      }
      return out;
    }

    const ifd0 = readIfd(u32(tiff + 4));
    const exif = ifd0[0x8769] ? readIfd(ifd0[0x8769]) : {};
    return { ifd0, exif };
  }

  async function read(file) {
    try {
      if (!file || !/jpe?g$/i.test(file.type || file.name)) return {};
      const buf = await file.slice(0, 512 * 1024).arrayBuffer();
      const view = new DataView(buf);
      if (view.getUint16(0) !== 0xFFD8) return {};
      let off = 2;
      while (off + 4 < view.byteLength) {
        const marker = view.getUint16(off);
        const len = view.getUint16(off + 2);
        if (marker === 0xFFE1 && view.getUint32(off + 4) === 0x45786966) { // "Exif"
          const t = parseTiff(view, off + 10);
          return t ? toFields(t) : {};
        }
        if ((marker & 0xFF00) !== 0xFF00 || marker === 0xFFDA) break; // start of scan: no EXIF
        off += 2 + len;
      }
    } catch (e) { /* unreadable EXIF → nothing, never a guess */ }
    return {};
  }

  function toFields({ ifd0, exif }) {
    const out = {};
    const make = (ifd0[0x010F] || '').trim(), model = (ifd0[0x0110] || '').trim();
    if (model) out.camera = make && !model.toLowerCase().includes(make.split(' ')[0].toLowerCase()) ? `${make} ${model}` : model;
    if (exif[0xA434]) out.lens = exif[0xA434];
    if (exif[0x8827]) out.iso = exif[0x8827];
    const t = exif[0x829A];
    if (t) out.shutter = t >= 1 ? String(Math.round(t * 10) / 10) : `1/${Math.round(1 / t)}`;
    if (exif[0x829D]) out.aperture = Math.round(exif[0x829D] * 10) / 10;
    if (exif[0x920A]) out.focal_mm = Math.round(exif[0x920A] * 10) / 10;
    const dt = exif[0x9003] || ifd0[0x0132];
    const m = typeof dt === 'string' && dt.match(/^(\d{4}):(\d{2}):(\d{2}) (\d{2}:\d{2}:\d{2})/);
    if (m && m[1] !== '0000') out.taken_at = `${m[1]}-${m[2]}-${m[3]} ${m[4]}`;
    return out;
  }

  /** Downscale + re-encode for upload. Falls back to the original file. */
  async function prepare(file, maxPx = 2560, quality = 0.9) {
    if (typeof createImageBitmap !== 'function') return file;
    let bmp;
    try { bmp = await createImageBitmap(file, { imageOrientation: 'from-image' }); }
    catch { return file; }  // e.g. a format this browser can't decode
    const scale = Math.min(1, maxPx / Math.max(bmp.width, bmp.height));
    const w = Math.round(bmp.width * scale), h = Math.round(bmp.height * scale);
    const canvas = document.createElement('canvas');
    canvas.width = w; canvas.height = h;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, w, h);
    ctx.drawImage(bmp, 0, 0, w, h);
    bmp.close && bmp.close();
    const blob = await new Promise(r => canvas.toBlob(r, 'image/jpeg', quality));
    // Always prefer the re-encoded copy: it carries no GPS or other metadata.
    return blob || file;
  }

  global.PhotoExif = { read, prepare };
})(window);

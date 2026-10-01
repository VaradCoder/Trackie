/**
 * Trackie — Android background job (Capacitor Background Runner).
 *
 * Runs in Android WorkManager about every 15 minutes, even when the app is
 * closed (Android may stretch the interval to save battery). It asks the
 * server which reminders fire in the next few hours and schedules them as
 * local notifications, so reminders added on the website reach the phone
 * without opening the app.
 *
 * This is NOT a browser: no DOM, no cookies. It authenticates with the
 * device token the app received at sign-in (api/device.php), stored here via
 * the 'configure' event. InfinityFree's bot check needs a `__test` cookie that
 * expires every 6 h: the app passes its current one, and this job solves the
 * check itself when it expires (TrackieHostChallenge below).
 *
 * Horizon is deliberately short (3 h): this runner can schedule but not
 * cancel, so a reminder deleted on the web can only linger until the next
 * time the app opens (which reschedules everything for 7 days).
 */

const HORIZON_HOURS = 3;

/**
 * InfinityFree bot check, solved without a browser.
 *
 * Requests without the `__test` cookie get a page that computes it as
 *   __test = hex( AES-128-CBC-decrypt(c, key = a, iv = b) )
 * with a, b, c given as hex in the page (slowAES.decrypt(c, 2, a, b)).
 * The cookie lasts 6 hours, so the background job must be able to renew it
 * on its own. Pure JS: the Background Runner engine has no WebCrypto.
 *
 * Exposes: TrackieHostChallenge.solve(html) → '__test=…' or null.
 */
var TrackieHostChallenge = (function () {
  var SBOX = [], INV = [];
  (function init() {
    // Rijndael S-box via the multiplicative inverse in GF(2^8) + affine map.
    var p = 1, q = 1;
    do {
      p = p ^ ((p << 1) & 0xff) ^ (p & 0x80 ? 0x1b : 0);
      q ^= q << 1; q ^= q << 2; q ^= q << 4; q &= 0xff; if (q & 0x80) q ^= 0x09;
      var x = q ^ ((q << 1) | (q >> 7)) ^ ((q << 2) | (q >> 6)) ^ ((q << 3) | (q >> 5)) ^ ((q << 4) | (q >> 4));
      x = (x ^ 0x63) & 0xff;
      SBOX[p] = x; INV[x] = p;
    } while (p !== 1);
    SBOX[0] = 0x63; INV[0x63] = 0;
  })();

  function xtime(b) { return ((b << 1) ^ (b & 0x80 ? 0x1b : 0)) & 0xff; }
  function mul(a, b) { var r = 0; while (b) { if (b & 1) r ^= a; a = xtime(a); b >>= 1; } return r; }

  function expandKey(key) {                       // 16-byte key → 11 round keys
    var w = key.slice(), rcon = 1;
    for (var i = 16; i < 176; i += 4) {
      var t = w.slice(i - 4, i);
      if (i % 16 === 0) {
        t = [SBOX[t[1]] ^ rcon, SBOX[t[2]], SBOX[t[3]], SBOX[t[0]]];
        rcon = xtime(rcon);
      }
      for (var j = 0; j < 4; j++) w[i + j] = w[i - 16 + j] ^ t[j];
    }
    return w;
  }

  function decryptBlock(block, w) {
    var s = block.slice(), r, i, c;
    for (i = 0; i < 16; i++) s[i] ^= w[160 + i];
    for (r = 9; r >= 0; r--) {
      // inverse shift rows
      var t = s.slice();
      for (c = 0; c < 4; c++) for (i = 0; i < 4; i++) s[i + 4 * ((c + i) % 4)] = t[i + 4 * c];
      for (i = 0; i < 16; i++) s[i] = INV[s[i]];               // inverse sub bytes
      for (i = 0; i < 16; i++) s[i] ^= w[16 * r + i];           // add round key
      if (r > 0) {                                              // inverse mix columns
        for (c = 0; c < 4; c++) {
          var a0 = s[4 * c], a1 = s[4 * c + 1], a2 = s[4 * c + 2], a3 = s[4 * c + 3];
          s[4 * c]     = mul(a0, 14) ^ mul(a1, 11) ^ mul(a2, 13) ^ mul(a3, 9);
          s[4 * c + 1] = mul(a0, 9)  ^ mul(a1, 14) ^ mul(a2, 11) ^ mul(a3, 13);
          s[4 * c + 2] = mul(a0, 13) ^ mul(a1, 9)  ^ mul(a2, 14) ^ mul(a3, 11);
          s[4 * c + 3] = mul(a0, 11) ^ mul(a1, 13) ^ mul(a2, 9)  ^ mul(a3, 14);
        }
      }
    }
    return s;
  }

  function hexToBytes(h) { var o = []; for (var i = 0; i < h.length; i += 2) o.push(parseInt(h.substr(i, 2), 16)); return o; }
  function bytesToHex(b) { return b.map(function (x) { return (x < 16 ? '0' : '') + x.toString(16); }).join(''); }

  /** AES-128-CBC decrypt (no padding removal — matches slowAES mode 2 as used by the host). */
  function decryptCbcHex(cHex, keyHex, ivHex) {
    var w = expandKey(hexToBytes(keyHex)), c = hexToBytes(cHex), prev = hexToBytes(ivHex), out = [];
    for (var i = 0; i < c.length; i += 16) {
      var blk = c.slice(i, i + 16), p = decryptBlock(blk, w);
      for (var j = 0; j < 16; j++) out.push(p[j] ^ prev[j]);
      prev = blk;
    }
    return bytesToHex(out);
  }

  function solve(html) {
    var m = String(html || '').match(/a=toNumbers\("([0-9a-f]{32})"\),b=toNumbers\("([0-9a-f]{32})"\),c=toNumbers\("([0-9a-f]{32,})"\)/i);
    return m ? '__test=' + decryptCbcHex(m[3], m[1], m[2]) : null;
  }

  return { solve: solve, decryptCbcHex: decryptCbcHex };
})();

function kv(key) {
  try { const r = CapacitorKV.get(key); return r && r.value ? r.value : ''; } catch (e) { return ''; }
}

// The app hands over everything this job needs when the user signs in.
addEventListener('configure', (resolve, reject, args) => {
  try {
    if (!args || !args.token || !args.base) throw new Error('missing token/base');
    CapacitorKV.set('deviceToken', String(args.token));
    CapacitorKV.set('base', String(args.base));
    CapacitorKV.set('testCookie', String(args.testCookie || ''));
    CapacitorKV.set('appVersion', String(args.appVersion || ''));
    resolve({ ok: true });
  } catch (e) { reject(e); }
});

// Sign-out: stop syncing.
addEventListener('clear', (resolve) => {
  ['deviceToken', 'base', 'testCookie'].forEach(k => { try { CapacitorKV.remove(k); } catch (e) {} });
  resolve({ ok: true });
});

addEventListener('syncReminders', async (resolve, reject) => {
  try {
    const token = kv('deviceToken'), base = kv('base');
    if (!token || !base) { resolve({ skipped: 'not signed in' }); return; }
    try {
      const net = CapacitorDevice.getNetworkStatus();
      if (net && net.connected === false) { resolve({ skipped: 'offline' }); return; }
    } catch (e) { /* unknown → try anyway */ }

    const post = (cookie) => fetch(base + '/api/device.php', {
      method: 'POST',
      headers: Object.assign({
        'Authorization': 'Bearer ' + token,
        'X-Trackie-Device': token,                   // some hosts strip Authorization
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest',
      }, cookie ? { 'Cookie': cookie } : {}),
      body: 'action=sync&hours=' + HORIZON_HOURS + '&app_version=' + encodeURIComponent(kv('appVersion')),
    });

    let res = await post(kv('testCookie'));
    let text = await res.text();
    // The host's bot-check page (cookie missing/expired after 6 h): solve it, retry once.
    if (!/^\s*[{\[]/.test(text)) {
      const fresh = TrackieHostChallenge.solve(text);
      if (!fresh) { resolve({ skipped: 'host challenge (unrecognised)' }); return; }
      CapacitorKV.set('testCookie', fresh);
      res = await post(fresh);
      text = await res.text();
    }

    if (res.status === 401) {                          // revoked in Settings / signed out elsewhere
      ['deviceToken', 'testCookie'].forEach(k => { try { CapacitorKV.remove(k); } catch (e) {} });
      resolve({ skipped: 'revoked' });
      return;
    }
    let data;
    try { data = JSON.parse(text); } catch (e) { resolve({ skipped: 'unexpected response' }); return; }
    if (!data || !data.success) { resolve({ skipped: 'server refused' }); return; }

    const now = Date.now();
    const list = (data.occurrences || [])
      .filter(o => o.ts * 1000 > now)
      .map(o => ({
        id: o.id,                                      // stable per reminder + time → replaces, never duplicates
        title: '⏰ ' + o.title,
        body: o.body,
        scheduleAt: new Date(o.ts * 1000),
        channelId: 'trackie_reminders',
        smallIcon: 'ic_stat_trackie',
        autoCancel: true,
        extra: { url: '/pages/reminders.php' },
      }));
    if (list.length) CapacitorNotifications.schedule(list);
    resolve({ scheduled: list.length });
  } catch (e) {
    reject(e);
  }
});

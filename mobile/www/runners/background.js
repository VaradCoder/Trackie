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
 * the 'configure' event. InfinityFree's bot check also needs its `__test`
 * cookie, which the app passes along from its web view.
 *
 * Horizon is deliberately short (3 h): this runner can schedule but not
 * cancel, so a reminder deleted on the web can only linger until the next
 * time the app opens (which reschedules everything for 7 days).
 */

const HORIZON_HOURS = 3;

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

    const cookie = kv('testCookie');
    const res = await fetch(base + '/api/device.php', {
      method: 'POST',
      headers: Object.assign({
        'Authorization': 'Bearer ' + token,
        'X-Trackie-Device': token,                   // some hosts strip Authorization
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest',
      }, cookie ? { 'Cookie': cookie } : {}),
      body: 'action=sync&hours=' + HORIZON_HOURS + '&app_version=' + encodeURIComponent(kv('appVersion')),
    });

    if (res.status === 401) {                          // revoked in Settings / signed out elsewhere
      ['deviceToken', 'testCookie'].forEach(k => { try { CapacitorKV.remove(k); } catch (e) {} });
      resolve({ skipped: 'revoked' });
      return;
    }
    const text = await res.text();
    let data;
    try { data = JSON.parse(text); } catch (e) {
      // Host bot-check page instead of JSON: wait for the app to refresh the cookie.
      resolve({ skipped: 'host challenge' });
      return;
    }
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

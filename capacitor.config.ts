import { CapacitorConfig } from '@capacitor/cli';

/**
 * Trackie — Capacitor configuration (Android + iOS shells).
 *
 * The app is a native shell that loads the live site at
 * https://trackie.free.nf. Every web deploy therefore reaches the app
 * instantly — no new APK. Only native changes (plugins, permissions, this
 * file) need a new build, which the app offers itself (capacitor-bridge.js
 * checks GitHub Releases).
 *
 * Capacitor injects its native bridge into the remote page, so the script on
 * the website (assets/js/capacitor-bridge.js) can use the plugins below.
 *
 * Notifications reach the phone three ways:
 *   1. LocalNotifications — reminders scheduled as Android alarms; they fire
 *      on time even when the app is closed or the phone is offline.
 *   2. BackgroundRunner — an Android WorkManager job (~every 15 min) that
 *      re-syncs reminders with the server using a revocable device token
 *      (mobile/www/runners/background.js).
 *   3. PushNotifications — Firebase Cloud Messaging for server-originated
 *      notifications (achievements, goals, weekly review). Needs
 *      google-services.json at build time (see mobile/README.md).
 */
const config: CapacitorConfig = {
  appId: 'com.varad.trackie',
  appName: 'Trackie',
  webDir: 'mobile/www',

  server: {
    url: 'https://trackie.free.nf',
    cleartext: false,            // site is HTTPS only
    androidScheme: 'https',
    // Allow these hosts to open INSIDE the app webview; everything else
    // opens in the system browser (handled by capacitor-bridge.js).
    allowNavigation: ['trackie.free.nf', 'thetrackie.in']
  },

  android: {
    path: 'mobile/android',
    // Lets capacitor-bridge.js tell the native app apart from a browser.
    appendUserAgent: 'TrackieApp/Android'
  },

  plugins: {
    SplashScreen: {
      launchShowDuration: 1500,
      launchAutoHide: false,     // hidden from JS once the page is ready
      backgroundColor: '#0f172a',
      showSpinner: false,
      splashFullScreen: true,
      splashImmersive: true
    },
    StatusBar: {
      style: 'DARK',             // dark background → light icons
      backgroundColor: '#0f172a',
      overlaysWebView: false
    },
    LocalNotifications: {
      smallIcon: 'ic_stat_trackie',
      iconColor: '#ef4444'
    },
    PushNotifications: {
      // Show pushes as system notifications even while the app is open.
      presentationOptions: ['badge', 'sound', 'alert']
    },
    BackgroundRunner: {
      label: 'com.varad.trackie.sync',
      src: 'runners/background.js',
      event: 'syncReminders',
      repeat: true,
      interval: 15,              // minutes — Android's minimum for periodic work
      autoStart: true
    }
  },

  ios: {
    path: 'mobile/ios',
    backgroundColor: '#0f172a',
    contentInset: 'always'
  }
};

export default config;

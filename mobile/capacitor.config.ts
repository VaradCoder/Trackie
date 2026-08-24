import { CapacitorConfig } from '@capacitor/cli';

/**
 * Trackie — Capacitor configuration.
 *
 * The app is a native shell that loads the live site at
 * https://trackie.free.nf. Capacitor injects its native bridge into that
 * remote page, so the small script on the website (capacitor-bridge.js)
 * can use App / SplashScreen / StatusBar / Browser plugins.
 */
const config: CapacitorConfig = {
  appId: 'com.varad.trackie',
  appName: 'Trackie',
  webDir: 'www',

  server: {
    url: 'https://trackie.free.nf',
    cleartext: false,            // site is HTTPS only
    androidScheme: 'https',
    // Allow these hosts to open INSIDE the app webview; everything else
    // opens in the system browser (handled by capacitor-bridge.js).
    allowNavigation: ['trackie.free.nf']
  },

  plugins: {
    SplashScreen: {
      launchShowDuration: 1500,
      launchAutoHide: false,     // we hide it from JS once the page is ready
      backgroundColor: '#0f172a',
      showSpinner: false,
      splashFullScreen: true,
      splashImmersive: true
    },
    StatusBar: {
      style: 'DARK',             // dark background → light icons
      backgroundColor: '#0f172a',
      overlaysWebView: false
    }
  },

  ios: {
    backgroundColor: '#0f172a',
    contentInset: 'always'
  }
};

export default config;

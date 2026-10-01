# Trackie for Android

A native shell (Capacitor 8) around the live site **https://trackie.free.nf**.

| | How |
| --- | --- |
| **Updates** | The app loads the live site, so every website deploy reaches the app instantly. A new APK is only needed when the native shell changes; the app checks GitHub Releases twice a day and offers the download. |
| **Reminders** | Scheduled as Android alarms (LocalNotifications): they fire on time with the app closed and the phone offline. Opening the app reschedules the next 7 days. |
| **Background** | Android WorkManager job (Background Runner, ~every 15 min): re-syncs the next 3 h of reminders with `api/device.php` using this install's device token, so reminders added on the website arrive without opening the app. |
| **Push** | Firebase Cloud Messaging for server notifications (achievements, goals reached, weekly review). Optional; see below. |

Files: `capacitor.config.ts` (repo root) · `mobile/android/` (native project) ·
`mobile/www/runners/background.js` (background job) ·
`assets/js/capacitor-bridge.js` (runs inside the app, served by the website) ·
`api/device.php` + `includes/native.php` (server) · `.github/workflows/android.yml` (build).

## One-time setup

### 1. Signing key → GitHub secrets (required for installable updates)

The key was generated on the dev machine as `keys/trackie-release.p12`
(git-ignored). **Back it up** with `keys/trackie-release-secrets.txt` —
losing it means future updates can't install over the app.

GitHub → Settings → Secrets and variables → Actions → *New repository secret*:

| Secret | Value |
| --- | --- |
| `ANDROID_KEYSTORE_BASE64` | the one line in `keys/trackie-release.p12.base64.txt` |
| `ANDROID_KEYSTORE_PASSWORD` | from `keys/trackie-release-secrets.txt` |
| `ANDROID_KEY_ALIAS` | `trackie` |
| `ANDROID_KEY_PASSWORD` | same as the keystore password |

Then Actions → **Android app** → *Run workflow*. The signed APK appears under
Releases (`android-<versionCode>`).

### 2. Firebase push (optional)

1. https://console.firebase.google.com → *Add project* (Analytics not needed).
2. *Add app* → Android → package name **`com.varad.trackie`** → download
   `google-services.json`.
3. GitHub secret `GOOGLE_SERVICES_JSON_BASE64` = base64 of that file
   (`base64 -w0 google-services.json`).
4. Project settings → *Service accounts* → *Generate new private key* →
   upload the JSON to the server as **`keys/fcm-service-account.json`**
   (both web roots). Never commit it.
5. Re-run the workflow. Admin → Configuration shows push as configured.

Without Firebase everything else works — reminders are alarms either way.

## Installing

On the phone: open the latest release → download the APK → open it → allow
*Install unknown apps* for the browser once. Later updates install over the
top. Sign in once; the app asks for notification permission and (Android
12+) *Alarms & reminders* access so reminders arrive on the minute.

Some phone brands (Xiaomi, Oppo, Vivo, Samsung…) kill background apps
aggressively — if background sync stops, exempt Trackie from battery
optimisation (see dontkillmyapp.com). Alarms already scheduled still fire.

## Local development

```bash
npm install
npx cap sync android        # copies www/ + plugin config into mobile/android
npx cap open android        # Android Studio (needs JDK 21 + Android SDK)
```

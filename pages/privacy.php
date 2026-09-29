<?php
/**
 * Public Privacy Policy. Written to match what the code actually does —
 * update it whenever a new integration, data type or processor is added.
 * Contact address comes from SUPPORT_EMAIL in config/env.php.
 */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

$pageTitle    = 'Privacy Policy';
$metaIndex = true;
$metaDescription = 'What data Trackie stores, why, who it is shared with, and your choices.';
$effective    = 'September 29, 2026';
$supportEmail = (string)env('SUPPORT_EMAIL');
$site         = $_SERVER['HTTP_HOST'] ?? 'Trackie';

require_once '../includes/head.php';
?>
<div class="legal-wrap">
  <article class="legal-card">
    <a class="legal-brand" href="<?= APP_BASE ?>/"><i class="fas fa-rocket"></i> Trackie</a>
    <h1>Privacy Policy</h1>
    <p class="legal-meta">Effective <?= h($effective) ?> · applies to <?= h($site) ?></p>

    <p>Trackie is a personal productivity and life-tracking app. This policy explains what data Trackie stores,
      why, who else it is shared with, and the choices you have. Trackie does not sell your data, does not show ads,
      and does not use third-party analytics or tracking scripts.</p>

    <h2>1. Data you give us</h2>
    <ul>
      <li><strong>Account:</strong> your name, email address, and password (stored only as a one-way hash). Optionally a profile picture, your chosen hobbies and preferences.</li>
      <li><strong>What you track:</strong> todos, habits, goals, routines, study tasks, reminders, focus sessions, finance entries, workouts, nutrition and recovery check-ins, and hobby records such as books, games, recipes, photos, writing and plants.</li>
      <li><strong>Photos you upload</strong> (Photography): stored in private storage that is only served to your signed-in account. Before upload, your browser re-encodes each photo, which removes GPS location and other embedded metadata. Camera settings (camera, lens, ISO, shutter, aperture, focal length, date taken) are read first and saved so they can be shown to you.</li>
    </ul>

    <h2>2. Data from services you choose to connect</h2>
    <p>Integrations are optional and off until you connect them. Access tokens are encrypted at rest (AES-256-GCM).
      You can disconnect any integration in Settings, which deletes its tokens and synced data.</p>
    <ul>
      <li><strong>Steam:</strong> your SteamID, public game library, playtime, achievement counts, and your Steam friends' public profile names and libraries (only to find games you share).</li>
      <li><strong>GitHub:</strong> your public profile, repositories and recent public activity (scopes <code>read:user</code>, <code>public_repo</code>).</li>
      <li><strong>Google Calendar &amp; Tasks:</strong> read-only access to upcoming events on your primary calendar and your open tasks.</li>
      <li><strong>Spotify:</strong> your profile, currently playing and recently played tracks, top artists/tracks, playlists, and playback control for the in-app player.</li>
    </ul>
    <p><strong>Google user data:</strong> Trackie's use and transfer of information received from Google APIs adheres to the
      <a href="https://developers.google.com/terms/api-services-user-data-policy" target="_blank" rel="noopener">Google API Services User Data Policy</a>,
      including the Limited Use requirements. Google data is used only to show your own calendar and tasks inside Trackie; it is not
      used for advertising, not sold, and not read by people except where you ask for help or the law requires it.</p>

    <h2>3. Services Trackie uses to provide features</h2>
    <ul>
      <li><strong>Hosting:</strong> Trackie runs on InfinityFree shared hosting; your data is stored in its MySQL database and file storage.</li>
      <li><strong>Catalogue lookups</strong> (no personal data sent, only search terms or IDs): Open Library (books), Steam Store (game genres), ExerciseDB / WorkoutDB (exercise demos), Open-Meteo (weather for your location setting).</li>
      <li><strong>AI insights</strong> (only when you request one and the feature is enabled): a summary of your statistics for that module — counts, streaks and item names such as exercise names — is sent to Google Gemini or OpenAI to generate a short tip. Your name and email are not included.</li>
      <li><strong>Email:</strong> password-reset emails are sent through an email delivery provider (such as Brevo), which receives your email address and the message.</li>
      <li><strong>Content delivery:</strong> fonts, icons and a few scripts load from Google Fonts, cdnjs and jsDelivr, and the Spotify player from Spotify. These providers receive your IP address as part of normal web requests.</li>
    </ul>

    <h2>4. Cookies and local storage</h2>
    <ul>
      <li><strong>Session cookie</strong> — keeps you signed in during a visit.</li>
      <li><strong>Remember-me cookie</strong> — only if you tick “Remember me”; keeps you signed in for up to 30 days. The server stores only a hash of it.</li>
      <li><strong>Browser storage</strong> — interface preferences (theme, view choices), a running timer, and an offline copy of pages you visited (service worker). This stays on your device.</li>
    </ul>
    <p>Trackie sets no advertising or cross-site tracking cookies.</p>

    <h2>5. How your data is used</h2>
    <p>Only to run Trackie for you: storing and showing your records, computing your statistics, streaks, XP and insights,
      sending reminders you set up, securing your account, and fixing problems. Server logs may record technical errors
      (never passwords or tokens) for troubleshooting.</p>

    <h2>6. Security</h2>
    <p>Passwords are hashed, integration tokens are encrypted, requests are protected against cross-site request forgery,
      and each record is checked against your account before it is shown or changed. No system is perfectly secure;
      please use a unique password.</p>

    <h2>7. Retention and your choices</h2>
    <ul>
      <li>You can edit or delete individual records at any time, and disconnect integrations in Settings.</li>
      <li>Your data is kept while your account exists. To get a copy of your data or delete your account and all its data,
        <?php if ($supportEmail): ?>email <a href="mailto:<?= h($supportEmail) ?>"><?= h($supportEmail) ?></a> from your account's address<?php else: ?>contact the site operator<?php endif; ?>;
        requests are completed within 30 days. Self-service export and deletion are being added to Settings.</li>
      <li>Depending on where you live (for example the EU/UK under the GDPR, or India under the DPDP Act), you may have rights
        to access, correct, delete or port your data, and to complain to a data-protection authority.</li>
    </ul>

    <h2>8. Children</h2>
    <p>Trackie is not directed at children under 13, and we do not knowingly collect their data. If you believe a child has
      created an account, contact us and we will delete it.</p>

    <h2>9. Changes</h2>
    <p>If this policy changes in a meaningful way, the effective date above will change and signed-in users will be told in the app.</p>

    <h2>10. Contact</h2>
    <p><?php if ($supportEmail): ?>Questions or requests: <a href="mailto:<?= h($supportEmail) ?>"><?= h($supportEmail) ?></a>.<?php else: ?>Questions or requests: contact the site operator.<?php endif; ?></p>

    <p class="legal-foot"><a href="<?= APP_BASE ?>/pages/terms.php">Terms of Service</a> · <a href="<?= APP_BASE ?>/">Back to Trackie</a></p>
  </article>
</div>
</body></html>

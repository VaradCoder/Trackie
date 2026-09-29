<?php
/** Public Terms of Service. Contact address comes from SUPPORT_EMAIL in config/env.php. */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

$pageTitle    = 'Terms of Service';
$effective    = 'September 29, 2026';
$supportEmail = (string)env('SUPPORT_EMAIL');

require_once '../includes/head.php';
?>
<div class="legal-wrap">
  <article class="legal-card">
    <a class="legal-brand" href="<?= APP_BASE ?>/"><i class="fas fa-rocket"></i> Trackie</a>
    <h1>Terms of Service</h1>
    <p class="legal-meta">Effective <?= h($effective) ?></p>

    <p>By creating an account or using Trackie you agree to these terms. If you don't agree, please don't use Trackie.</p>

    <h2>1. The service</h2>
    <p>Trackie is a free personal productivity and life-tracking app. Features may change, be added or be removed.
      Trackie is provided by an independent developer and runs on free hosting, so it may occasionally be slow or unavailable.</p>

    <h2>2. Your account</h2>
    <ul>
      <li>Give a valid email address and keep your password private. You're responsible for activity on your account.</li>
      <li>You must be at least 13 years old (or the minimum age for online services in your country).</li>
      <li>Tell us promptly if you think your account has been accessed without permission.</li>
    </ul>

    <h2>3. Your content</h2>
    <p>You own what you put into Trackie — your records, notes and photos. You give Trackie permission to store, process and
      display that content only to provide the service to you. Don't upload content you don't have the right to use, or
      anything illegal.</p>

    <h2>4. Acceptable use</h2>
    <p>Don't try to access other people's data, disrupt or overload the service, probe it for vulnerabilities without
      permission, scrape it, or use it to send spam. Found a security issue? Please report it privately
      <?php if ($supportEmail): ?>to <a href="mailto:<?= h($supportEmail) ?>"><?= h($supportEmail) ?></a><?php endif; ?>.</p>

    <h2>5. Connected services</h2>
    <p>Integrations such as Steam, GitHub, Google and Spotify are provided by those companies under their own terms.
      Trackie only uses the access you grant, and you can disconnect at any time in Settings.</p>

    <h2>6. Not professional advice</h2>
    <p>Fitness, nutrition, recovery, finance and other insights in Trackie are for general information and motivation only.
      They are not medical, financial or other professional advice. Check with a qualified professional before making
      health or financial decisions.</p>

    <h2>7. Availability and data</h2>
    <p>Trackie is provided “as is” without warranties of any kind. We work to keep your data safe, but we can't guarantee
      the service will be uninterrupted or error-free, so keep your own copy of anything important.</p>

    <h2>8. Limitation of liability</h2>
    <p>To the extent the law allows, Trackie and its developer are not liable for indirect or consequential losses, or for
      lost data or profits, arising from your use of the service.</p>

    <h2>9. Ending your use</h2>
    <p>You can stop using Trackie at any time and ask for your account to be deleted. We may suspend accounts that break
      these terms or put the service or other users at risk.</p>

    <h2>10. Changes</h2>
    <p>We may update these terms. If a change is significant, the effective date will change and signed-in users will be told
      in the app. Continuing to use Trackie after that means you accept the new terms.</p>

    <h2>11. Contact</h2>
    <p><?php if ($supportEmail): ?>Questions: <a href="mailto:<?= h($supportEmail) ?>"><?= h($supportEmail) ?></a>.<?php else: ?>Questions: contact the site operator.<?php endif; ?></p>

    <p class="legal-foot"><a href="<?= APP_BASE ?>/pages/privacy.php">Privacy Policy</a> · <a href="<?= APP_BASE ?>/">Back to Trackie</a></p>
  </article>
</div>
</body></html>

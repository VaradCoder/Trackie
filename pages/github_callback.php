<?php
/**
 * GitHub OAuth callback — receives code from GitHub, exchanges for a token.
 * Requires GITHUB_CLIENT_ID and GITHUB_CLIENT_SECRET (config/env.php).
 * Register this exact callback URL in the GitHub OAuth App settings:
 *   https://trackie.free.nf/pages/github_callback.php
 */
require_once '../config/app.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/providers.php';
require_once '../includes/oauth.php';

requireAuth();

$clientId     = env('GITHUB_CLIENT_ID');
$clientSecret = env('GITHUB_CLIENT_SECRET');
$redirectUri  = oauthRedirectUri('github_callback.php');

if (!$clientId || !$clientSecret) {
    flash('error', 'GitHub integration is not configured on this server.');
    redirect(APP_BASE . '/pages/settings.php');
}

// Where to land afterwards: the Coding page when connected from there.
$back = ($_SESSION['github_return'] ?? '') === 'projects' ? APP_BASE . '/pages/projects.php?tab=github' : APP_BASE . '/pages/settings.php';

// Step 1: redirect to GitHub's authorization screen
if (!isset($_GET['code']) && !isset($_GET['error'])) {
    $state = generateToken(8);
    $_SESSION['github_state'] = $state;
    $_SESSION['github_return'] = ($_GET['from'] ?? '') === 'projects' ? 'projects' : 'settings';
    // Private repositories are opt-in: GitHub's only scope for them (`repo`)
    // also allows writing, so it is never requested by default.
    $wantPrivate = !empty($_GET['private']);

    $authUrl = 'https://github.com/login/oauth/authorize?' . http_build_query([
        'client_id'    => $clientId,
        // Scopes come from the provider so there is ONE definition of what
        // Trackie asks for. This previously hardcoded 'repo' — full read/write
        // on private repositories — which Trackie has no use for. The provider
        // declares the minimum: read:user + public_repo.
        'scope'        => implode(' ', $wantPrivate ? provider('github')->privateScopes() : provider('github')->scopes()),
        'redirect_uri' => $redirectUri,
        'state'        => $state,
    ]);
    redirect($authUrl);
}

// GitHub returned an error (user denied, etc.)
if (isset($_GET['error'])) {
    // GitHub sends error=redirect_uri_mismatch here (to the REGISTERED
    // callback) when Trackie is opened on a host the OAuth app doesn't know.
    flash('error', oauthErrorMessage('GitHub', (string)$_GET['error'], (string)($_GET['error_description'] ?? ''), $redirectUri));
    redirect($back);
}

// State validation (CSRF protection for the OAuth flow itself)
if (($_GET['state'] ?? '') === '' || ($_GET['state'] ?? '') !== ($_SESSION['github_state'] ?? '')) {
    flash('error', 'GitHub sign-in expired or was opened in another tab. Please connect again.');
    redirect($back);
}
unset($_SESSION['github_state'], $_SESSION['github_return']);

// Step 2: exchange the authorization code for an access token
$ch = curl_init('https://github.com/login/oauth/access_token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'code'          => $_GET['code'],
        'redirect_uri'  => $redirectUri,
    ]),
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_TIMEOUT => 10,
]);
$resp = curl_exec($ch);
curl_close($ch);

$data = json_decode($resp, true);

if (empty($data['access_token'])) {
    // GitHub answers 200 with {"error": "..."} on a bad exchange.
    flash('error', oauthErrorMessage('GitHub', (string)($data['error'] ?? 'no_token'), (string)($data['error_description'] ?? ''), $redirectUri));
    redirect($back);
}

// Persist through the provider: the token is ENCRYPTED at rest in
// user_integrations (AES-256-GCM) rather than living in the PHP session, so
// the connection survives logout and is available to background syncs.
$gh = provider('github');
if (!$gh) {
    flash('error', 'GitHub provider is not available on this server.');
    redirect($back);
}

try {
    $gh->storeTokens(
        currentUserId(),
        $data['access_token'],
        $data['refresh_token'] ?? '',
        isset($data['expires_in']) ? (int)$data['expires_in'] : null,
        '',
        array_filter(explode(',', $data['scope'] ?? ''))
    );
} catch (Throwable $e) {
    // Most likely cause: TRACKIE_ENCRYPTION_KEY missing. crypto.php fails
    // closed by design — better to refuse the connection than to store a
    // bare token. Never echo the exception (it can carry the token).
    flash('error', 'Could not securely store the GitHub token. Check the server encryption key.');
    redirect($back);
}

// First sync immediately so the module has data to show right away.
// Isolated — a sync failure must not fail the connection itself.
$res = runSync($gh, currentUserId());

flash('success', $res['ok']
    ? 'GitHub connected — found ' . (int)(fetchOne("SELECT COUNT(*) n FROM integration_data WHERE user_id=? AND provider='github' AND kind='repo'", [currentUserId()])['n'] ?? 0) . ' repositories.'
    : 'GitHub connected, but the first sync failed: ' . $res['error']);
redirect($back);

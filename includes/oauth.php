<?php
/**
 * Shared OAuth-callback helpers (GitHub, Google, Spotify, …).
 * Error messages name the real cause instead of a generic "try again",
 * and never include tokens, codes or client secrets.
 */

/** The callback URL for this request's host — must be registered with the provider. */
function oauthRedirectUri(string $callbackFile): string {
    return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
         . '://' . $_SERVER['HTTP_HOST'] . APP_BASE . '/pages/' . $callbackFile;
}

/** Human message for an OAuth error code returned by a provider. */
function oauthErrorMessage(string $label, string $error, string $description, string $redirectUri): string {
    $error = strtolower(trim($error));
    $desc  = mb_substr(trim(strip_tags($description)), 0, 160);
    return match (true) {
        $error === 'access_denied'
            => "{$label} connection was cancelled.",
        str_contains($error, 'redirect_uri') || str_contains(strtolower($desc), 'redirect')
            => "{$label} rejected the callback address. Add this exact URL to the app's allowed redirect URIs in the {$label} developer console: {$redirectUri}",
        in_array($error, ['invalid_client', 'unauthorized_client', 'incorrect_client_credentials'], true)
            => "{$label} rejected Trackie's app credentials — check the client ID and secret in config/env.php.",
        $error === 'invalid_grant' || $error === 'bad_verification_code'
            => "{$label} sign-in code expired or was already used. Please connect again.",
        default
            => "{$label} error: {$error}" . ($desc !== '' ? " — {$desc}" : ''),
    };
}

/** POST form fields, return [httpCode, decodedJsonOrNull]. */
function oauthPost(string $url, array $fields, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'], $headers),
        CURLOPT_TIMEOUT        => 12,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = $body ? json_decode($body, true) : null;
    return [$code, is_array($data) ? $data : null];
}

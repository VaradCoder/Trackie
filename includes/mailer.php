<?php
/**
 * Trackie mailer — one function, pluggable transports.
 *
 * InfinityFree (and most free hosts) disable PHP mail(), so a real transport
 * is required for password resets. Configure ONE of these in config/env.php:
 *
 *   Brevo HTTP API (recommended — HTTPS, free 300 emails/day):
 *     define('BREVO_API_KEY', 'xkeysib-…');
 *   Generic SMTP (Gmail app password, Zoho, Brevo SMTP, …):
 *     define('SMTP_HOST', 'smtp.gmail.com');
 *     define('SMTP_PORT', 587);            // 587 = STARTTLS, 465 = SSL
 *     define('SMTP_USER', 'you@gmail.com');
 *     define('SMTP_PASS', 'app-password');
 *   Always:
 *     define('MAIL_FROM', 'noreply@your-domain');   // must be a verified sender
 *     define('MAIL_FROM_NAME', 'Trackie');
 *
 * Returns ['ok' => bool, 'via' => string, 'error' => ?string]. Errors never
 * contain credentials or message bodies (they can carry reset tokens).
 */

function mailConfigured(): bool {
    return env('BREVO_API_KEY') !== '' || (env('SMTP_HOST') !== '' && env('SMTP_USER') !== '');
}

function sendMail(string $to, string $subject, string $text, ?string $html = null): array {
    $from     = env('MAIL_FROM') ?: '';
    $fromName = env('MAIL_FROM_NAME') ?: 'Trackie';
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'via' => 'none', 'error' => 'Invalid recipient.'];
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'via' => 'none', 'error' => 'MAIL_FROM is not set.'];
    }
    if (env('BREVO_API_KEY') !== '') return mailViaBrevo($to, $subject, $text, $html, $from, $fromName);
    if (env('SMTP_HOST') !== '')     return mailViaSmtp($to, $subject, $text, $html, $from, $fromName);
    return ['ok' => false, 'via' => 'none', 'error' => 'No mail transport configured.'];
}

/** Brevo transactional email API (https://developers.brevo.com/reference/sendtransacemail). */
function mailViaBrevo(string $to, string $subject, string $text, ?string $html, string $from, string $fromName): array {
    $payload = [
        'sender'      => ['email' => $from, 'name' => $fromName],
        'to'          => [['email' => $to]],
        'subject'     => $subject,
        'textContent' => $text,
    ];
    if ($html) $payload['htmlContent'] = $html;
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => ['accept: application/json', 'content-type: application/json', 'api-key: ' . env('BREVO_API_KEY')],
        CURLOPT_TIMEOUT        => 12,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($code >= 200 && $code < 300) return ['ok' => true, 'via' => 'brevo', 'error' => null];
    $msg = $body ? (json_decode($body, true)['message'] ?? '') : $err;
    return ['ok' => false, 'via' => 'brevo', 'error' => "Brevo HTTP {$code}" . ($msg ? ': ' . mb_substr((string)$msg, 0, 160) : '')];
}

/** Minimal SMTP client: STARTTLS (587) or implicit TLS (465), AUTH LOGIN. */
function mailViaSmtp(string $to, string $subject, string $text, ?string $html, string $from, string $fromName): array {
    $host = (string)env('SMTP_HOST');
    $port = (int)(env('SMTP_PORT') ?: 587);
    $fail = static fn(string $e) => ['ok' => false, 'via' => 'smtp', 'error' => $e];

    $remote = ($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 12, STREAM_CLIENT_CONNECT,
                                stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]));
    if (!$fp) return $fail("Could not connect to {$host}:{$port} ({$errstr}). Your host may block outgoing SMTP — use BREVO_API_KEY instead.");
    stream_set_timeout($fp, 12);

    $read = static function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 515)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;   // last line of a multi-line reply
        }
        return $out;
    };
    $cmd = static function (string $c, array $okCodes) use ($fp, $read): ?string {
        if ($c !== '') fwrite($fp, $c . "\r\n");
        $r = $read();
        return in_array((int)substr($r, 0, 3), $okCodes, true) ? null : trim($r);
    };

    $ehloHost = preg_replace('/[^a-z0-9.-]/i', '', $_SERVER['HTTP_HOST'] ?? 'localhost') ?: 'localhost';
    if ($e = $cmd('', [220]))                   { fclose($fp); return $fail('SMTP greeting failed.'); }
    if ($e = $cmd("EHLO {$ehloHost}", [250]))   { fclose($fp); return $fail('SMTP EHLO rejected.'); }
    if ($port !== 465) {
        if ($e = $cmd('STARTTLS', [220]))       { fclose($fp); return $fail('SMTP server refused STARTTLS.'); }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            fclose($fp); return $fail('TLS negotiation failed.');
        }
        if ($e = $cmd("EHLO {$ehloHost}", [250])) { fclose($fp); return $fail('SMTP EHLO (TLS) rejected.'); }
    }
    if ($cmd('AUTH LOGIN', [334]) || $cmd(base64_encode((string)env('SMTP_USER')), [334])
        || $cmd(base64_encode((string)env('SMTP_PASS')), [235])) {
        fclose($fp); return $fail('SMTP login failed — check SMTP_USER / SMTP_PASS (Gmail needs an app password).');
    }
    if ($cmd("MAIL FROM:<{$from}>", [250]) || $cmd("RCPT TO:<{$to}>", [250, 251]) || $cmd('DATA', [354])) {
        fclose($fp); return $fail('SMTP server rejected the sender or recipient.');
    }

    $enc = static fn(string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
    $boundary = 'b' . bin2hex(random_bytes(8));
    $headers = [
        'Date: ' . date('r'),
        'From: ' . $enc($fromName) . " <{$from}>",
        "To: <{$to}>",
        'Subject: ' . $enc($subject),
        'MIME-Version: 1.0',
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $ehloHost . '>',
    ];
    if ($html) {
        $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
        $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($text))
              . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($html)) . "--{$boundary}--";
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $body = chunk_split(base64_encode($text));
    }
    // base64 bodies never contain a line that is just ".", so no dot-stuffing needed.
    if ($cmd(implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.", [250])) {
        fclose($fp); return $fail('SMTP server did not accept the message.');
    }
    $cmd('QUIT', [221]);
    fclose($fp);
    return ['ok' => true, 'via' => 'smtp', 'error' => null];
}

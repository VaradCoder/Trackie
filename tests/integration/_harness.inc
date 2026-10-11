<?php
/**
 * Shared by tests/integration/*.php (not run on its own — no .php extension).
 *
 * Each test signs a throwaway user in through the real login endpoint, which
 * counts toward the login throttle (10 per IP per 15 min). Without this, three
 * tests per regression run lock the developer's own localhost out after a few
 * runs. It removes exactly the one attempt the test added, nothing else.
 */
function releaseTestLogin(string $email): void {
    update("UPDATE rate_limits SET attempts = GREATEST(attempts - 1, 0)
             WHERE action='login' AND identifier IN ('127.0.0.1','::1') AND attempts > 0");
    update("DELETE FROM rate_limits WHERE action='login_acct' AND identifier=?", [strtolower($email)]);
}

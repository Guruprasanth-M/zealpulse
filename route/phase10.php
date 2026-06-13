<?php

declare(strict_types=1);

/**
 * Phase 10 — CGI dispatch & the legacy bay (the /phase10 surface).
 *
 * Thin handlers only — logic lives in ZealPulse\LegacyBay. The legacy scripts
 * under public/legacy/ are stock mod_php (zero framework code) and are reached
 * via App::include() from these routes (a direct /legacy/*.php request is blocked
 * by the BlockPhpExtMiddleware on the /legacy when()-scope, mod_php-style). The
 * shell CGI is reached at /cgi-bin/status.sh via the cgiScriptAlias in app.php.
 */

use ZealPHP\App;
use ZealPulse\LegacyBay;

$app = App::instance();

// B1 — the strategy switchboard (active mode/strategy + the legacy-bay map).
$app->route('/phase10', fn () => LegacyBay::switchboard());

// B1/B5 — the unmodified stock guestbook (GET lists + form, POST signs); the
// session visit counter proves session continuity across the CGI boundary.
$app->route('/phase10/guestbook', methods: ['GET', 'POST'], handler: fn () => LegacyBay::guestbook());

// B2 — universal return contract over the boundary (int/array/string/echo).
$app->route('/phase10/contract', fn () => LegacyBay::contract());

// B4 — env isolation proof (httpoxy + cgiPoolEnvAllowlist) via the subprocess.
$app->route('/phase10/envcheck', fn () => App::include('/legacy/envcheck.php'));

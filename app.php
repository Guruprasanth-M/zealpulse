<?php
/**
 * ZealPulse — a real-time server-ops dashboard & live event board built on
 * ZealPHP. Constructed PHASE BY PHASE (Phase 1 → Phase 14): each
 * `route/phaseN.php` module adds the features that exercise that phase's
 * batches, so the running app doubles as the cross-phase validation surface.
 *
 * Self-contained composer project (own vendor/) — portable: move it anywhere,
 * `composer install`, `php app.php`.
 *
 *   ZEAL_MODE = coroutine | mixed | coroutine-legacy | legacy-cgi  (default coroutine)
 *   ZEAL_PORT = listen port                                        (default 9100)
 *
 *   composer install
 *   php app.php                  # coroutine (recommended default)
 *   ZEAL_MODE=mixed php app.php
 *
 * Phase coverage is tracked in PROJECT.md. Route modules live in route/phaseN.php
 * and are auto-included by ZealPHP at boot (each does `$app = App::instance();`).
 */
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use ZealPHP\App;
use ZealPHP\Counter;
use ZealPHP\Store;
use ZealPHP\Middleware\BlockPhpExtMiddleware;
use ZealPHP\Middleware\BodySizeLimitMiddleware;
use ZealPHP\Middleware\CacheControlMiddleware;
use ZealPHP\Middleware\CharsetMiddleware;
use ZealPHP\Middleware\ConcurrencyLimitMiddleware;
use ZealPHP\Middleware\ContentEncodingMiddleware;
use ZealPHP\Middleware\ContentLanguageMiddleware;
use ZealPHP\Middleware\CorsMiddleware;
use ZealPHP\Middleware\CsrfMiddleware;
use ZealPHP\Middleware\ETagMiddleware;
use ZealPHP\Middleware\ExpiresMiddleware;
use ZealPHP\Middleware\HeaderMiddleware;
use ZealPHP\Middleware\IniIsolationMiddleware;
use ZealPHP\Middleware\IpAccessMiddleware;
use ZealPHP\Middleware\MergeSlashesMiddleware;
use ZealPHP\Middleware\MimeTypeMiddleware;
use ZealPHP\Middleware\RangeMiddleware;
use ZealPHP\Middleware\RateLimitMiddleware;
use ZealPHP\Middleware\RefererMiddleware;
use ZealPHP\Middleware\RequestIdMiddleware;
use ZealPHP\Middleware\SessionStartMiddleware;
use ZealPHP\Middleware\SetEnvIfMiddleware;
use ZealPulse\Middleware\SessionAuthMiddleware;
use ZealPulse\Middleware\TraceMiddleware;

$mode = getenv('ZEAL_MODE') ?: 'coroutine';
$port = (int) (getenv('ZEAL_PORT') ?: 9100);
$modeConst = match ($mode) {
    'coroutine'        => App::MODE_COROUTINE,
    'mixed'            => App::MODE_MIXED,
    'coroutine-legacy' => App::MODE_COROUTINE_LEGACY,
    'legacy-cgi'       => App::MODE_LEGACY_CGI,
    default            => throw new RuntimeException("unknown ZEAL_MODE='$mode'"),
};

App::mode($modeConst);
App::documentRoot(__DIR__ . '/public');
App::ignorePhpExt(false);   // serve public/*.php at their own path (Apache-style)

$app = App::init('127.0.0.1', $port);

// ─── Phase 4 — sessions: explicit save path (avoid the root-fallback #343) +
//     a handler switchboard so the same login works on every backend ─────────
$sessDir = sys_get_temp_dir() . '/zealpulse-sessions';
@mkdir($sessDir, 0700, true);
App::sessionSavePath($sessDir);
App::sessionStrictMode(true);                 // reject a forged/unissued PHPSESSID (fixation)
// Handler switchboard. NOTE: explicitly selecting 'file' routes through the
// FileSessionHandler class, whose session_regenerate_id(true) path fatals
// ($savePath accessed before open() — a Phase-4 ZealPHP bug, to be filed). The
// unconfigured default uses the inline file path that works with rotation, so
// only set a handler for the non-default backends.
$zpHandler = getenv('ZEAL_SESSION_HANDLER') ?: 'file';
if ($zpHandler !== 'file') {
    App::sessionHandler($zpHandler);
}

// ─── Phase 5 — middleware suite: the FULL built-in band ──────────────────────
//
// Every built-in middleware ZealPHP ships gets ONE real home (global band,
// when() path scope, route group, per-route, or api in-file). Per-route and
// group mounts live in route/phase5.php; the global band, the when() scopes,
// and the alias registry are bootstrap concerns and live here.
//
// DELIBERATELY NOT MOUNTED: CompressionMiddleware — OpenSwoole already serves
// with server-level http_compression on (the default), so an app-level gzip
// pass would double-compress every response. Recorded in
// ZealPulse\MiddlewareInfo::notMounted() and on the /middleware page.

// Trusted proxy = loopback, so curl probes (and a front proxy on this host)
// can present a real client IP via X-Forwarded-For. App::clientIp() only
// honours XFF when the socket peer is inside this list — spoof-safe.
App::trustedProxies(['127.0.0.1']);

// Pre-fork shared state (MUST exist before $app->run() so workers share it):
// RateLimitMiddleware's window table + ConcurrencyLimitMiddleware's counter.
Store::make('rate_limit', 4096, [
    'ip'    => [Store::TYPE_STRING, 64],
    'count' => [Store::TYPE_INT, 8],
    'reset' => [Store::TYPE_INT, 8],
]);
$reportsInflight = new Counter(0, 'zp_reports_inflight');

// ZealAPI auth hooks — the session identity (Phase 4) is the single source of
// truth; SessionAuthMiddleware and api-file $this->isAuthenticated() share it.
App::authChecker(fn (): bool => \ZealPulse\Auth::user() !== null);
App::adminChecker(fn (): bool => \ZealPulse\Auth::isAdmin());

// ── Alias registry (factories run ONCE at App::run(); instances are SHARED —
//    stateless rule: per-request state lives in $g, never on the instance).
//    Each factory logs its construction so "factory runs once" is auditable
//    in the server log (B6).
$aliasLog = function (string $name): void {
    fwrite(STDERR, "[zealpulse] middleware alias factory ran: {$name}\n");
};
App::middlewareAlias('throttle', function ($n = '60') use ($aliasLog) {
    $aliasLog("throttle:{$n}");
    return new RateLimitMiddleware(limit: (int) $n, window: 60);
});
App::middlewareAlias('trace', function ($tag = 'mw') use ($aliasLog) {
    $aliasLog("trace:{$tag}");
    return new TraceMiddleware((string) $tag);
});
App::middlewareAlias('session-auth', function () use ($aliasLog) {
    $aliasLog('session-auth');
    return new SessionAuthMiddleware();
});
App::middlewareAlias('admin-ip', function () use ($aliasLog) {
    $aliasLog('admin-ip');
    $allow = ['127.0.0.1', '::1'];
    $cidr = getenv('ZP_ADMIN_CIDR');
    if ($cidr !== false && trim($cidr) !== '') {
        $allow[] = trim($cidr);
    }
    return new IpAccessMiddleware(['allow' => $allow]);
});
App::middlewareAlias('csrf', function () use ($aliasLog) {
    $aliasLog('csrf');
    return new CsrfMiddleware();
});
App::middlewareAlias('referer-gate', function () use ($aliasLog) {
    $aliasLog('referer-gate');
    // Admin form posts: only our own host may refer; no/blocked Referer = 403.
    return new RefererMiddleware([], allowNone: false, allowBlocked: false,
        serverNames: ['127.0.0.1', 'localhost', 'ops.localhost']);
});
App::middlewareAlias('reports-gate', function () use ($aliasLog, $reportsInflight) {
    $aliasLog('reports-gate');
    return new ConcurrencyLimitMiddleware(maxConcurrent: 2, counter: $reportsInflight);
});
App::middlewareAlias('upload-cap', function () use ($aliasLog) {
    $aliasLog('upload-cap');
    return new BodySizeLimitMiddleware('2k');
});

// WORKAROUND (new divergence, noted for the verification sweep — NOT re-filed
// here): CharsetMiddleware decides from the PSR response's Content-Type
// (CharsetMiddleware.php:60), which is EMPTY whenever a handler set its CT via
// the ZealPHP header() shim (the shim writes to $g->zealphp_response only).
// It then forces App::$default_mimetype ('text/html') onto the OpenSwoole
// response (CharsetMiddleware.php:86), CLOBBERING the handler's CT — e.g.
// Http::json's application/json became text/html on /health. $default_mimetype
// is consumed ONLY by CharsetMiddleware, so blanking it disables exactly that
// force-default branch: shim-typed responses pass untouched, PSR-typed
// responses still get the charset appended (see /mw/charset, /healthz).
App::defaultMimeType('');

// ── Global stack (first-registered = OUTERMOST) ──────────────────────────────
$app->addMiddleware(new RequestIdMiddleware());          // X-Request-Id on EVERY response
$app->addMiddleware(new MergeSlashesMiddleware());       // //x///y → /x/y before routing
$app->addMiddleware(new CharsetMiddleware());            // closes the HELD charset gap (PSR path)
$app->addMiddleware(new SetEnvIfMiddleware([             // tag bots from User-Agent
    ['attr' => 'User-Agent', 'regex' => '#(bot|crawler|spider)#i', 'set' => ['ZP_BOT' => '1']],
]));
$app->addMiddleware(new HeaderMiddleware(['set' => [     // security headers, all responses
    'X-Content-Type-Options' => 'nosniff',
    'X-Frame-Options'        => 'DENY',
    'Referrer-Policy'        => 'strict-origin-when-cross-origin',
]]));
$app->addMiddleware(new SessionStartMiddleware());       // eager session for first visitors

// ── App::when() path scopes (registration order = outermost first) ───────────
// /api → CORS (origins from ZEALPHP_CORS_ORIGINS) + parameterized rate limit.
App::when('/api', [new CorsMiddleware(), 'throttle:120']);
// /assets-dl → the full static-asset decorator chain over handler-served bytes.
App::when('/assets-dl', [
    new RangeMiddleware(),
    new ETagMiddleware(),
    new CacheControlMiddleware(),
    new ExpiresMiddleware(byType: ['text/' => '+5 minutes', 'image/' => '+30 days'], default: '+1 hour'),
    new MimeTypeMiddleware([
        'css'  => 'text/css',
        'txt'  => 'text/plain',
        'json' => 'application/json',
        'tar'  => 'application/x-tar',
        'svg'  => 'image/svg+xml',
    ]),
    new ContentEncodingMiddleware(['gz' => 'gzip', 'br' => 'br']),
    new ContentLanguageMiddleware(['en' => 'en', 'ta' => 'ta']),
]);
// /legacy → per-request ini snapshot/restore + mod_php-style .php blocking.
App::when('/legacy', [new IniIsolationMiddleware(), new BlockPhpExtMiddleware()]);

// Route modules in route/*.php are auto-included by ZealPHP at boot, in name
// order (phase1, phase2, …). Each grabs the app via App::instance().

fwrite(STDERR, sprintf("[zealpulse] mode=%s port=%d\n", $mode, $port));
$app->run(['worker_num' => 2]);

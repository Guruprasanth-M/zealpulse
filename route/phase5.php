<?php
/**
 * ZealPulse — Phase 5: middleware suite, the FULL built-in band (batches B1–B8).
 *
 * The global stack, when() scopes and alias registry are wired in app.php;
 * THIS module mounts the group / per-route homes:
 *
 *   /admin/** group        — admin-ip (IpAccess) + session-auth, with per-route
 *                            referer-gate (Referer), reports-gate (ConcurrencyLimit),
 *                            upload-cap (BodySizeLimit), RequestHeader tier inject,
 *                            and a NESTED /admin/tools group (trace proves the onion).
 *   /_info                 — BasicAuth (htpasswd fixture, APR1 scheme).
 *   /feedback              — Csrf form route (login/logout in phase4 are Csrf'd too).
 *   /teapot                — ReturnMiddleware 418 short-circuit.
 *   /old-dash              — RedirectMiddleware legacy URL map (301 → /).
 *   /mw/port-redirect      — LocationHeaderMiddleware behind-proxy port rewrite.
 *   /vhost                 — HostRouterMiddleware (ops.localhost → alt handler).
 *   /about                 — BodyRewriteMiddleware footer stamp.
 *   /scoped/{which}        — ScopedMiddleware::location() wrapper demo.
 *   /healthz · /readyz     — HealthCheckMiddleware (readyz with a real check).
 *   /assets-dl/{name}      — bytes for the when('/assets-dl') decorator chain.
 *   /legacy/*              — probes for the when('/legacy') scope.
 *   /mw/env · /mw/charset  — global-band probes (SetEnvIf / RequestId / Charset).
 *   /middleware            — the introspection page (App::describeRoutes()).
 */
declare(strict_types=1);

use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\Store;
use ZealPHP\Middleware\BasicAuthMiddleware;
use ZealPHP\Middleware\BodyRewriteMiddleware;
use ZealPHP\Middleware\HeaderMiddleware;
use ZealPHP\Middleware\HealthCheckMiddleware;
use ZealPHP\Middleware\HostRouterMiddleware;
use ZealPHP\Middleware\LocationHeaderMiddleware;
use ZealPHP\Middleware\RedirectMiddleware;
use ZealPHP\Middleware\RequestHeaderMiddleware;
use ZealPHP\Middleware\ReturnMiddleware;
use ZealPHP\Middleware\ScopedMiddleware;
use ZealPulse\Auth;
use ZealPulse\Dl;
use ZealPulse\Http;
use ZealPulse\MiddlewareInfo;

$app = App::instance();
$zpPort = (int) (getenv('ZEAL_PORT') ?: 9100);

// ── Admin group: IP gate (outermost) → session auth → route-level gates ──────
$app->group('/admin', ['trace:admin-group', 'admin-ip', 'session-auth'], function ($admin) {
    // Onion-order probe: group middleware enters before route middleware.
    $admin->route('/panel', middleware: ['trace:panel-route'], handler: function () {
        $g = G::instance();
        return Http::json([
            'area'  => 'admin',
            'user'  => Auth::user(),
            'trace' => $g->memo['zp_trace'] ?? [],
        ]);
    });

    // Referer-gated + CSRF'd admin form post.
    $admin->route('/note', methods: ['POST'], middleware: ['referer-gate', 'csrf'],
        handler: function () {
            $g = G::instance();
            return Http::json(['saved' => true, 'note' => (string) ($g->post['note'] ?? '')]);
        });

    // Concurrency-capped report build (max 2 in flight; usleep is hooked →
    // coroutine sleep, so a burst genuinely overlaps).
    $admin->route('/reports/slow', middleware: ['reports-gate'], handler: function () {
        usleep(400_000);
        return Http::json(['report' => 'built', 'took_ms' => 400]);
    });

    // Body-size-capped upload sink (413 over 2k).
    $admin->route('/upload', methods: ['POST'], middleware: ['upload-cap'], handler: function () {
        $raw = (string) file_get_contents('php://input');
        return Http::json(['received_bytes' => strlen($raw)]);
    });

    // RequestHeaderMiddleware: inject X-ZP-Tier for downstream handler code.
    $admin->route('/tier', middleware: [new RequestHeaderMiddleware([
        ['op' => 'set', 'name' => 'X-ZP-Tier', 'value' => 'admin'],
    ])], handler: function () {
        $g = G::instance();
        return Http::json(['tier' => $g->server['HTTP_X_ZP_TIER'] ?? null]);
    });

    // Nested group — inherits the admin gates, adds its own trace layer.
    $admin->group('/tools', ['trace:tools-nested'], function ($tools) {
        $tools->route('/ping', function () {
            $g = G::instance();
            return Http::json(['pong' => 'admin-tools', 'trace' => $g->memo['zp_trace'] ?? []]);
        });
    });
});

// ── BasicAuth (htpasswd file, APR1 scheme) on /_info ─────────────────────────
$app->route('/_info', middleware: [new BasicAuthMiddleware(
    htpasswdFile: dirname(__DIR__) . '/assets/fixtures/htpasswd',
    realm: 'ZealPulse Info',
)], handler: fn () => Http::json([
    'app'     => 'zealpulse',
    'php'     => PHP_VERSION,
    'sapi'    => php_sapi_name(),
    'openswoole' => phpversion('openswoole') ?: null,
]));

// ── Csrf form route (the Phase-5 demo form; /login + /logout carry it too) ───
$app->route('/feedback', methods: ['GET', 'POST'], middleware: ['csrf', 'trace:feedback'],
    handler: function ($request) {
        $g = G::instance();
        $method = strtoupper((string) ($g->server['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'POST') {
            Http::secureHeaders();
            $token = htmlspecialchars((string) ($g->memo['csrf_token'] ?? ''), ENT_QUOTES);
            return <<<HTML
                <!doctype html><meta charset=utf-8><title>Feedback — ZealPulse</title>
                <link rel="stylesheet" href="/css/app.css">
                <h1>Feedback</h1>
                <form method="post" action="/feedback">
                  <input type="hidden" name="_csrf_token" value="$token">
                  <textarea name="msg" placeholder="what's on fire?"></textarea>
                  <button>Send</button>
                </form>
                HTML;
        }
        return Http::json(['received' => true, 'msg' => (string) ($g->post['msg'] ?? '')]);
    });

// ── ReturnMiddleware: mod_alias `Redirect`/`Return` — a fixed 418 ────────────
$app->route('/teapot', middleware: [new ReturnMiddleware(418, "short and stout\n")],
    handler: fn () => 'unreachable — ReturnMiddleware short-circuits');

// ── RedirectMiddleware: legacy URL map ───────────────────────────────────────
$app->route('/old-dash', middleware: [new RedirectMiddleware([
    ['from' => '/old-dash', 'to' => '/', 'status' => 301],
])], handler: fn () => 'unreachable — RedirectMiddleware short-circuits');

// ── LocationHeaderMiddleware: behind-proxy port rewrite on outbound Location ─
$app->route('/mw/port-redirect', middleware: [new LocationHeaderMiddleware(8443)],
    handler: fn () => new \OpenSwoole\Core\Psr\Response('', 302, '', [
        'Location' => "http://127.0.0.1:{$zpPort}/health",
    ]));

// ── HostRouterMiddleware: vhost demo (no '*' catch-all → pass-through) ───────
$app->route('/vhost', middleware: [new HostRouterMiddleware([
    'ops.localhost' => fn () => ['vhost' => 'ops.localhost', 'handler' => 'alt'],
])], handler: fn () => Http::json(['vhost' => 'default-site']));

// ── BodyRewriteMiddleware: footer-stamp /about (mod_substitute) ──────────────
$app->route('/about', middleware: [new BodyRewriteMiddleware([
    ['pattern' => '#</body>#', 'replacement' => '<footer>stamped by BodyRewriteMiddleware · zealpulse-phase5</footer></body>'],
])], handler: function () {
    Http::secureHeaders();
    return "<!doctype html><html><head><meta charset=\"utf-8\"><title>About — ZealPulse</title></head>"
        . '<body><h1>About ZealPulse</h1><p>A real-time ops control room on ZealPHP.</p></body></html>';
});

// ── ScopedMiddleware::location(): one instance, fires only on its prefix ─────
$app->route('/scoped/{which}', middleware: [
    ScopedMiddleware::location(new HeaderMiddleware(['set' => ['X-ZP-Scoped' => 'hit']]), '/scoped/special'),
], handler: fn ($which) => Http::json(['which' => $which]));

// ── HealthCheckMiddleware: liveness + readiness (readyz has a real check) ────
$app->route('/healthz', middleware: [new HealthCheckMiddleware(['/healthz'])],
    handler: fn () => Http::json(['fallthrough' => true]));
$app->route('/readyz', middleware: [new HealthCheckMiddleware(['/readyz'],
    check: fn (): ?string => Store::table('rate_limit') !== null ? null : 'rate_limit table missing',
)], handler: fn () => Http::json(['fallthrough' => true]));

// ── /assets-dl/{name}: raw bytes for the when('/assets-dl') decorator chain ──
// Deliberately NO Content-Type from the handler — MimeTypeMiddleware resolves
// it from the extension; ETag/Range/CacheControl/Expires/Encoding/Language
// decorate on the unwind.
$app->route('/assets-dl/{name}', function ($name) {
    $bytes = Dl::read((string) $name);
    if ($bytes === null) {
        return 404;
    }
    return $bytes;
});

// ── when('/legacy') probes: IniIsolation + BlockPhpExt ───────────────────────
$app->route('/legacy/ini', function () {
    $g = G::instance();
    $requested = $g->get['precision'] ?? null;
    if ($requested !== null) {
        ini_set('precision', (string) (int) $requested);
    }
    return Http::json(['precision' => ini_get('precision'), 'mutated' => $requested !== null]);
});
$app->route('/legacy/tool.php', fn () => 'NEVER SERVED — BlockPhpExtMiddleware 404s any .php under /legacy');
$app->route('/legacy/home', fn () => Http::json(['legacy' => 'bay', 'scope' => 'IniIsolation + BlockPhpExt']));

// ── Global-band probes ───────────────────────────────────────────────────────
$app->route('/mw/env', function () {
    $g = G::instance();
    return Http::json([
        'bot'        => $g->server['ZP_BOT'] ?? null,          // SetEnvIfMiddleware
        'request_id' => $g->memo['request_id'] ?? null,        // RequestIdMiddleware memo
    ]);
});
$app->route('/mw/charset', function () {
    // PSR passthrough with a charset-less Content-Type — CharsetMiddleware
    // appends `; charset=utf-8` on the unwind. (The shim-header path is NOT
    // used here: CharsetMiddleware can't see shim-set CTs — see the
    // App::defaultMimeType('') workaround note in app.php.)
    return new \OpenSwoole\Core\Psr\Response("charset appended by CharsetMiddleware\n", 200, '', [
        'Content-Type' => 'text/plain',
    ]);
});

// ── /api burst probe (B6 statelessness): echoes its own token + request id ───
$app->route('/api/burst', function () {
    $g = G::instance();
    return Http::json([
        'token'      => (string) ($g->get['token'] ?? ''),
        'request_id' => $g->memo['request_id'] ?? null,
    ]);
});

// ── /middleware — the introspection page (B7) ────────────────────────────────
$app->route('/middleware', function () {
    App::render('middleware', [
        'info'       => MiddlewareInfo::describe(),
        'notMounted' => MiddlewareInfo::notMounted(),
    ]);
});

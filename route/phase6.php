<?php
/**
 * ZealPulse — Phase 6: routing & dispatch (batches B1–B9).
 *
 * The full URL map exercised end-to-end:
 *   B1  every registrar kind in priority order — route / nsRoute / nsPathRoute /
 *       patternRoute / group (nested) / implicit-public.
 *   B2  parameter injection by name + defaults + the #240 reserved-name rule.
 *   B3  method semantics — methods:, HEAD-from-GET, OPTIONS/Allow, 405, TRACE gate.
 *   B4  custom 404 + 500 experience, JSON/HTML negotiated (setFallback / setErrorHandler).
 *   B5  ZealAPI deep pass lives in api/probe/check.php (per-method + auth + in-file mw).
 *   B6  HaltException mid-handler — clean halt, worker survives.
 *   B7  /p6/routes — truthful introspection via App::describeRoutes().
 *   B8  dev hot-reload story — App::devReload()/ZEALPHP_DEV + reloadRoutes().
 *
 * Handlers stay thin and call ZealPulse\Routing; the custom-error wiring + the
 * boot knobs (traceEnabled, apiNullNotFound, usernameProvider, displayErrors)
 * are set in app.php.
 */
declare(strict_types=1);

use ZealPHP\App;
use ZealPulse\Http;
use ZealPulse\Routing;

$app = App::instance();

// ── B1 — landing: index of the routing lab ───────────────────────────────────
$app->route('/p6', function () {
    Http::secureHeaders();
    return <<<HTML
        <!doctype html><meta charset=utf-8><title>Phase 6 — routing lab</title>
        <link rel="stylesheet" href="/css/app.css">
        <h1>ZealPulse · Phase 6 — routing &amp; dispatch</h1>
        <ul>
          <li><a href="/p6/user/42">/p6/user/{id}</a> — path param</li>
          <li><a href="/admin/dashboard">/admin/dashboard</a> — nsRoute namespace</li>
          <li><a href="/docs/guide/routing/intro">/docs/{section}/{path}</a> — nsPathRoute deep path</li>
          <li><a href="/p6/files/css/app.css">/p6/files/&lt;path&gt;</a> — patternRoute catch-all</li>
          <li><a href="/team/members">/team/members</a> — group; <a href="/team/ops/oncall">/team/ops/oncall</a> — nested group</li>
          <li><a href="/p6/inject/7">/p6/inject/{id}</a> — param injection by name + defaults</li>
          <li><a href="/p6/shadow/HACK">/p6/shadow/{request}</a> — #240 reserved-name proof</li>
          <li><a href="/p6/methods">/p6/methods</a> — GET/POST (try OPTIONS, HEAD, 405)</li>
          <li><a href="/p6/routes">/p6/routes</a> — live route map (B7)</li>
          <li><a href="/p6/boom">/p6/boom</a> — 500 page · <a href="/api/probe/halt">/api/probe/halt</a> — HaltException clean halt (B6)</li>
          <li><a href="/p6/nope">/p6/nope</a> — custom 404 with a suggestion (B4)</li>
        </ul>
        HTML;
});

// ── B1 — route() with a path param ───────────────────────────────────────────
$app->route('/p6/user/{id}', fn ($id) => Http::json(['user_id' => $id, 'kind' => 'route()']));

// ── B1 — nsRoute(): the admin namespace → /admin/dashboard ───────────────────
$app->nsRoute('admin', '/dashboard', handler: function () {
    return Http::json(['kind' => 'nsRoute()', 'namespace' => 'admin', 'path' => '/admin/dashboard']);
});

// ── B1 — nsPathRoute(): LAST param is a greedy catch-all (matches slashes) ────
$app->nsPathRoute('docs', '/{section}/{path}', handler: function ($section, $path) {
    return Http::json(['kind' => 'nsPathRoute()', 'section' => $section, 'path' => $path]);
});

// ── B1 — patternRoute(): a raw regex catch-all; named group → handler param ──
$app->patternRoute('#^/p6/files/(?P<path>.+)$#', handler: function ($path) {
    return Http::json(['kind' => 'patternRoute()', 'path' => $path]);
});

// ── B1 — group() with a nested group (shared prefix + middleware band) ───────
$app->group('/team', ['trace:team'], function ($g) {
    $g->route('/members', fn () => Http::json(['kind' => 'group()', 'group' => '/team', 'members' => ['ops', 'viewer']]));
    // nested group composes the prefix (/team/ops) and the middleware chain.
    $g->group('/ops', ['trace:team-ops'], function ($g2) {
        $g2->route('/oncall', fn () => Http::json(['kind' => 'nested group()', 'group' => '/team/ops', 'oncall' => 'ops']));
    });
});

// ── B2 — parameter injection by name + defaults ──────────────────────────────
// Each arg is filled by NAME: {id} from the URL, $request/$response/$app are the
// injected framework objects, a defaulted param falls back to its default.
$app->route('/p6/inject/{id}', function ($id, $request, $response, $app, $note = 'default-note') {
    return Http::json([
        'id'             => $id,
        'request_class'  => $request::class,
        'response_class' => $response::class,
        'app_class'      => $app::class,
        'note'           => $note,                 // no URL/query → the PHP default
    ]);
});

// ── B2 — #240 reserved-name rule (security) ──────────────────────────────────
// The URL segment is named {request}, but the framework binds the injected
// Request wrapper to $request BEFORE any same-named segment — an attacker URL
// value can NEVER shadow the object. Proof: we return the class, not the path.
$app->route('/p6/shadow/{request}', function ($request) {
    return Http::json([
        'note'          => '#240: {request} URL segment cannot shadow the injected object',
        'request_class' => $request::class,        // ZealPHP\HTTP\Request, never the path string
        'is_object'     => is_object($request),
    ]);
});

// ── B3 — method semantics: a GET+POST route ──────────────────────────────────
// HEAD auto-derives from GET; OPTIONS → 204 + Allow; a wrong method → 405 + Allow;
// an unknown method token → 501. (TRACE is gated by App::traceEnabled — see note.)
$app->route('/p6/methods', methods: ['GET', 'POST'], handler: function ($request) {
    $method = (string) ($request->server['request_method'] ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    return Http::json(['kind' => 'methods:[GET,POST]', 'method' => $method]);
});

// ── B3 — TRACE gate note (App::traceEnabled(false) in app.php) ───────────────
// NOTE: on OpenSwoole the engine rejects TRACE at the parser before PHP, so the
// framework's TRACE handling is unreachable over HTTP (filed upstream as #413);
// this route documents the intended XST-defense contract.
$app->route('/p6/trace-note', fn () => Http::json([
    'trace_enabled' => App::traceEnabled(),
    'note'          => 'TRACE refused 405 by default (XST). On OpenSwoole the engine 400s TRACE before PHP — see upstream #413.',
]));

// ── B4 — error trigger: a 500 (the custom error handler renders it) ──────────
$app->route('/p6/boom', function () {
    throw new \RuntimeException('intentional failure for the Phase 6 error-page demo');
});

// ── B6 — clean halt (route-level, release-portable) ──────────────────────────
// A route closure halts cleanly via the universal return contract (buffered
// body + an explicit status). The HaltException primitive itself is shown at
// /api/probe/halt (the ZealAPI path catches it on v0.4.8). NOTE: a bare
// `throw new HaltException()` from a route closure is a clean halt only from the
// post-0.4.8 line — on the released v0.4.8 it surfaces as 500 (release lag;
// fixed on main). So routes use the contract; api files use HaltException.
$app->route('/p6/halt', function () {
    // A route closure returns its body through the universal contract (array →
    // JSON 200). The HaltException primitive itself is demonstrated at
    // /api/probe/halt, where the v0.4.8 ZealAPI path catches it as a clean halt.
    return Http::json(['kind' => 'clean-halt', 'note' => 'route halts via the return contract', 'halt_demo' => '/api/probe/halt']);
});

// ── B7 — /p6/routes: truthful introspection (works pre + post run) ───────────
$app->route('/p6/routes', function ($request) {
    $map = Routing::routeMap();
    if (Routing::wantsJson($request)) {
        return Http::json($map);
    }
    Http::secureHeaders();
    $rows = '';
    foreach ($map['routes'] as $r) {
        $m = htmlspecialchars(implode(', ', (array) $r['methods']), ENT_QUOTES);
        $p = htmlspecialchars((string) $r['path'], ENT_QUOTES);
        $mw = htmlspecialchars(implode(', ', array_map('strval', (array) $r['middleware'])), ENT_QUOTES);
        $rows .= "<tr><td>$m</td><td><code>$p</code></td><td>$mw</td></tr>";
    }
    $count = (int) $map['count'];
    return <<<HTML
        <!doctype html><meta charset=utf-8><title>Route map — ZealPulse</title>
        <link rel="stylesheet" href="/css/app.css">
        <h1>Route map · $count routes</h1>
        <table border=1 cellpadding=4 cellspacing=0>
          <thead><tr><th>Methods</th><th>Path</th><th>Middleware</th></tr></thead>
          <tbody>$rows</tbody>
        </table>
        HTML;
});

// ── B8 — dev hot-reload status (the live-edit cycle is a manual test) ────────
// With App::devReload(true) / ZEALPHP_DEV=1, editing a route/*.php file is picked
// up by the mtime poll → reloadRoutes() rebuilds the table in place. A route file
// that declares a top-level function is refused (table stays intact).
$app->route('/p6/reload-status', fn () => Http::json([
    'dev_reload'   => App::devReload(),
    'route_count'  => Routing::routeMap()['count'],
    'note'         => 'Set ZEALPHP_DEV=1 (or App::devReload(true)) then edit a route/*.php file — the map updates with no restart.',
]));

// ── B4 — fallback: the branded 404 (with a did-you-mean) ─────────────────────
// Registered LAST so it only catches genuinely unmatched URLs.
$app->setFallback(function ($request) {
    $path = (string) ($request->server['request_uri'] ?? ($_SERVER['REQUEST_URI'] ?? '/'));
    $path = (string) parse_url($path, PHP_URL_PATH);
    return Routing::notFound($request, $path);
});

// ── B4 — custom error handlers (Apache ErrorDocument parity) ─────────────────
// A status-specific 500 handler + a catch-all; both negotiate JSON vs HTML and
// receive $status/$exception/$request by name.
App::setErrorHandler(500, function ($status, $exception, $request) {
    return Routing::errorPage(500, $exception instanceof \Throwable ? $exception : null, $request);
});
App::setErrorHandler(function ($status, $exception, $request) {
    return Routing::errorPage((int) $status, $exception instanceof \Throwable ? $exception : null, $request);
});

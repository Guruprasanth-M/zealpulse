<?php
/**
 * ZealPulse file-API (ZealAPI) — /api/probe/check  (Phase 6 · B5 deep pass).
 *
 * Per-method dispatch ($get / $post), the fail-closed auth hooks, and an
 * in-file $middleware band:
 *   GET  /api/probe/check   → public health snapshot ($this helpers + injected $server)
 *   POST /api/probe/check   → requires an authenticated POST ($this->requirePostAuth())
 *
 * Auth is wired in app.php via App::authChecker()/adminChecker()/usernameProvider();
 * with no session the POST is rejected 403 (fail-closed). The in-file $middleware
 * runs INNERMOST (after the global + /api when-scope bands) and reuses the shared
 * alias registry.
 *
 * KNOWN UPSTREAM BUG: a HEAD on any ZealAPI file returns 406 (REST::inputs has no
 * HEAD case) — filed as #411. GET/POST are unaffected.
 */
declare(strict_types=1);

// In-file middleware band (innermost) — reuses the app.php alias registry.
$middleware = ['trace:api-probe-check'];

$get = function ($request, $response, $server) {
    header('Content-Type: application/json; charset=utf-8');
    $g = \ZealPHP\G::instance();
    return [
        'ok'            => true,
        'endpoint'      => '/api/probe/check',
        'method'        => 'GET',
        'authenticated' => $this->isAuthenticated(),   // fail-closed: false with no session
        'admin'         => $this->isAdmin(),
        'user'          => $this->getUsername(),
        'worker'        => is_object($server) ? 'server-injected' : 'n/a',
        'trace'         => $g->memo['zp_trace'] ?? [],  // proves the in-file chain ran
    ];
};

$post = function ($request, $response) {
    header('Content-Type: application/json; charset=utf-8');
    // Fail-closed: rejects unless App::authChecker() says this request is authed
    // AND the method is POST. Returns 403 itself when unauthorized.
    $this->requirePostAuth();
    return [
        'ok'        => true,
        'method'    => 'POST',
        'actor'     => $this->getUsername(),
        'recorded'  => true,
    ];
};

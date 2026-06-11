<?php
/**
 * ZealPulse — Phase 1: HTTP response & headers (batches B1–B8).
 *
 * Real dashboard endpoints, each chosen so it exercises a Phase-1 batch:
 *   B1 status codes · B2 header() family · B3 cookies · B4 body framing/HEAD ·
 *   B5 redirects · B6 charset/content-type · B7 universal return contract ·
 *   B8 re-verify (the known-filed divergences are exercised but not re-filed).
 *
 * $app is in scope (required from app.php).
 */
declare(strict_types=1);

use ZealPHP\App;
use ZealPulse\Http;

// Route files are auto-included by ZealPHP at boot; grab the app instance.
$app = App::instance();

// ── Landing: the dashboard shell (HTML return → B7 string, B2/B6 headers) ────
$app->route('/', function () {
    Http::secureHeaders();
    return <<<HTML
        <!doctype html><html lang="en"><head><meta charset="utf-8">
        <title>ZealPulse</title></head><body>
        <h1>⚡ ZealPulse</h1>
        <p>Real-time server-ops dashboard on ZealPHP. Phase 1 online.</p>
        <ul>
          <li><a href="/health">/health</a> — health (B1 status, B7 array)</li>
          <li><a href="/api/status/418">/api/status/{code}</a> — status playground (B1)</li>
          <li><a href="/headers">/headers</a> — response-header inspector (B2)</li>
          <li><a href="/prefs?theme=dark">/prefs</a> — set a preference cookie (B3)</li>
          <li><a href="/go?to=/health">/go</a> — safe redirect (B5)</li>
          <li><a href="/feed">/feed</a> — streamed SSR list (B7 generator)</li>
        </ul></body></html>
        HTML;
});

// ── B1 — status codes: a real health endpoint + a status playground ──────────
$app->route('/health', function () {
    // 200 + JSON via the contract; a real readiness check would gate the code.
    return Http::json(['status' => 'ok', 'phase' => 1, 'ts' => 'now']);
});
$app->route('/api/status/{code}', function ($code) {
    // Status-code playground: returns the requested code (out-of-range → 500
    // via coerceStatusCode; 418/451 emit correctly).
    Http::secureHeaders('text/plain; charset=utf-8');
    http_response_code((int) $code);
    return "status set to {$code}\n";
});

// ── B2 — header() family: response-header inspector ──────────────────────────
$app->route('/headers', function () {
    Http::secureHeaders('text/plain; charset=utf-8');
    header('Link: </css/app.css>; rel=preload; as=style');
    header('Link: </js/app.js>; rel=preload; as=script', false);   // 2nd same-name (B2/#260)
    return "Inspect this response's headers (X-ZealPulse appears twice; two Link lines).\n";
});

// ── B3 — cookies: user preferences (theme/density) ───────────────────────────
$app->route('/prefs', function ($request) {
    $theme = ($request->get['theme'] ?? $_GET['theme'] ?? 'light');
    $theme = in_array($theme, ['light', 'dark'], true) ? $theme : 'light';
    // 1-year pref cookie, SameSite=Lax, HttpOnly off (read by JS in a real UI).
    setcookie('zp_theme', $theme, [
        'expires'  => time() + 31536000,
        'path'     => '/',
        'samesite' => 'Lax',
        'httponly' => false,
    ]);
    return Http::json(['saved' => true, 'theme' => $theme]);
});

// ── B4 — body framing: a 204 action (no content) + HEAD works on any GET ─────
$app->route('/api/ack', fn () => Http::noContent());   // 204, body stripped

// ── B5 — redirects: safe same-origin redirect (guards block open-redirect) ───
$app->route('/go', function ($request, $response) {
    $to = (string) ($request->get['to'] ?? $_GET['to'] ?? '/');
    // Only allow same-origin absolute paths; Response::redirect() enforces the
    // rest (no scheme/host/CRLF). A non-path target falls back to '/'.
    if ($to === '' || $to[0] !== '/') {
        $to = '/';
    }
    Http::redirect($response, $to, 302);
});

// ── B7 — universal return contract: streamed SSR feed (generator) ────────────
$app->route('/feed', function () {
    // Generator → SSR stream. (In mixed mode this hits the filed #354 crash —
    // ZealPulse runs in coroutine mode by default, where streaming is correct.)
    return (function () {
        yield "<!doctype html><meta charset=utf-8><title>feed</title><ul>\n";
        foreach (['boot', 'workers up', 'metrics primed', 'listening'] as $i => $line) {
            yield "  <li>#{$i} {$line}</li>\n";
        }
        yield "</ul>\n";
    })();
});

// B7 — the other return shapes, as small real endpoints:
$app->route('/api/ping', fn () => Http::json(['pong' => true]));        // array → JSON
$app->route('/robots.txt', function () {                                // string → text
    Http::secureHeaders('text/plain; charset=utf-8');
    return "User-agent: *\nDisallow:\n";
});
$app->route('/api/teapot', fn () => 418);                               // int → status

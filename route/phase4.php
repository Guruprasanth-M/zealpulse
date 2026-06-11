<?php
/**
 * ZealPulse — Phase 4: sessions & identity (batches B1–B8).
 *
 *   /login    GET  -> form ; POST -> authenticate, rotate session (fixation), redirect
 *   /logout   POST -> destroy session
 *   /me       GET  -> current identity (session read across requests/workers)
 *   /prefs/set?theme=dark   -> per-user pref in the session
 *   /admin/sessions         -> live session inspector (admin only)
 *
 * Session handler is chosen by ZEAL_SESSION_HANDLER (file|table|store|memory),
 * wired in app.php before run(); the SAME flow works on every backend.
 */
declare(strict_types=1);

use ZealPHP\App;
use ZealPulse\Auth;
use ZealPulse\Http;

$app = App::instance();

// ── B1/B3 — login: authenticate + fixation-safe rotation ─────────────────────
$app->route('/login', methods: ['GET', 'POST'], handler: function ($request) {
    $method = $request->server['request_method'] ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'POST') {
        Http::secureHeaders();
        $flash = Auth::flash() ?? '';
        return <<<HTML
            <!doctype html><meta charset=utf-8><title>Login — ZealPulse</title>
            <link rel="stylesheet" href="/css/app.css">
            <h1>ZealPulse login</h1><p style="color:#f87171">$flash</p>
            <form method="post" action="/login">
              <input name="user" placeholder="user (ops / viewer)" autofocus>
              <input name="pass" type="password" placeholder="password">
              <button>Sign in</button>
            </form>
            HTML;
    }
    // Read via $g->post to stay mode-portable (works in coroutine too).
    $g = \ZealPHP\G::instance();
    $user = (string) ($g->post['user'] ?? '');
    $pass = (string) ($g->post['pass'] ?? '');
    if (Auth::login($user, $pass)) {
        return ['ok' => true, 'user' => $user, 'redirect' => '/me'];
    }
    Auth::flash('Invalid credentials');
    http_response_code(401);
    return Http::json(['ok' => false, 'error' => 'invalid_credentials']);
});

// ── B1 — logout ──────────────────────────────────────────────────────────────
$app->route('/logout', methods: ['POST'], handler: function () {
    Auth::logout();
    return Http::json(['ok' => true, 'logged_out' => true]);
});

// ── B1 — identity read (persists across requests + workers) ──────────────────
$app->route('/me', function () {
    $u = Auth::user();
    if ($u === null) {
        http_response_code(401);
        return Http::json(['authenticated' => false]);
    }
    return Http::json([
        'authenticated' => true,
        'user'          => $u,
        'session_id'    => session_id(),
        'handler'       => getenv('ZEAL_SESSION_HANDLER') ?: 'file',
        'prefs'         => $_SESSION['prefs'] ?? [],
    ]);
});

// ── B1 — per-user prefs in the session ───────────────────────────────────────
$app->route('/prefs/set', function () {
    $g = \ZealPHP\G::instance();
    $theme = (string) ($g->get['theme'] ?? 'light');
    Auth::setPref('theme', in_array($theme, ['light', 'dark'], true) ? $theme : 'light');
    return Http::json(['saved' => true, 'theme' => Auth::pref('theme')]);
});

// ── admin-only live session inspector ────────────────────────────────────────
$app->route('/admin/sessions', function () {
    if (!Auth::isAdmin()) {
        http_response_code(403);
        return Http::json(['error' => 'admin_only']);
    }
    return Http::json([
        'me'          => Auth::user(),
        'session_id'  => session_id(),
        'session_keys'=> array_keys($_SESSION ?? []),
        'cookie_name' => session_name(),
        'gc_maxlife'  => (int) ini_get('session.gc_maxlifetime'),
    ]);
});

<?php
/**
 * ZealPulse — Phase 2: request input & SAPI surface (batches B1–B11).
 *
 * Real input features, each exercising a Phase-2 batch:
 *   B1 $_GET (filter) · B2 $_POST + php://input (submit) · B3 $_FILES (upload) ·
 *   B4 $_COOKIE · B5 $_REQUEST (read $_GET/$_POST explicitly — #356) ·
 *   B6 $_SERVER (guard int ports — #306) · B7 Basic-auth meta-vars ·
 *   B8 getallheaders() · B9 PSR-7/RequestInput · B10 limits + G aliasing · B11 re-verify.
 *
 * Input is read via ZealPulse\Req ($g-based → works in ALL modes); the /whoami
 * inspector also dumps raw $_* to show the superglobal surface (populated in
 * mixed/legacy-cgi/coroutine-legacy; n/a in coroutine by design).
 */
declare(strict_types=1);

use ZealPHP\App;
use ZealPulse\Http;
use ZealPulse\Req;

$app = App::instance();

// ── B1 — $_GET: metrics filter ───────────────────────────────────────────────
$app->route('/search', function () {
    $route = Req::query('route', '');
    $min   = (int) Req::query('min', '0');
    $tags  = Req::g()->get['tags'] ?? [];   // array syntax ?tags[]=a&tags[]=b
    return Http::json([
        'filter' => ['route' => $route, 'min_hits' => $min, 'tags' => is_array($tags) ? $tags : [$tags]],
        'note'   => 'parsed from $_GET via $g (mode-portable)',
    ]);
});

// ── B2 — $_POST + php://input: submit an event ───────────────────────────────
$app->route('/events/submit', methods: ['POST'], handler: function () {
    $ctype = (string) Req::server('CONTENT_TYPE', '');
    if (str_contains($ctype, 'application/json')) {
        $body = Req::json();                       // JSON → php://input (not $_POST)
        $src  = 'json';
    } else {
        $body = ['type' => Req::post('type', 'note'), 'msg' => Req::post('msg', '')];
        $src  = 'form';
    }
    return Http::json(['accepted' => true, 'source' => $src, 'event' => $body, 'raw_len' => strlen(Req::rawBody())]);
});

// ── B3 — $_FILES: avatar upload (field-major layout #304, upload shims) ──────
$app->route('/upload', methods: ['POST'], handler: function () {
    $files = $_FILES ?? [];
    $report = [];
    foreach ($files as $field => $f) {
        $tmp = is_array($f['tmp_name'] ?? null) ? ($f['tmp_name'][0] ?? '') : ($f['tmp_name'] ?? '');
        $report[$field] = [
            'name'        => $f['name'] ?? null,
            'shape'       => is_array($f['name'] ?? null) ? 'field-major (array)' : 'single',
            'is_uploaded' => $tmp !== '' ? is_uploaded_file($tmp) : null,
        ];
    }
    return Http::json(['uploaded' => $report, 'forged_check' => is_uploaded_file('/etc/passwd')]);
});

// ── B4 — $_COOKIE: read the theme pref set in Phase 1 ────────────────────────
$app->route('/whoami/cookies', function () {
    return Http::json(['cookies' => Req::g()->cookie ?? []]);
});

// ── B5/B6/B7/B8 — request-context inspector (the diagnostic) ─────────────────
$app->route('/whoami', function () {
    // B6: guard int ports (#306) — coerce to string. B5: read $_GET/$_POST, never $_REQUEST.
    $auth = Req::basicAuth();
    return Http::json([
        'mode'        => getenv('ZEAL_MODE') ?: 'coroutine',
        'method'      => Req::server('REQUEST_METHOD'),
        'uri'         => Req::server('REQUEST_URI'),
        'scheme'      => Req::server('REQUEST_SCHEME'),
        'server_port' => Req::server('SERVER_PORT'),               // string-coerced (B6/#306)
        'server_addr' => Req::server('SERVER_ADDR', '(absent)'),   // #306: often absent
        'auth_user'   => $auth['user'] ?? null,                    // B7
        'headers'     => function_exists('getallheaders') ? array_keys(getallheaders()) : [],  // B8
        'superglobal_get_populated' => isset($_GET) && $_GET !== [],  // B10: n/a in coroutine
    ]);
});

// ── B7 — Basic-auth gated admin probe ────────────────────────────────────────
$app->route('/admin/probe', function ($response) {
    $auth = Req::basicAuth();
    if ($auth === null || $auth['user'] !== 'ops' || $auth['pass'] !== 'pulse') {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="ZealPulse Admin"');
        return Http::json(['error' => 'unauthorized']);
    }
    return Http::json(['ok' => true, 'user' => $auth['user'], 'role' => 'admin']);
});

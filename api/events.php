<?php
/**
 * ZealPulse file-API (ZealAPI) — /api/events.
 *   GET  /api/events        → list recent events (sample; Store-backed in Phase 8)
 *   POST /api/events        → create an event (reads $_POST / JSON body)
 *
 * ZealAPI resolves /api/events → api/events.php and dispatches by method var
 * ($get / $post). Business logic stays in src/ (here trivial; grows in Phase 8).
 */
declare(strict_types=1);

use ZealPulse\Req;

$get = function ($request, $response) {
    header('Content-Type: application/json; charset=utf-8');
    return [
        'events' => [
            ['id' => 1, 'type' => 'boot',    'msg' => 'workers up'],
            ['id' => 2, 'type' => 'metrics', 'msg' => 'primed'],
        ],
        'count' => 2,
    ];
};

$post = function ($request, $response) {
    header('Content-Type: application/json; charset=utf-8');
    $ctype = (string) ($request->server['content_type'] ?? $request->header['content-type'] ?? '');
    $event = str_contains($ctype, 'application/json')
        ? Req::json()
        : ['type' => Req::post('type', 'note'), 'msg' => Req::post('msg', '')];
    return ['created' => true, 'event' => $event];
};

<?php

declare(strict_types=1);

/**
 * Phase 11 — the background machine (the /phase11 + /_metrics surface).
 *
 * Thin handlers only — logic lives in ZealPulse\Prober / Background. The probe
 * round + report build exercise the concurrency primitives (App::parallelLimit,
 * HTTP::all, task workers) verified in zealphp-exp Phase 11.
 */

use ZealPHP\App;
use ZealPulse\Prober;
use ZealPulse\Background;

$app = App::instance();

// B-overview — the background machine's live state (timers/sidecar/signals/tasks).
$app->route('/phase11', fn () => [
    'background_machine' => Background::counters(),
    'stats'              => App::stats(),
    'endpoints'          => [
        'metrics' => '/_metrics       — App::stats + background counters',
        'prober'  => '/phase11/prober — one concurrent probe round (HTTP::all)',
        'report'  => '/phase11/report — enqueue a report build on a task worker',
        'host'    => '/phase11/host    — coroutine-yielding shell probe (App::exec)',
    ],
]);

// B-metrics — the ops metrics endpoint (App::stats snapshot + bg counters).
$app->route('/_metrics', fn () => [
    'workers'    => App::stats()['workers'] ?? null,
    'memory'     => App::stats()['memory'] ?? null,
    'uptime_sec' => App::stats()['uptime_sec'] ?? null,
    'background' => Background::counters(),
]);

// B4 — the uptime prober: probe all targets CONCURRENTLY (wall ≈ slowest target).
$app->route('/phase11/prober', fn () => Prober::round());

// B3 — offload a report build to a task worker (request thread never blocks).
$app->route('/phase11/report', function ($request) {
    $kind = (string) ($request->get['kind'] ?? 'uptime');
    $ok   = App::getServer()->task(['handler' => '/task/report', 'args' => [$kind]]);
    return ['enqueued' => $ok !== false, 'task_id' => $ok, 'note' => 'built on a task worker; see zp_reports_built in /_metrics'];
});

// B7 — shell probe: App::exec (coroutine-yielding, returns output lines) vs
// App::rawExec (blocking, returns the raw string).
$app->route('/phase11/host', function () {
    $up = App::exec('uptime');                       // array of output lines
    return [
        'uptime_yield' => trim(is_array($up) ? implode("\n", $up) : (string) $up),
        'disk_raw'     => trim((string) App::rawExec("df -h / | tail -1")),
    ];
});

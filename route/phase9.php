<?php

declare(strict_types=1);

/**
 * Phase 9 — Lifecycle modes & per-coroutine isolation lab (the /modes surface).
 *
 * Thin handlers only — logic lives in ZealPulse\ModesLab. The isolation burst
 * endpoints are designed to be hit by N concurrent clients with unique tokens;
 * a single sequential request can never prove isolation.
 */

use ZealPHP\App;
use ZealPulse\ModesLab;

$app = App::instance();

// B1 — the live mode matrix (truthful in whatever mode the app booted in).
$app->route('/modes', fn () => ModesLab::matrix());

// B2 — isolation burst probe: /modes/burst?token=UNIQUE — each request writes
// its token, yields, re-reads. Client fires 40 concurrent with distinct tokens
// and checks every echo matches (zero cross-coroutine leak).
$app->route('/modes/burst', fn ($request) =>
    ModesLab::burstProbe((string) ($request->get['token'] ?? ('t-' . getmypid() . '-' . \OpenSwoole\Coroutine::getCid()))));

// B3 — per-coroutine process-state demo: /modes/tz?zone=Asia/Kolkata — sets a
// per-request timezone, yields, re-reads; concurrent requests must not leak in.
$app->route('/modes/tz', fn ($request) =>
    ModesLab::processStateProbe((string) ($request->get['zone'] ?? 'UTC')));

// B6 — ini_set / putenv request-overlay isolation under burst.
$app->route('/modes/ini', fn ($request) =>
    ModesLab::iniProbe((string) ($request->get['val'] ?? ('v-' . \OpenSwoole\Coroutine::getCid()))));

// B4 — boot-guard / refused-combo description + the enum's unknown-rejection.
$app->route('/modes/guard', fn () => ModesLab::refusedCombos());

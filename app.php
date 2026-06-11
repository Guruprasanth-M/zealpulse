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

// Route modules in route/*.php are auto-included by ZealPHP at boot, in name
// order (phase1, phase2, …). Each grabs the app via App::instance().

fwrite(STDERR, sprintf("[zealpulse] mode=%s port=%d\n", $mode, $port));
$app->run(['worker_num' => 2]);

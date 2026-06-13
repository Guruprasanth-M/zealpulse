<?php

declare(strict_types=1);

namespace ZealPulse;

use OpenSwoole\Coroutine;
use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\Isolation;

/**
 * Phase 9 — the lifecycle-modes & per-coroutine isolation lab.
 *
 * Renders the live mode matrix (which mode am I in, what's populated, which
 * manager/dispatch path, every isolation knob's state), and the burst probes
 * that PROVE per-coroutine isolation: a single sequential request can never
 * prove it, so each probe forces a mid-flight yield and the client fires N
 * concurrent requests with unique tokens and checks for zero cross-talk.
 *
 * Everything degrades gracefully without ext-zealphp (coroutine-legacy is inert
 * there) — the lab records the OFF-state leak instead of crashing.
 */
final class ModesLab
{
    /** Per-request class static — the isolation burst proves this never leaks across coroutines. */
    private static string $lastToken = '';

    /** B1 — the live mode matrix: mode label, the two axes, every knob, what's populated. */
    public static function matrix(): array
    {
        $extLoaded = extension_loaded('zealphp');
        $sg = App::$superglobals;
        return [
            'mode_env'    => getenv('ZEAL_MODE') ?: 'coroutine',
            'axes' => [
                'superglobals'                  => $sg,
                'isolation'                     => App::isolation(),               // enum string
                'coroutine_isolated_superglobals' => App::$coroutine_isolated_superglobals,
            ],
            'derived' => [
                'enableCoroutine'  => App::enableCoroutine(),
                'hookAll'          => App::hookAll(),
                'processIsolation' => App::processIsolation(),
            ],
            'populated' => [
                // In coroutine (sg=false) the superglobals are NOT populated — $g is the source.
                'GET_populated'  => $sg && isset($_GET),
                'source_of_truth' => $sg ? '$_GET/$_SESSION (+ $g alias)' : '$g / RequestContext only',
                'session_manager' => $sg && !App::enableCoroutine() ? 'SessionManager (sequential)' : 'CoSessionManager (coroutine)',
                'dispatch_path'   => App::processIsolation() ? 'CGI subprocess' : 'in-process executeFile()',
            ],
            'corleg_knobs' => [
                'silentRedeclare'            => self::knob('silentRedeclare'),
                'includeIsolation'           => self::knob('includeIsolation'),
                'coroutineGlobalsIsolation'  => self::knob('coroutineGlobalsIsolation'),
                'coroutineStaticsIsolation'  => self::knob('coroutineStaticsIsolation'),
                'functionIsolation'          => self::knob('functionIsolation'),
                'defineIsolation'            => self::knob('defineIsolation'),
                'keepGlobals'                => self::knob('keepGlobals'),
                'globalScopeInclude'         => self::knob('globalScopeInclude'),
                'perRequestStateResetsActive' => App::perRequestStateResetsActive(),
            ],
            'process_state_knobs' => [
                'coroutineCwdIsolation'      => self::knob('coroutineCwdIsolation'),
                'coroutineLocaleIsolation'   => self::knob('coroutineLocaleIsolation'),
                'coroutineTimezoneIsolation' => self::knob('coroutineTimezoneIsolation'),
            ],
            'ext_zealphp' => [
                'loaded'  => $extLoaded,
                'version' => $extLoaded ? phpversion('zealphp') : null,
                // The version string can lie on a doc-only re-tag; the symbol table can't.
                'isolation_symbols' => $extLoaded ? [
                    'reset_request_class_statics' => function_exists('zealphp_reset_request_class_statics'),
                    'reset_request_statics'       => function_exists('zealphp_reset_request_statics'),
                    'require_global'              => function_exists('zealphp_require_global'),
                    'process_state_snapshot'      => function_exists('zealphp_process_state_snapshot'),
                ] : null,
            ],
            'worker_pid' => getmypid(),
            'cid'        => Coroutine::getCid(),
        ];
    }

    /**
     * B2 — isolation burst probe. Writes a unique token to $g, a class static,
     * and (when superglobals are on) $_GET, then FORCES a yield, then re-reads
     * all three. The client fires N concurrent requests with distinct tokens;
     * any response whose echo != its token is a cross-coroutine leak.
     */
    public static function burstProbe(string $token): array
    {
        $g = G::instance();
        // $g->memo is the per-request memo (a declared RequestContext property);
        // arbitrary dynamic $g->props are rejected in coroutine mode by design.
        $g->memo['probe_token'] = $token;
        self::$lastToken = $token;
        $sgBefore = null;
        if (App::$superglobals) {
            $_GET['probe_token'] = $token;
            $sgBefore = $token;
        }
        // Force a mid-flight yield so 40 concurrent requests interleave here.
        usleep(20000);   // yields the coroutine under HOOK_ALL (forces interleave)
        $gAfter  = $g->memo['probe_token'] ?? null;
        $stAfter = self::$lastToken;
        $sgAfter = App::$superglobals ? ($_GET['probe_token'] ?? null) : null;
        return [
            'token'        => $token,
            'g_after'      => $gAfter,
            'static_after' => $stAfter,
            'sg_after'     => $sgAfter,
            'g_ok'         => $gAfter === $token,
            'static_ok'    => $stAfter === $token,    // class static: only isolated in coroutine-legacy
            'sg_ok'        => $sgBefore === null ? null : ($sgAfter === $token),
            'pid'          => getmypid(),
            'cid'          => Coroutine::getCid(),
        ];
    }

    /**
     * B3 — per-coroutine process-state demo. Sets a per-request timezone (a
     * PROCESS-global in stock PHP), yields, re-reads. With coroutineTimezone-
     * Isolation on (coroutine-legacy) a concurrent request's TZ never leaks in.
     */
    public static function processStateProbe(string $tz): array
    {
        $before = date_default_timezone_get();
        @date_default_timezone_set($tz);
        usleep(20000);   // yields the coroutine under HOOK_ALL (forces interleave)
        $after = date_default_timezone_get();
        return [
            'requested'    => $tz,
            'before'       => $before,
            'after_yield'  => $after,
            'isolated_ok'  => $after === $tz,         // true if no concurrent request clobbered it
            'knob'         => self::knob('coroutineTimezoneIsolation'),
            'pid'          => getmypid(),
            'cid'          => Coroutine::getCid(),
        ];
    }

    /**
     * B6 — ini_set / putenv request-overlay isolation. Sets a unique value,
     * yields, re-reads. The framework override scopes these per request ($g).
     */
    public static function iniProbe(string $val): array
    {
        ini_set('default_charset', $val);
        putenv("ZP_PROBE={$val}");
        usleep(20000);   // yields the coroutine under HOOK_ALL (forces interleave)
        return [
            'val'         => $val,
            'ini_after'   => ini_get('default_charset'),
            'env_after'   => getenv('ZP_PROBE'),
            'ini_ok'      => ini_get('default_charset') === $val,
            'env_ok'      => getenv('ZP_PROBE') === $val,
            'pid'         => getmypid(),
            'cid'         => Coroutine::getCid(),
        ];
    }

    /**
     * B4 — boot-guard / refused-combo description. The actual guard
     * (validateLifecycleCombination) runs at boot and is frozen; here we
     * document the refused matrix and prove the Isolation enum rejects unknowns.
     */
    public static function refusedCombos(): array
    {
        $coerceRejects = false;
        try {
            Isolation::coerce('totally-unknown');
        } catch (\InvalidArgumentException) {
            $coerceRejects = true;
        }
        return [
            'isolation_enum_rejects_unknown' => $coerceRejects,
            'isolation_cases' => array_map(fn (Isolation $c) => $c->value, Isolation::cases()),
            'refused_at_boot' => [
                'sg=false + enableCoroutine=false' => 'ALWAYS refused (CoSessionManager needs the scheduler)',
                'sg=true + enableCoroutine=true (no ext-zealphp)' => 'refused — coroutine-legacy needs ext-zealphp',
                'sg=true + hookAll!=0 (no ext-zealphp)'          => 'refused — superglobals-under-HOOK_ALL would race',
            ],
            'auto_fallbacks' => [
                'pi=true + ec=true + sg=true'  => 'force ec=false + hookAll=0 (CGI uses blocking proc_open)',
                'pi=true + ec=true + sg=false' => 'force pi=false',
            ],
        ];
    }

    /** Read a bool knob defensively (some are coroutine-legacy-only). */
    private static function knob(string $method): bool|string
    {
        try {
            return App::$method(null);
        } catch (\Throwable $e) {
            return 'n/a';
        }
    }
}

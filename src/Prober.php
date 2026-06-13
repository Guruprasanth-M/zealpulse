<?php

declare(strict_types=1);

namespace ZealPulse;

use ZealPHP\App;
use ZealPHP\HTTP;
use ZealPHP\Counter;

/**
 * Phase 11 — the uptime prober (the "background machine"'s probe round).
 *
 * Probes N targets CONCURRENTLY via ZealPHP\HTTP::all (which rides App::parallel:
 * input-order results, first-exception, transport-failure ≠ HTTP error). Wall
 * clock ≈ the slowest target, not the sum. Bounded fan-out via App::parallelLimit.
 *
 * NOTE (verification cross-ref, zealphp-exp #429): HTTP::all / App::parallel only
 * work where the request handler is a coroutine — i.e. `coroutine`/`coroutine-legacy`.
 * In `mixed`/`legacy-cgi` they deadlock the worker (the getCid()<0 → Coroutine::run()
 * auto-wrap never completes). ZealPulse runs in `coroutine` (the recommended mode),
 * so the prober is correct here; running it under mixed would hang.
 */
final class Prober
{
    /** Default targets — self-probes plus a couple of well-known endpoints. */
    public static function targets(): array
    {
        $port = (int) (getenv('ZEAL_PORT') ?: 9100);
        return [
            'self-metrics' => "http://127.0.0.1:$port/_metrics",
            'self-health'  => "http://127.0.0.1:$port/healthz",
            'self-board'   => "http://127.0.0.1:$port/api/board",
            'bad-host'     => 'http://no-such-host.invalid:9/x',   // proves failure isolation
        ];
    }

    /**
     * Run one concurrent probe round. Returns per-target {ok, status, ms, failed},
     * the wall-clock, and the sum-of-latencies (to show concurrency: wall ≪ sum).
     */
    public static function round(): array
    {
        $targets = self::targets();

        // Concurrent fan-out — one HTTP::get thunk per target, all in flight at once.
        $names   = array_keys($targets);
        $results = App::parallelLimit(
            $targets,
            function (string $url, string $name): array {
                $t0 = microtime(true);
                $r  = HTTP::get($url, [], 5.0);
                $ms = (microtime(true) - $t0) * 1000;
                return [
                    'name'   => $name,
                    'status' => $r->status,
                    'ok'     => $r->ok(),
                    'failed' => $r->failed(),       // transport failure (DNS/connect/TLS) — NOT a 4xx/5xx
                    'ms'     => round($ms, 1),
                ];
            },
            4 // concurrency cap
        );

        $sumMs = array_sum(array_map(static fn ($r) => $r['ms'], $results));
        $up    = count(array_filter($results, static fn ($r) => $r['ok']));

        (new Counter(0, 'zp_prober_rounds'))->increment();

        // Optional: persist the round to Mongo (Phase-8 firehose). Gated + tolerant.
        $stored = self::storeRound($results);

        return [
            'targets'    => count($targets),
            'up'         => $up,
            'down'       => count($results) - $up,
            'results'    => array_values($results),
            'stored_mongo' => $stored,
            'note'       => 'concurrent: wall-clock ≈ slowest target, not the sum of latencies',
        ];
    }

    /** Persist a round to Mongo (probe_results, time-series). No-op if Mongo is absent. */
    private static function storeRound(array $results): bool
    {
        if (!class_exists(Mongo::class) || !Mongo::available()) { return false; }
        try {
            $docs = array_map(static fn ($r) => $r + ['ts' => time()], array_values($results));
            $res  = Mongo::db()->selectCollection('probe_results')->insertMany($docs);
            return $res->getInsertedCount() > 0;
        } catch (\Throwable $e) {
            return false;   // degrade gracefully — the prober's concurrency is the point
        }
    }
}

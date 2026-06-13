<?php

declare(strict_types=1);

namespace ZealPulse;

use ZealPHP\Counter;

/**
 * Phase 11 — the background machine's worker-side logic: the per-worker metric
 * tick aggregation and the sidecar's prune pass. Thin, static, side-effecting.
 */
final class Background
{
    /** Per-worker tick (every 5s) — bump a shared aggregation counter. */
    public static function aggregateTick(int $workerId): void
    {
        (new Counter(0, 'zp_tick_aggregations'))->increment();
    }

    /**
     * Sidecar prune pass — drop probe_results older than the retention window
     * from Mongo (the firehose's TTL backstop). No-op when Mongo is absent.
     */
    public static function pruneOldProbes(int $retentionSec = 3600): int
    {
        if (!class_exists(Mongo::class) || !Mongo::available()) { return 0; }
        try {
            $cutoff = time() - $retentionSec;
            $res = Mongo::db()->selectCollection('probe_results')
                ->deleteMany(['ts' => ['$lt' => $cutoff]]);
            return $res->getDeletedCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Snapshot the background-machine counters for /_metrics and /phase11. */
    public static function counters(): array
    {
        $names = ['zp_prober_rounds', 'zp_reports_built', 'zp_warmup', 'zp_sigterm',
                  'zp_statsdump', 'zp_pruner_runs', 'zp_tick_aggregations'];
        $out = [];
        foreach ($names as $n) { $out[$n] = (new Counter(0, $n))->get(); }
        return $out;
    }
}

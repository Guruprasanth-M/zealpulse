<?php
/**
 * Phase 11 task handler — heavy report build, offloaded to a task worker so the
 * request thread never blocks. Closure name MUST equal the filename
 * (task/report.php → $report), invoked by App::dispatchTaskCallback($args).
 *
 * Runs in a SEPARATE task-worker process (its own state — no request $g). Bumps
 * a shared atomic counter to prove it ran, and aggregates from Mongo when the
 * firehose is available (Phase-8 cross-ref), degrading to a synthetic summary.
 */
use ZealPHP\Counter;
use ZealPulse\Mongo;

$report = function (string $kind = 'uptime') {
    $rounds = (new Counter(0, 'zp_prober_rounds'))->get();

    $sample = null;
    if (class_exists(Mongo::class) && Mongo::available()) {
        try {
            $sample = Mongo::db()->selectCollection('probe_results')->countDocuments();
        } catch (\Throwable $e) {
            $sample = null;
        }
    }

    (new Counter(0, 'zp_reports_built'))->increment();

    return [
        'kind'           => $kind,
        'built_by_pid'   => getmypid(),
        'prober_rounds'  => $rounds,
        'mongo_samples'  => $sample,          // null when the firehose is absent
        'built_at'       => time(),
    ];
};

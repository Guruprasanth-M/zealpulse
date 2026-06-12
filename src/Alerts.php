<?php

declare(strict_types=1);

namespace ZealPulse;

use Throwable;
use ZealPHP\App;
use ZealPHP\Store;
use ZealPHP\Store\StoreException;

/**
 * Phase 8 — alert fan-out (fire-and-forget pub/sub) + the restart-surviving
 * audit trail (reliable streams, consumer group + XACK + reclaim).
 *
 * Both REQUIRE the Redis Store backend; on Table they throw StoreException —
 * caught and surfaced as `skip` so the app still runs on the default backend.
 */
final class Alerts
{
    public const CHANNEL = 'zp_alerts';
    public const STREAM = 'zp_alerts_audit';

    /** Wire the per-worker subscriber + the audit consumer. Call in onWorkerStart. */
    public static function wire(): void
    {
        // Fan-out: every worker registers ONE handler; delivery is proven by
        // each worker recording its own pid into the delivery log table.
        App::onPubSub(self::CHANNEL, static function (string $payload): void {
            $key = 'w:' . getmypid();
            if (!Store::exists('alert_log', $key)) {
                Store::set('alert_log', $key, ['worker' => getmypid(), 'seen' => 0, 'last' => '']);
            }
            Store::incr('alert_log', $key, 'seen');
            Store::set('alert_log', $key, [
                'worker' => getmypid(),
                'seen'   => (int) Store::get('alert_log', $key, 'seen'),
                'last'   => substr($payload, 0, 120),
            ]);
        });

        // Audit trail: consumer-group member per worker; handler returns true
        // → XACK; the group + pending entries survive a server restart.
        App::onReliableMessage(self::STREAM, static function (string $payload, string $id): bool {
            $key = 'a:' . substr($id, 0, 48);
            Store::set('alert_audit', $key, [
                'msg_id' => substr($id, 0, 48),
                'worker' => getmypid(),
                'body'   => substr($payload, 0, 120),
            ]);
            return true;
        }, 'zealpulse-audit');
    }

    /** Publish an alert: pub/sub fan-out + reliable audit copy. */
    public static function publish(string $kind, string $msg): array
    {
        $payload = json_encode(['kind' => $kind, 'msg' => $msg, 'from' => getmypid(), 'ts' => time()]);
        try {
            $receivers = Store::publish(self::CHANNEL, (string) $payload);
            $auditId = Store::publishReliable(self::STREAM, (string) $payload, 10000);
            return ['ok' => true, 'receivers' => $receivers, 'audit_id' => $auditId];
        } catch (StoreException $e) {
            return ['ok' => false, 'skip' => 'pub/sub needs the Redis Store backend', 'error' => $e->getMessage()];
        }
    }

    /** Which workers saw the last fan-out (B13's cross-worker proof). */
    public static function deliveryLog(): array
    {
        $out = [];
        try {
            foreach (Store::iterate('alert_log') as $key => $row) {
                $out[$key] = $row;
            }
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
        return $out;
    }

    /** Audit entries consumed so far (B14 — survives restart via the group). */
    public static function auditLog(): array
    {
        $out = [];
        try {
            foreach (Store::iterate('alert_audit') as $key => $row) {
                $out[$key] = $row;
            }
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
        return $out;
    }
}

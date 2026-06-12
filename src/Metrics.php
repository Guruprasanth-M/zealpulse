<?php

declare(strict_types=1);

namespace ZealPulse;

use ZealPHP\Cache;
use ZealPHP\Counter;
use ZealPHP\Store;
use ZealPHP\Store\StoreException;

/**
 * Phase 8 — the shared-memory metrics spine.
 *
 * Per-route counters live in the `route_metrics` Store table (typed cols),
 * atomic totals in `Counter`, per-route active-viewer sets in Store set-ops
 * (Redis-only — skip-recorded on Table), and the board aggregate is cached
 * through Cache::getOrCompute under the `route-metrics` tag.
 *
 * All tables are made in app.php BEFORE $app->run() (master-fork rule).
 */
final class Metrics
{
    public const TABLE = 'route_metrics';
    /** Logical route names — /pulse/hit/{route} records one of these. */
    public const ROUTES = ['board', 'feed', 'incidents', 'rollup'];

    private static ?Counter $totalHits = null;
    private static ?Counter $online = null;

    public static function totalHits(): Counter
    {
        return self::$totalHits ??= new Counter(0, 'zp_total_hits');
    }

    public static function online(): Counter
    {
        return self::$online ??= new Counter(0, 'zp_online');
    }

    /** One request observed: Store::incr per route + Counter total. */
    public static function recordHit(string $route, float $ms, bool $err = false): void
    {
        $key = self::routeKey($route);
        if (!Store::exists(self::TABLE, $key)) {
            Store::set(self::TABLE, $key, ['route' => $route, 'hits' => 0, 'errs' => 0, 'total_us' => 0]);
        }
        Store::incr(self::TABLE, $key, 'hits');
        Store::incr(self::TABLE, $key, 'total_us', (int) round($ms * 1000));
        if ($err) {
            Store::incr(self::TABLE, $key, 'errs');
        }
        self::totalHits()->increment();
    }

    /** Batch board read — ONE mget over every known route row. */
    public static function board(): array
    {
        $keys = array_map([self::class, 'routeKey'], self::ROUTES);
        $rows = Store::mget(self::TABLE, $keys);
        $out = [];
        foreach (self::ROUTES as $i => $route) {
            $row = $rows[$keys[$i]] ?? null;
            $out[$route] = $row === null ? ['hits' => 0, 'errs' => 0, 'avg_ms' => 0.0] : [
                'hits'   => (int) $row['hits'],
                'errs'   => (int) $row['errs'],
                'avg_ms' => $row['hits'] > 0 ? round($row['total_us'] / $row['hits'] / 1000, 3) : 0.0,
            ];
        }
        return ['routes' => $out, 'total' => self::totalHits()->get(), 'worker_pid' => getmypid()];
    }

    /** Cached board aggregate — stampede-guarded, tag-invalidated on writes. */
    public static function cachedBoard(): array
    {
        $hit = ['computed' => false];
        $board = Cache::getOrCompute('board-agg', function () use (&$hit) {
            $hit['computed'] = true;
            return self::board();
        }, 5);
        // Tag registration: Cache::getOrCompute has no tag param — pair it with
        // an explicit tagged set so invalidateTag('route-metrics') can evict.
        if ($hit['computed']) {
            Cache::set('board-agg', $board, 5, ['route-metrics']);
        }
        return $board + ['cache_computed' => $hit['computed']];
    }

    public static function invalidateBoard(): int
    {
        return Cache::invalidateTag('route-metrics');
    }

    /** Event-ring page scan (the P7 live_ring table) via iteratePaged. */
    public static function ringPage(string $cursor = '0', int $count = 25): array
    {
        return Store::iteratePaged('live_ring', $cursor, $count);
    }

    /**
     * Per-route active-viewer sets — Store set-ops, Redis-only.
     * On TableBackend these throw StoreException by design: skip-recorded.
     */
    public static function viewerJoin(string $route, string $who): array
    {
        try {
            Store::sadd('zp_viewers:' . self::routeKey($route), $who);
            return ['ok' => true, 'viewers' => Store::scard('zp_viewers:' . self::routeKey($route))];
        } catch (StoreException $e) {
            return ['ok' => false, 'skip' => 'set-ops unsupported on this backend', 'error' => $e->getMessage()];
        }
    }

    public static function viewerLeave(string $route, string $who): array
    {
        try {
            Store::srem('zp_viewers:' . self::routeKey($route), $who);
            return ['ok' => true, 'viewers' => Store::scard('zp_viewers:' . self::routeKey($route))];
        } catch (StoreException $e) {
            return ['ok' => false, 'skip' => 'set-ops unsupported on this backend', 'error' => $e->getMessage()];
        }
    }

    /** Config flip via CAS — only one concurrent flipper wins. */
    public static function flipFlag(string $flag, string $expect, string $new): array
    {
        if (!Store::exists(self::TABLE, 'flag:' . $flag)) {
            Store::set(self::TABLE, 'flag:' . $flag, ['route' => 'off', 'hits' => 0, 'errs' => 0, 'total_us' => 0]);
        }
        $won = Store::compareAndSet(self::TABLE, 'flag:' . $flag, 'route', $expect, $new);
        return ['won' => $won, 'now' => Store::get(self::TABLE, 'flag:' . $flag, 'route')];
    }

    /** Backend switchboard introspection for /pulse/store-backend. */
    public static function backendInfo(): array
    {
        $backend = Store::defaultBackend();
        return [
            'backend'  => $backend::class,
            'env'      => getenv('ZEALPHP_STORE_BACKEND') ?: '(default table)',
            'ping'     => Store::ping(),
            'stats'    => Store::stats(),
            'set_ops'  => Store::hasSetOps(),
            'tables'   => Store::names(),
            'advisory' => Store::tieredAdvisory($backend),
        ];
    }

    private static function routeKey(string $route): string
    {
        return 'r:' . trim($route, '/') ?: 'r:root';
    }
}

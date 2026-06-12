<?php

declare(strict_types=1);

namespace ZealPulse;

use MongoDB\Client;
use MongoDB\Database;
use Throwable;

/**
 * Phase 8 — MongoDB layer bootstrap (firehose + analytics + vault).
 *
 * Per-worker Client singleton (built lazily / in onWorkerStart). Driver:
 * mongodb/mongodb 2.3 over ext-mongodb (libmongoc). NOTE the spec's Rust
 * async driver (zealphp/mongodb, AsyncBridge) is NOT installed — libmongoc
 * sockets are C-level and NOT hooked by OpenSwoole, so Mongo ops BLOCK the
 * worker; the B9 async proof is skip-recorded with this evidence.
 *
 * Graceful absence: ZP_MONGO_URI unset → available() false.
 */
final class Mongo
{
    private static ?Client $client = null;
    private static bool $indexed = false;

    public static function available(): bool
    {
        return getenv('ZP_MONGO_URI') !== false;
    }

    public static function client(): Client
    {
        if (self::$client === null) {
            $uri = getenv('ZP_MONGO_URI');
            if ($uri === false || trim($uri) === '') {
                throw new \RuntimeException('Mongo layer unavailable (ZP_MONGO_URI unset)');
            }
            self::$client = new Client($uri, [], [
                'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
            ]);
        }
        return self::$client;
    }

    public static function db(): Database
    {
        return self::client()->selectDatabase(getenv('ZP_MONGO_DB') ?: 'zealpulse');
    }

    /** db-level ping via command() — boot health for /_metrics. */
    public static function ping(): array
    {
        try {
            $r = self::db()->command(['ping' => 1])->toArray()[0] ?? [];
            return ['ok' => (float) ($r['ok'] ?? 0) === 1.0];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Index bootstrap (idempotent): TTL index on events.ts = self-pruning
     * firehose, compound route+ts = the feed query's index.
     */
    public static function ensureIndexes(): array
    {
        if (self::$indexed) {
            return ['done' => 'already'];
        }
        $events = self::db()->selectCollection('events');
        $names = $events->createIndexes([
            ['key' => ['ts' => 1], 'expireAfterSeconds' => 3600, 'name' => 'ttl_ts'],
            ['key' => ['route' => 1, 'ts' => -1], 'name' => 'route_ts'],
        ]);
        self::$indexed = true;
        $listed = [];
        foreach ($events->listIndexes() as $idx) {
            $listed[] = $idx->getName();
        }
        return ['created' => $names, 'listed' => $listed];
    }

    /** Replica-set detection — gates transactions + change streams (B10). */
    public static function isReplicaSet(): bool
    {
        try {
            $r = self::client()->selectDatabase('admin')->command(['hello' => 1])->toArray()[0] ?? [];
            return isset($r['setName']);
        } catch (Throwable) {
            return false;
        }
    }
}

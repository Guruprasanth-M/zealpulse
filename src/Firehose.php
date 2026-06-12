<?php

declare(strict_types=1);

namespace ZealPulse;

use MongoDB\BSON\Binary;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use Throwable;
use ZealPHP\App;

/**
 * Phase 8 — the Mongo event firehose + analytics layer.
 *
 * Every event is a flexible document in `events`; aggregation-pipeline
 * rollups power the board; BSON types are used naturally (ObjectId ids,
 * UTCDateTime timestamps, Regex search, Binary payload sample, Decimal128
 * latency). The §1.1 contract: events/analytics live ONLY here — the SQL
 * side holds only fixed-schema facts and cross-references by id.
 */
final class Firehose
{
    public static function events(): Collection
    {
        return Mongo::db()->selectCollection('events');
    }

    /** Fire-and-forget emit — insertOne pushed off the request path via App::go. */
    public static function emit(string $type, string $route, string $msg, float $latencyMs = 0.0): string
    {
        $id = new ObjectId();
        $doc = [
            '_id'     => $id,
            'type'    => $type,
            'route'   => $route,
            'msg'     => $msg,
            'ts'      => new UTCDateTime(),
            'latency' => new Decimal128(number_format($latencyMs, 3, '.', '')),
            'sample'  => new Binary(substr($msg, 0, 16), Binary::TYPE_GENERIC),
        ];
        $went = App::go(static function () use ($doc) {
            try {
                self::events()->insertOne($doc);
            } catch (Throwable $e) {
                error_log('[firehose] emit failed: ' . $e->getMessage());
            }
        });
        if ($went === false) {
            self::events()->insertOne($doc);
        }
        return (string) $id;
    }

    /** Probe-round batch — ONE insertMany. */
    public static function emitBatch(array $rows): array
    {
        $docs = array_map(static fn (array $r) => [
            'type'  => $r['type'] ?? 'probe',
            'route' => $r['route'] ?? '/probe',
            'msg'   => $r['msg'] ?? '',
            'ts'    => new UTCDateTime(),
        ], $rows);
        $res = self::events()->insertMany($docs);
        return ['inserted' => $res->getInsertedCount()];
    }

    /** Feed scan — find with Regex search, cursor iterated lazily. */
    public static function feed(?string $search = null, int $limit = 20): array
    {
        $filter = $search !== null && $search !== ''
            ? ['msg' => new Regex(preg_quote($search, '/'), 'i')]
            : [];
        $cursor = self::events()->find($filter, ['sort' => ['ts' => -1], 'limit' => $limit]);
        $out = [];
        foreach ($cursor as $doc) {
            $out[] = [
                'id'    => (string) $doc['_id'],
                'type'  => $doc['type'],
                'route' => $doc['route'],
                'msg'   => $doc['msg'],
            ];
        }
        return $out;
    }

    public static function findOneById(string $id): ?array
    {
        $doc = self::events()->findOne(['_id' => new ObjectId($id)]);
        return $doc === null ? null : ['id' => (string) $doc['_id'], 'type' => $doc['type'], 'msg' => $doc['msg']];
    }

    /** Event tagging — updateOne (by id) / updateMany (by route). */
    public static function tag(string $route, string $tag): array
    {
        $r = self::events()->updateMany(['route' => $route], ['$set' => ['tag' => $tag]]);
        return ['matched' => $r->getMatchedCount(), 'modified' => $r->getModifiedCount()];
    }

    /** Prune — deleteMany older than $seconds (the TTL index does this too). */
    public static function prune(int $seconds): array
    {
        $r = self::events()->deleteMany(['ts' => ['$lt' => new UTCDateTime((time() - $seconds) * 1000)]]);
        return ['deleted' => $r->getDeletedCount()];
    }

    /** The board rollup — $match/$group/$sort aggregation per route. */
    public static function rollup(int $sinceSeconds = 3600): array
    {
        $cursor = self::events()->aggregate([
            ['$match' => ['ts' => ['$gte' => new UTCDateTime((time() - $sinceSeconds) * 1000)]]],
            ['$group' => ['_id' => '$route', 'n' => ['$sum' => 1], 'types' => ['$addToSet' => '$type']]],
            ['$sort'  => ['n' => -1]],
        ]);
        $out = [];
        foreach ($cursor as $row) {
            $out[$row['_id']] = ['n' => $row['n'], 'types' => $row['types']];
        }
        return $out;
    }

    /** Hand-computed control for B8 — same answer WITHOUT the pipeline. */
    public static function rollupControl(int $sinceSeconds = 3600): array
    {
        $cursor = self::events()->find(['ts' => ['$gte' => new UTCDateTime((time() - $sinceSeconds) * 1000)]]);
        $out = [];
        foreach ($cursor as $doc) {
            $out[$doc['route']]['n'] = ($out[$doc['route']]['n'] ?? 0) + 1;
        }
        return $out;
    }

    public static function distinctTypes(): array
    {
        return self::events()->distinct('type');
    }

    /** Mixed upsert batch from the aggregation tick — ONE bulkWrite. */
    public static function bulkUpsertRollups(array $rollup): array
    {
        if ($rollup === []) {
            return ['skipped' => 'empty rollup'];
        }
        $ops = [];
        foreach ($rollup as $route => $agg) {
            $ops[] = ['updateOne' => [
                ['_id' => 'rollup:' . $route],
                ['$set' => ['n' => $agg['n'], 'at' => new UTCDateTime()]],
                ['upsert' => true],
            ]];
        }
        $r = Mongo::db()->selectCollection('rollups')->bulkWrite($ops);
        return ['upserted' => $r->getUpsertedCount(), 'modified' => $r->getModifiedCount()];
    }

    /** Claim-next-report-job pattern — findOneAndUpdate. */
    public static function claimJob(): ?array
    {
        $doc = Mongo::db()->selectCollection('jobs')->findOneAndUpdate(
            ['state' => 'queued'],
            ['$set' => ['state' => 'claimed', 'claimed_at' => new UTCDateTime(), 'worker' => getmypid()]],
            ['sort' => ['_id' => 1], 'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
        );
        return $doc === null ? null : ['id' => (string) $doc['_id'], 'state' => $doc['state'], 'worker' => $doc['worker']];
    }

    public static function counts(): array
    {
        return [
            'countDocuments'         => self::events()->countDocuments(),
            'estimatedDocumentCount' => self::events()->estimatedDocumentCount(),
        ];
    }

    /**
     * B10 — multi-doc transaction (replica set required): incident-merge
     * writes two collections atomically; $abort proves rollback.
     */
    public static function transactionProbe(bool $abort = false): array
    {
        if (!Mongo::isReplicaSet()) {
            return ['skip' => 'standalone mongod — transactions need a replica set'];
        }
        $session = Mongo::client()->startSession();
        $a = Mongo::db()->selectCollection('txn_a');
        $b = Mongo::db()->selectCollection('txn_b');
        $mark = (string) new ObjectId();
        $session->startTransaction();
        try {
            $a->insertOne(['mark' => $mark], ['session' => $session]);
            $b->insertOne(['mark' => $mark], ['session' => $session]);
            if ($abort) {
                $session->abortTransaction();
            } else {
                $session->commitTransaction();
            }
        } catch (Throwable $e) {
            $session->abortTransaction();
            return ['error' => $e->getMessage()];
        } finally {
            $session->endSession();
        }
        return [
            'mark'    => $mark,
            'aborted' => $abort,
            'a_rows'  => $a->countDocuments(['mark' => $mark]),
            'b_rows'  => $b->countDocuments(['mark' => $mark]),
        ];
    }

    /** B10 — change stream poke: watch, write, read ONE change, return token. */
    public static function watchProbe(): array
    {
        if (!Mongo::isReplicaSet()) {
            return ['skip' => 'standalone mongod — change streams need a replica set'];
        }
        $coll = Mongo::db()->selectCollection('watch_probe');
        $stream = $coll->watch([], ['fullDocument' => 'updateLookup', 'maxAwaitTimeMS' => 1500]);
        $coll->insertOne(['poke' => true, 'ts' => new UTCDateTime()]);
        $stream->rewind();
        for ($i = 0; $i < 20 && !$stream->valid(); $i++) {
            $stream->next();
        }
        if (!$stream->valid()) {
            return ['ok' => false, 'reason' => 'no change observed within window'];
        }
        $event = $stream->current();
        return [
            'ok'           => true,
            'op'           => $event['operationType'] ?? '?',
            'resume_token' => (string) json_encode($stream->getResumeToken()),
        ];
    }

    /**
     * B9 — the async honesty probe. The spec's eventfd non-blocking proof
     * requires the Rust async driver (zealphp/mongodb AsyncBridge), which is
     * NOT installed here: this app runs mongodb/mongodb over ext-mongodb
     * (libmongoc), whose sockets are C-level and NOT hooked by OpenSwoole.
     * So a real Mongo query BLOCKS the worker — the non-blocking claim cannot
     * be demonstrated and is recorded as SKIP, with the measured serialized
     * timing as evidence rather than a false "concurrent" result.
     */
    public static function asyncProof(): array
    {
        $t0 = microtime(true);
        // Two real Mongo ops, back to back, timed. Under a true async driver a
        // concurrent harness would overlap them; under libmongoc they serialize.
        Mongo::db()->command(['ping' => 1]);
        $tFastStart = microtime(true);
        self::events()->findOne([]);
        $fast = microtime(true) - $tFastStart;
        $tAggStart = microtime(true);
        self::events()->aggregate([
            ['$group' => ['_id' => '$route', 'n' => ['$sum' => 1]]],
        ])->toArray();
        $agg = microtime(true) - $tAggStart;
        return [
            'skip'         => 'eventfd non-blocking proof requires the Rust async driver (zealphp/mongodb), not installed',
            'driver'       => 'mongodb/mongodb 2.3 + ext-mongodb (libmongoc — C sockets, NOT coroutine-hooked → BLOCKING)',
            'findOne_s'    => round($fast, 4),
            'aggregate_s'  => round($agg, 4),
            'total_s'      => round(microtime(true) - $t0, 4),
            'note'         => 'Ops are serialized (blocking). A real concurrency proof would need AsyncBridge::isCoroutineMode() true.',
        ];
    }
}

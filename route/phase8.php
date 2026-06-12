<?php

declare(strict_types=1);

/**
 * Phase 8 — Store / Counter / Cache / SQL / MongoDB / Messaging (the data spine).
 *
 * Thin handlers only — logic lives in ZealPulse\{Metrics,Sql,Mongo,Firehose,Vault,Alerts}.
 * Batches B1–B15 are validated live against these endpoints (PROJECT.md §4 P8).
 */

use ZealPHP\App;
use ZealPulse\Alerts;
use ZealPulse\Firehose;
use ZealPulse\Metrics;
use ZealPulse\Mongo;
use ZealPulse\Sql;
use ZealPulse\Vault;

$app = App::instance();

// ── Store / Counter / Cache (B1–B5) ─────────────────────────────────────────
$app->route('/pulse/board', fn () => Metrics::cachedBoard());
$app->route('/pulse/board-raw', fn () => Metrics::board());
$app->route('/pulse/hit/{route}', methods: ['POST'], handler: function (string $route) {
    Metrics::recordHit($route, (float) rand(1, 50), rand(0, 9) === 0);
    Mongo::available() && Firehose::emit('hit', $route, 'probe hit');
    return ['ok' => true, 'worker' => getmypid()];
});
$app->route('/pulse/ring', fn ($request) => Metrics::ringPage(
    (string) ($request->get['cursor'] ?? '0'),
    (int) ($request->get['count'] ?? 25),
));
$app->route('/pulse/viewers/{route}', methods: ['POST'], handler: fn (string $route, $request) =>
    (($request->get['op'] ?? 'join') === 'leave')
        ? Metrics::viewerLeave('/' . $route, (string) ($request->get['who'] ?? 'anon'))
        : Metrics::viewerJoin('/' . $route, (string) ($request->get['who'] ?? 'anon')));
$app->route('/pulse/flip/{flag}', methods: ['POST'], handler: fn (string $flag, $request) =>
    Metrics::flipFlag($flag, (string) ($request->get['expect'] ?? 'off'), (string) ($request->get['new'] ?? 'on')));
$app->route('/pulse/invalidate', methods: ['POST'], handler: fn () => ['evicted' => Metrics::invalidateBoard()]);
$app->route('/pulse/store-backend', fn () => Metrics::backendInfo());
$app->route('/pulse/cache-stats', fn () => \ZealPHP\Cache::stats());

// ── SQL system of record (B6) ────────────────────────────────────────────────
$app->route('/api/incidents', methods: ['GET'], handler: fn () =>
    Sql::available() ? Sql::listIncidents() : ['skip' => 'ZP_DB_DSN unset']);
$app->route('/api/incidents', methods: ['POST'], handler: function ($request) {
    if (!Sql::available()) {
        return ['skip' => 'ZP_DB_DSN unset'];
    }
    $force = ($request->get['fail'] ?? '') === '1';
    try {
        return Sql::createIncident(
            (string) ($request->post['title'] ?? 'untitled'),
            (string) ($request->post['note'] ?? 'created'),
            $force,
        );
    } catch (Throwable $e) {
        return ['rolled_back' => true, 'error' => $e->getMessage()];
    }
});
$app->route('/pulse/sql-rows', fn () => Sql::available() ? Sql::rowCounts() : ['skip' => 'ZP_DB_DSN unset']);
$app->route('/pulse/sql-stats', fn () => Sql::stats());
$app->route('/pulse/sql-mysqli', fn () => Sql::mysqliProbe());

// ── Mongo firehose / analytics / async / txn / watch (B7–B10, B12) ──────────
$app->route('/pulse/fire', methods: ['POST'], handler: fn ($request) => Mongo::available()
    ? ['id' => Firehose::emit(
        (string) ($request->get['type'] ?? 'note'),
        (string) ($request->get['route'] ?? '/pulse/fire'),
        (string) ($request->post['msg'] ?? 'fired'),
        (float) ($request->get['ms'] ?? 1.5),
    )]
    : ['skip' => 'ZP_MONGO_URI unset']);
$app->route('/pulse/fire-batch', methods: ['POST'], handler: fn ($request) => Mongo::available()
    ? Firehose::emitBatch(json_decode((string) file_get_contents('php://input'), true) ?: [])
    : ['skip' => 'ZP_MONGO_URI unset']);
$app->route('/pulse/feed', fn ($request) => Mongo::available()
    ? Firehose::feed($request->get['q'] ?? null, (int) ($request->get['limit'] ?? 20))
    : ['skip' => 'ZP_MONGO_URI unset']);
$app->route('/pulse/event/{id}', fn (string $id) => Mongo::available()
    ? (Firehose::findOneById($id) ?? 404) : ['skip' => 'ZP_MONGO_URI unset']);
$app->route('/pulse/tag', methods: ['POST'], handler: fn ($request) => Mongo::available()
    ? Firehose::tag((string) ($request->get['route'] ?? '/'), (string) ($request->get['tag'] ?? 'tagged'))
    : ['skip' => 'ZP_MONGO_URI unset']);
$app->route('/pulse/prune', methods: ['POST'], handler: fn ($request) => Mongo::available()
    ? Firehose::prune((int) ($request->get['older'] ?? 86400)) : ['skip' => 'ZP_MONGO_URI unset']);
$app->route('/pulse/rollup', fn () => Mongo::available()
    ? ['pipeline' => Firehose::rollup(), 'control' => Firehose::rollupControl()]
    : ['skip' => 'ZP_MONGO_URI unset']);
$app->route('/pulse/distinct', fn () => Mongo::available()
    ? ['types' => Firehose::distinctTypes()] : ['skip' => 'ZP_MONGO_URI unset']);
$app->route('/pulse/bulk-rollup', methods: ['POST'], handler: fn () => Mongo::available()
    ? Firehose::bulkUpsertRollups(Firehose::rollup()) : ['skip' => 'ZP_MONGO_URI unset']);
$app->route('/pulse/claim-job', methods: ['POST'], handler: fn () => Mongo::available()
    ? (Firehose::claimJob() ?? ['empty' => true]) : ['skip' => 'ZP_MONGO_URI unset']);
$app->route('/pulse/mongo-counts', fn () => Mongo::available()
    ? Firehose::counts() : ['skip' => 'ZP_MONGO_URI unset']);
$app->route('/pulse/mongo-ping', fn () => Mongo::available()
    ? Mongo::ping() + ['indexes' => Mongo::ensureIndexes(), 'replica_set' => Mongo::isReplicaSet()]
    : ['skip' => 'ZP_MONGO_URI unset']);
$app->route('/pulse/mongo-txn', methods: ['POST'], handler: fn ($request) => Mongo::available()
    ? Firehose::transactionProbe(($request->get['abort'] ?? '') === '1')
    : ['skip' => 'ZP_MONGO_URI unset']);
$app->route('/pulse/watch-probe', methods: ['POST'], handler: fn () => Mongo::available()
    ? Firehose::watchProbe() : ['skip' => 'ZP_MONGO_URI unset']);
$app->route('/pulse/mongo-async-proof', fn () => Mongo::available()
    ? Firehose::asyncProof() : ['skip' => 'ZP_MONGO_URI unset']);

// ── GridFS vault (B11) ───────────────────────────────────────────────────────
$app->route('/pulse/vault', fn () => Mongo::available() ? Vault::listAll() : ['skip' => 'ZP_MONGO_URI unset']);
$app->route('/pulse/vault/{name}', methods: ['POST'], handler: fn (string $name, $request) => Mongo::available()
    ? Vault::put($name, (string) file_get_contents('php://input')) : ['skip' => 'ZP_MONGO_URI unset']);
$app->route('/pulse/vault/{name}', methods: ['GET'], handler: function (string $name) {
    if (!Mongo::available()) {
        return ['skip' => 'ZP_MONGO_URI unset'];
    }
    $bytes = Vault::get($name);
    return $bytes ?? 404;
});
$app->route('/pulse/vault-revisions/{name}', fn (string $name) => Mongo::available()
    ? Vault::revisions($name) : ['skip' => 'ZP_MONGO_URI unset']);
$app->route('/pulse/vault-rename/{id}', methods: ['POST'], handler: fn (string $id, $request) => Mongo::available()
    ? ['ok' => Vault::rename($id, (string) ($request->get['to'] ?? 'renamed.bin'))] : ['skip' => 'ZP_MONGO_URI unset']);
$app->route('/pulse/vault-delete/{id}', methods: ['POST'], handler: fn (string $id) => Mongo::available()
    ? ['ok' => Vault::delete($id)] : ['skip' => 'ZP_MONGO_URI unset']);

// ── Messaging (B13–B14) ──────────────────────────────────────────────────────
$app->route('/pulse/alert', methods: ['POST'], handler: fn ($request) =>
    Alerts::publish((string) ($request->get['kind'] ?? 'warn'), (string) ($request->post['msg'] ?? 'alert!')));
$app->route('/pulse/alerts-log', fn () => Alerts::deliveryLog());
$app->route('/pulse/alerts-audit', fn () => Alerts::auditLog());

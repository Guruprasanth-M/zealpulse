<?php

declare(strict_types=1);

namespace ZealPulse;

use PDO;
use Throwable;
use ZealPHP\Db\DbConnectionPool;
use ZealPHP\Db\DbException;

/**
 * Phase 8 — the SQL system of record (§1.1 dual-database contract).
 *
 * Fixed-schema facts live here: users / incidents / alert_rules /
 * probe_targets / reports. Every multi-row write goes through ONE
 * transaction(). Pool is per-worker (built in onWorkerStart); when
 * ZP_DB_DSN is unset the layer degrades gracefully (available() false,
 * every endpoint answers 503-with-reason instead of fataling).
 */
final class Sql
{
    private static ?DbConnectionPool $pool = null;
    private static bool $migrated = false;

    public static function available(): bool
    {
        return getenv('ZP_DB_DSN') !== false && self::$pool !== null;
    }

    /** Build the per-worker pool. Call from App::onWorkerStart. */
    public static function init(int $size = 4): void
    {
        $dsn = getenv('ZP_DB_DSN');
        if ($dsn === false || trim($dsn) === '') {
            return;
        }
        self::$pool = DbConnectionPool::pdo(
            $dsn,
            getenv('ZP_DB_USER') ?: null,
            getenv('ZP_DB_PASS') ?: null,
            [],
            $size,
            'SELECT 1',
        );
    }

    public static function pool(): DbConnectionPool
    {
        if (self::$pool === null) {
            throw new DbException('SQL layer unavailable (ZP_DB_DSN unset or pool not built)');
        }
        return self::$pool;
    }

    /** Idempotent schema migrate — run once (worker 0). */
    public static function migrate(): void
    {
        if (self::$migrated || self::$pool === null) {
            return;
        }
        self::pool()->with(function (PDO $db) {
            $db->exec('CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(64) NOT NULL UNIQUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
            $db->exec('CREATE TABLE IF NOT EXISTS incidents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(190) NOT NULL,
                status ENUM("open","ack","closed") NOT NULL DEFAULT "open",
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
            $db->exec('CREATE TABLE IF NOT EXISTS incident_timeline (
                id INT AUTO_INCREMENT PRIMARY KEY,
                incident_id INT NOT NULL,
                note VARCHAR(500) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (incident_id) REFERENCES incidents(id))');
            $db->exec('CREATE TABLE IF NOT EXISTS alert_rules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                metric VARCHAR(64) NOT NULL,
                threshold DOUBLE NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                incident_id INT NULL,
                FOREIGN KEY (incident_id) REFERENCES incidents(id))');
            $db->exec('CREATE TABLE IF NOT EXISTS probe_targets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                url VARCHAR(255) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1)');
            $db->exec('CREATE TABLE IF NOT EXISTS reports (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                gridfs_id VARCHAR(64) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
        });
        self::$migrated = true;
    }

    /**
     * Incident create = incident row + first timeline entry + rule link in
     * ONE transaction. $forceFail injects a failure AFTER the first insert
     * to prove rollback leaves zero partial rows (B6).
     */
    public static function createIncident(string $title, string $note, bool $forceFail = false): array
    {
        return self::pool()->transaction(function (PDO $db) use ($title, $note, $forceFail) {
            $db->prepare('INSERT INTO incidents (title) VALUES (?)')->execute([$title]);
            $id = (int) $db->lastInsertId();
            if ($forceFail) {
                throw new \RuntimeException('forced mid-transaction failure (rollback test)');
            }
            $db->prepare('INSERT INTO incident_timeline (incident_id, note) VALUES (?, ?)')
                ->execute([$id, $note]);
            $db->prepare('INSERT INTO alert_rules (name, metric, threshold, incident_id) VALUES (?, ?, ?, ?)')
                ->execute(['auto-' . $id, 'error_rate', 0.05, $id]);
            return ['id' => $id, 'title' => $title];
        });
    }

    public static function listIncidents(int $limit = 20): array
    {
        return self::pool()->with(function (PDO $db) use ($limit) {
            $q = $db->prepare('SELECT i.id, i.title, i.status, i.created_at,
                    (SELECT COUNT(*) FROM incident_timeline t WHERE t.incident_id = i.id) AS timeline_rows,
                    (SELECT COUNT(*) FROM alert_rules r WHERE r.incident_id = i.id) AS rules
                FROM incidents i ORDER BY i.id DESC LIMIT ' . (int) $limit);
            $q->execute();
            return $q->fetchAll();
        });
    }

    /** Row counts per table — the rollback proof reads these before/after. */
    public static function rowCounts(): array
    {
        return self::pool()->with(function (PDO $db) {
            $out = [];
            foreach (['incidents', 'incident_timeline', 'alert_rules'] as $t) {
                $out[$t] = (int) $db->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
            }
            return $out;
        });
    }

    /** Pool observability for /pulse/sql-stats + /_metrics. */
    public static function stats(): array
    {
        if (self::$pool === null) {
            return ['available' => false, 'reason' => 'ZP_DB_DSN unset or worker pool not built'];
        }
        $s = self::pool()->stats();
        return [
            'available' => true,
            'size'      => self::pool()->size(),
            'stats'     => method_exists($s, 'snapshot') ? $s->snapshot()
                : json_decode((string) json_encode($s), true),
            'worker'    => getmypid(),
        ];
    }

    /** B6 alt-driver probe: one mysqli pool round-trip, then closed. */
    public static function mysqliProbe(): array
    {
        $dsn = getenv('ZP_DB_DSN');
        if ($dsn === false || !preg_match('/host=([^;]+).*?port=(\d+).*?dbname=([^;]+)/', $dsn, $m)) {
            return ['available' => false, 'reason' => 'ZP_DB_DSN unset/unparsable'];
        }
        try {
            $pool = DbConnectionPool::mysqli(
                $m[1],
                getenv('ZP_DB_USER') ?: null,
                getenv('ZP_DB_PASS') ?: null,
                $m[3],
                (int) $m[2],
                null,
                'utf8mb4',
                2,
                'SELECT 1',
            );
            $row = $pool->with(fn (\mysqli $db) => $db->query('SELECT VERSION() AS v')->fetch_assoc());
            $pool->close();
            return ['available' => true, 'driver' => 'mysqli', 'server' => $row['v'] ?? '?'];
        } catch (Throwable $e) {
            return ['available' => false, 'error' => $e->getMessage()];
        }
    }
}

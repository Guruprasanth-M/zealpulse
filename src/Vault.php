<?php

declare(strict_types=1);

namespace ZealPulse;

use MongoDB\GridFS\Bucket;

/**
 * Phase 8 — the GridFS artifact vault (report files + uploads, revisioned).
 */
final class Vault
{
    public static function bucket(): Bucket
    {
        return Mongo::db()->selectGridFSBucket(['bucketName' => 'vault']);
    }

    /** Upload one artifact revision from a byte string. */
    public static function put(string $name, string $bytes): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $bytes);
        rewind($stream);
        $id = self::bucket()->uploadFromStream($name, $stream, ['metadata' => ['by' => getmypid()]]);
        return ['id' => (string) $id, 'name' => $name, 'bytes' => strlen($bytes)];
    }

    /** Download the LATEST revision by name (byte-identity check in B11). */
    public static function get(string $name): ?string
    {
        $out = fopen('php://temp', 'r+');
        try {
            self::bucket()->downloadToStreamByName($name, $out, ['revision' => -1]);
        } catch (\MongoDB\GridFS\Exception\FileNotFoundException) {
            return null;
        }
        rewind($out);
        return stream_get_contents($out) ?: '';
    }

    /** All revisions of a name, oldest first. */
    public static function revisions(string $name): array
    {
        $out = [];
        foreach (self::bucket()->find(['filename' => $name], ['sort' => ['uploadDate' => 1]]) as $f) {
            $out[] = ['id' => (string) $f['_id'], 'len' => (int) $f['length']];
        }
        return $out;
    }

    public static function rename(string $id, string $newName): bool
    {
        self::bucket()->rename(new \MongoDB\BSON\ObjectId($id), $newName);
        return true;
    }

    public static function delete(string $id): bool
    {
        self::bucket()->delete(new \MongoDB\BSON\ObjectId($id));
        return true;
    }

    /** Vault admin listing via find(). */
    public static function listAll(int $limit = 50): array
    {
        $out = [];
        foreach (self::bucket()->find([], ['limit' => $limit, 'sort' => ['uploadDate' => -1]]) as $f) {
            $out[] = ['id' => (string) $f['_id'], 'name' => $f['filename'], 'len' => (int) $f['length']];
        }
        return $out;
    }
}

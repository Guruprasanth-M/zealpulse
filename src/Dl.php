<?php
/**
 * ZealPulse — /assets-dl file source (Phase 5).
 *
 * Reads download fixtures from `assets/dl/` (non-docroot, so the native
 * static handler can never intercept them — every byte flows through the
 * `when('/assets-dl')` decorator chain: Range/ETag/CacheControl/Expires/
 * MimeType/ContentEncoding/ContentLanguage).
 *
 * The handler deliberately returns the RAW bytes with no Content-Type:
 * MimeTypeMiddleware resolves it from the URL extension (mod_mime parity).
 */
declare(strict_types=1);

namespace ZealPulse;

final class Dl
{
    /** Strict fixture-name allowlist: dotted segments, no traversal. */
    private const NAME_RE = '#^[A-Za-z0-9][A-Za-z0-9._-]*$#';

    public static function dir(): string
    {
        return dirname(__DIR__) . '/assets/dl';
    }

    /** Fixture bytes, or null when the name is unsafe / missing. */
    public static function read(string $name): ?string
    {
        if (preg_match(self::NAME_RE, $name) !== 1 || str_contains($name, '..')) {
            return null;
        }
        $path = realpath(self::dir() . '/' . $name);
        if ($path === false || !str_starts_with($path, self::dir() . '/') || !is_file($path)) {
            return null;
        }
        $bytes = file_get_contents($path);

        return $bytes === false ? null : $bytes;
    }
}

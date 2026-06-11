<?php
/**
 * ZealPulse — report files (Phase 3 surface).
 *
 * Resolves downloadable report assets and exposes their absolute path for
 * Response::sendFile() (which adds the weak ETag, Last-Modified, Range and the
 * full conditional-GET stack). Real metrics wiring lands in Phase 8; here the
 * sample report is a static asset under assets/.
 */
declare(strict_types=1);

namespace ZealPulse;

final class Reports
{
    /** Absolute path to a named report asset, or null if it escapes the dir / is missing. */
    public static function path(string $name): ?string
    {
        $dir  = \dirname(__DIR__) . '/assets';
        $real = \realpath($dir . '/' . \basename($name));
        if ($real === false || !\str_starts_with($real, $dir . '/') || !\is_file($real)) {
            return null;
        }
        return $real;
    }
}

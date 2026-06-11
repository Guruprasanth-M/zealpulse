<?php
/**
 * ZealPulse — HTTP response helpers (Phase 1 surface).
 *
 * Centralises the response hygiene every ZealPulse endpoint shares: a single
 * security-header preamble (B2 header family), consistent JSON/text shaping
 * (B6 charset / content-type), and a safe redirect wrapper (B5). Business
 * handlers stay thin and call these.
 */
declare(strict_types=1);

namespace ZealPulse;

use ZealPHP\App;

final class Http
{
    /**
     * Emit the baseline security headers every page/endpoint carries.
     * Exercises the B2 `header()` family: replace + same-name handling, and
     * appends a default charset (B6) since the demo app doesn't mount
     * CharsetMiddleware globally.
     */
    public static function secureHeaders(string $contentType = 'text/html; charset=utf-8'): void
    {
        header('Content-Type: ' . $contentType);                 // replace
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cache-Control: no-store');
        // Two-value same-name header — proves multi-append survives to the wire (B2/#260).
        header('X-ZealPulse: core');
        header('X-ZealPulse: phase-1', false);
    }

    /** JSON body with explicit charset (B6) — returns the array for the contract. */
    public static function json(array $data): array
    {
        // The universal return contract turns an array into application/json,
        // but we set the charset explicitly so text clients agree (B6).
        header('Content-Type: application/json; charset=utf-8');
        return $data;
    }

    /**
     * Safe redirect (B5) — delegates to Response::redirect() whose guards block
     * open-redirect / javascript: / CRLF (CWE-601). Same-origin paths only here.
     */
    public static function redirect($response, string $path, int $status = 302): void
    {
        $response->redirect($path, $status);
    }

    /** A body-forbidden 204 response (B4) — e.g. an action with no content to return. */
    public static function noContent(): int
    {
        return 204;   // universal return contract → bare 204, body stripped
    }
}

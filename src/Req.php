<?php
/**
 * ZealPulse — request-input helper (Phase 2 surface).
 *
 * Reads request input through `$g` (G::instance()), which works in EVERY
 * lifecycle mode — including bare `coroutine`, where the `$_GET/$_POST/...`
 * superglobals are intentionally NOT populated. Business handlers use this and
 * stay mode-portable; the raw `$_*` superglobals are only inspected directly by
 * the /whoami diagnostic to demonstrate the superglobal surface.
 *
 * Mind the filed divergences (PROJECT.md §3): never read `$_REQUEST` (#356 —
 * GET-wins in mixed/coroutine-legacy); guard int ports (#306).
 */
declare(strict_types=1);

namespace ZealPulse;

use ZealPHP\G;

final class Req
{
    public static function g(): G
    {
        return G::instance();
    }

    /** Query param via $g->get (portable across all modes). */
    public static function query(string $key, ?string $default = null): ?string
    {
        $v = self::g()->get[$key] ?? $default;
        return is_scalar($v) ? (string) $v : $default;
    }

    /** POST field via $g->post. */
    public static function post(string $key, ?string $default = null): ?string
    {
        $v = self::g()->post[$key] ?? $default;
        return is_scalar($v) ? (string) $v : $default;
    }

    /** Raw request body (re-readable via ZealPHP's buffered php://input). */
    public static function rawBody(): string
    {
        return (string) file_get_contents('php://input');
    }

    /** Decode a JSON body, or [] if not JSON / empty. */
    public static function json(): array
    {
        $raw = self::rawBody();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** A $_SERVER value via $g->server, coerced to string (guards #306 int ports). */
    public static function server(string $key, ?string $default = null): ?string
    {
        $v = self::g()->server[$key] ?? $default;
        return is_scalar($v) ? (string) $v : $default;
    }

    /** HTTP Basic-auth credentials, or null when absent. */
    public static function basicAuth(): ?array
    {
        $u = self::server('PHP_AUTH_USER');
        $p = self::server('PHP_AUTH_PW');
        return ($u !== null) ? ['user' => $u, 'pass' => (string) $p] : null;
    }
}

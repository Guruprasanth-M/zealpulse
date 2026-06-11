<?php
/**
 * ZealPulse — session-backed identity (Phase 4 surface).
 *
 * Login/logout with fixation-safe session rotation, per-user prefs, and flash
 * messages, on top of ZealPHP's session layer. Works in every lifecycle mode:
 * we call session_start() explicitly (so CoSessionManager's lazy coroutine path
 * mints on demand without needing SessionStartMiddleware) and read/write through
 * $_SESSION, which ZealPHP aliases to $g->session.
 *
 * The user store is the system-of-record's job (SQL, a later data-layer phase);
 * until that's wired it degrades to a small in-code credential map so the whole
 * auth flow — rotation, strict-mode, switchboard — is exercisable now.
 */
declare(strict_types=1);

namespace ZealPulse;

final class Auth
{
    /** Demo credential map (replaced by the SQL user store in the data-layer phase). */
    private const USERS = [
        'ops'   => ['pass' => 'pulse', 'role' => 'admin'],
        'viewer'=> ['pass' => 'watch', 'role' => 'viewer'],
    ];

    /** Ensure a session is active (mode-agnostic — explicit start). */
    public static function boot(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /** Attempt login. On success: rotate the id (fixation defence) + store identity. */
    public static function login(string $user, string $pass): bool
    {
        self::boot();
        $rec = self::USERS[$user] ?? null;
        if ($rec === null || !hash_equals($rec['pass'], $pass)) {
            return false;
        }
        // Fixation defence: a successful auth MUST get a fresh session id, and the
        // old id must no longer resolve to this identity (RFC: regenerate on
        // privilege change). session_regenerate_id(true) deletes the old file.
        session_regenerate_id(true);
        $_SESSION['uid']  = $user;
        $_SESSION['role'] = $rec['role'];
        $_SESSION['since'] = time();
        return true;
    }

    public static function logout(): void
    {
        self::boot();
        $_SESSION = [];
        session_destroy();
    }

    /** Current identity, or null when anonymous. */
    public static function user(): ?array
    {
        self::boot();
        if (!isset($_SESSION['uid'])) {
            return null;
        }
        return ['uid' => $_SESSION['uid'], 'role' => $_SESSION['role'] ?? 'viewer', 'since' => $_SESSION['since'] ?? null];
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? null) === 'admin';
    }

    /** Per-user prefs live in the session (theme/layout/refresh). */
    public static function pref(string $key, $default = null)
    {
        self::boot();
        return $_SESSION['prefs'][$key] ?? $default;
    }

    public static function setPref(string $key, $value): void
    {
        self::boot();
        $_SESSION['prefs'][$key] = $value;
    }

    /** One-shot flash message (consumed on read). */
    public static function flash(?string $msg = null): ?string
    {
        self::boot();
        if ($msg !== null) {
            $_SESSION['_flash'] = $msg;
            return null;
        }
        $m = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);
        return $m;
    }
}

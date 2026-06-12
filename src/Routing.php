<?php
/**
 * ZealPulse — Phase 6: routing & dispatch helpers.
 *
 * Keeps route/phase6.php thin: the custom 404/500 experience, the truthful
 * /routes introspection (App::describeRoutes), and Accept-based content
 * negotiation all live here as pure helpers (templates stay HTML-only,
 * business logic stays in src/ per the project standards).
 */
declare(strict_types=1);

namespace ZealPulse;

use ZealPHP\App;

final class Routing
{
    /**
     * Does this request prefer JSON? True when the Accept header leads with
     * application/json (or *\/* from a fetch/XHR that asked for it). Used by the
     * fallback + error handlers so the SAME endpoint serves an API client a JSON
     * body and a browser an HTML page (Apache `ErrorDocument` + negotiation parity).
     */
    public static function wantsJson($request): bool
    {
        $accept = '';
        if (is_object($request)) {
            $accept = (string) ($request->header['accept'] ?? $request->server['http_accept'] ?? '');
        }
        if ($accept === '') {
            $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        }
        return stripos($accept, 'application/json') !== false;
    }

    /**
     * The closest registered route path to a mistyped URL — a tiny
     * did-you-mean for the 404 page, computed from the live route table
     * (App::describeRoutes works before AND after run()).
     */
    public static function suggest(string $path): ?string
    {
        $best = null;
        $bestD = PHP_INT_MAX;
        foreach (self::routePaths() as $candidate) {
            $d = levenshtein($path, $candidate);
            if ($d < $bestD) {
                $bestD = $d;
                $best = $candidate;
            }
        }
        // Only suggest when it's actually close (avoid nonsense suggestions).
        return ($best !== null && $bestD <= max(3, (int) (strlen($path) / 2))) ? $best : null;
    }

    /** Flat list of every registered route path/pattern (for suggest + /routes). */
    public static function routePaths(): array
    {
        $paths = [];
        foreach (App::instance()->describeRoutes()['routes'] ?? [] as $r) {
            $paths[] = (string) ($r['path'] ?? '');
        }
        return array_values(array_filter(array_unique($paths)));
    }

    /**
     * The branded 404 body. JSON for API clients, an HTML page (with a
     * did-you-mean) for browsers. Returned by the setFallback() handler.
     */
    public static function notFound($request, string $path)
    {
        $suggestion = self::suggest($path);
        if (self::wantsJson($request)) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(404);     // the fallback dispatch defaults to 200 — set it
            $body = ['error' => 'not_found', 'status' => 404, 'path' => $path];
            if ($suggestion !== null) {
                $body['did_you_mean'] = $suggestion;
            }
            return $body;
        }
        $safePath = htmlspecialchars($path, ENT_QUOTES);
        $hint = $suggestion !== null
            ? '<p>Did you mean <a href="' . htmlspecialchars($suggestion, ENT_QUOTES) . '">'
                . htmlspecialchars($suggestion, ENT_QUOTES) . '</a>?</p>'
            : '';
        Http::secureHeaders();
        http_response_code(404);
        return <<<HTML
            <!doctype html><meta charset=utf-8><title>404 — ZealPulse</title>
            <link rel="stylesheet" href="/css/app.css">
            <h1>404 — no route here</h1>
            <p><code>$safePath</code> didn't match any registered route.</p>
            $hint
            <p><a href="/p6/routes">Browse the route map →</a></p>
            HTML;
    }

    /**
     * The branded error page for a status-specific or catch-all error handler.
     * Negotiates JSON vs HTML; the trace is shown ONLY when App::displayErrors()
     * is on (ZealPulse turns it OFF in app.php — secure by default, cf. the
     * framework default which leaks traces, issue #412).
     */
    public static function errorPage(int $status, ?\Throwable $e, $request)
    {
        $reason = self::reason($status);
        $showTrace = App::displayErrors() && $e !== null;
        if (self::wantsJson($request)) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($status);     // carry the real status on the error-handler path
            $body = ['error' => ['status' => $status, 'message' => $reason]];
            if ($showTrace) {
                $body['error']['detail'] = $e->getMessage();
            }
            return $body;
        }
        Http::secureHeaders();
        http_response_code($status);
        $detail = $showTrace
            ? '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</pre>'
            : '<p>The error was logged. Our team has been notified.</p>';
        return <<<HTML
            <!doctype html><meta charset=utf-8><title>$status — ZealPulse</title>
            <link rel="stylesheet" href="/css/app.css">
            <h1>$status — $reason</h1>
            $detail
            <p><a href="/p6">← back to the routing lab</a></p>
            HTML;
    }

    /** The full route map as a structured array (for the /routes JSON + HTML). */
    public static function routeMap(): array
    {
        $desc = App::instance()->describeRoutes();
        $rows = [];
        foreach ($desc['routes'] ?? [] as $r) {
            $rows[] = [
                'methods'    => $r['methods'] ?? ['GET'],
                'path'       => $r['path'] ?? '',
                'middleware' => $r['middleware'] ?? [],
                'backend'    => $r['backend'] ?? null,
            ];
        }
        return [
            'count'    => count($rows),
            'globals'  => $desc['global'] ?? [],
            'aliases'  => $desc['aliases'] ?? [],
            'when'     => $desc['when'] ?? [],
            'routes'   => $rows,
        ];
    }

    private static function reason(int $status): string
    {
        return [
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
            404 => 'Not Found', 405 => 'Method Not Allowed', 500 => 'Internal Server Error',
            503 => 'Service Unavailable',
        ][$status] ?? 'Error';
    }
}

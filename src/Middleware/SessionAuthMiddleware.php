<?php
/**
 * ZealPulse — session-auth gate (Phase 5 admin band).
 *
 * The app-level auth middleware over ZealPHP's `App::authChecker()` /
 * `App::adminChecker()` hooks: app.php registers the checkers (backed by
 * ZealPulse\Auth's session identity, Phase 4), and this middleware enforces
 * them on route groups — one source of truth for "is this request signed in"
 * shared between the ZealAPI layer (`$this->isAuthenticated()`) and the
 * middleware band.
 *
 * Short-circuits with a JSON 401 (no identity) / 403 (identity but not admin
 * when `requireAdmin`) without calling the handler — the B4 short-circuit
 * shape. Stateless: identity is read per-request from the session via the
 * checker callables; nothing request-scoped lives on the instance.
 */
declare(strict_types=1);

namespace ZealPulse\Middleware;

use OpenSwoole\Core\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPulse\Auth;

final class SessionAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private bool $requireAdmin = false)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $checker = App::authChecker();
        $authed = $checker !== null ? (bool) $checker() : (Auth::user() !== null);
        if (!$authed) {
            return $this->reject(401, 'auth_required');
        }

        if ($this->requireAdmin) {
            $adminChecker = App::adminChecker();
            $admin = $adminChecker !== null ? (bool) $adminChecker() : Auth::isAdmin();
            if (!$admin) {
                return $this->reject(403, 'admin_only');
            }
        }

        return $handler->handle($request);
    }

    private function reject(int $status, string $error): ResponseInterface
    {
        $body = (string) json_encode(['ok' => false, 'error' => $error]);

        return new Response($body, $status, '', ['Content-Type' => 'application/json']);
    }
}

<?php
/**
 * ZealPulse — onion-order trace middleware (Phase 5 mechanics probe).
 *
 * Each instance appends its tag to the per-request `$g->memo['zp_trace']`
 * list on the way IN, so the innermost handler can return the exact order in
 * which the middleware onion was entered (global → when → group → route).
 * On the way OUT it stamps the full trace on an `X-ZP-Trace` response header
 * so the composition is visible on the wire.
 *
 * Registered as the parameterized alias `trace` (`'trace:admin-group'`,
 * `'trace:panel-route'`, …) — one factory, many tagged instances, each
 * resolved ONCE at App::run().
 *
 * Stateless by contract: the only per-request state lives in `$g`
 * (RequestContext); the instance carries nothing but its constructor tag.
 */
declare(strict_types=1);

namespace ZealPulse\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\RequestContext;

final class TraceMiddleware implements MiddlewareInterface
{
    public function __construct(private string $tag)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $g = RequestContext::instance();
        $trace = $g->memo['zp_trace'] ?? [];
        $trace[] = $this->tag;
        $g->memo['zp_trace'] = $trace;

        $response = $handler->handle($request);

        // Unwind: every Trace layer rewrites the header with the (complete)
        // entry-order list — the outermost write wins, all writes identical.
        $resp = $g->zealphp_response;
        if ($resp !== null) {
            $resp->header('X-ZP-Trace', implode(' > ', $g->memo['zp_trace'] ?? []));
        }

        return $response;
    }
}

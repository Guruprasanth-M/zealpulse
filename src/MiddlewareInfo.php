<?php
/**
 * ZealPulse — middleware introspection service (Phase 5 `/middleware` page).
 *
 * Thin shaping layer over `App::describeRoutes()` (`{global, aliases, when,
 * routes}`) plus the deliberate-omission record (CompressionMiddleware) so
 * the template stays logic-free.
 */
declare(strict_types=1);

namespace ZealPulse;

use ZealPHP\App;

final class MiddlewareInfo
{
    /**
     * The full middleware topology as rendered by the /middleware page.
     *
     * @return array{global: list<string>, aliases: list<string>,
     *               when: list<array{scope: string, middleware: list<string>}>,
     *               routes: list<array<string, mixed>>}
     */
    public static function describe(): array
    {
        return App::instance()->describeRoutes();
    }

    /**
     * Built-ins deliberately NOT mounted, with the reason on record —
     * a silent gap is never acceptable (PROJECT.md §5 rule 4).
     *
     * @return list<array{name: string, reason: string}>
     */
    public static function notMounted(): array
    {
        return [
            [
                'name'   => 'CompressionMiddleware',
                'reason' => 'OpenSwoole serves with server-level http_compression already on; '
                    . 'mounting app-level gzip too would double-compress responses. Documented, not mounted.',
            ],
        ];
    }
}

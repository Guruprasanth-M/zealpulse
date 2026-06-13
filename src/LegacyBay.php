<?php

declare(strict_types=1);

namespace ZealPulse;

use ZealPHP\App;

/**
 * Phase 10 — CGI dispatch & the legacy bay.
 *
 * Thin service behind route/phase10.php. The four CGI strategies
 * (pool/proc/fork/fcgi) only diverge under legacy-cgi (subprocess isolation);
 * in coroutine/mixed modes App::include() runs the legacy script in-process.
 * This class reports the active strategy and runs the unmodified legacy scripts
 * through the universal return contract.
 */
final class LegacyBay
{
    /** The strategy switchboard — what's active + how to sweep the rest. */
    public static function switchboard(): array
    {
        $iso = App::isolation();             // current isolation coupling (string getter)
        $cgi = App::cgiMode();               // pool|proc|fork|fcgi
        $isolation = App::processIsolation();// bool getter
        return [
            'isolation'        => $iso,
            'cgi_strategy'     => $cgi,
            'enable_coroutine' => App::enableCoroutine(),
            'process_isolation'=> $isolation,
            'dispatch'         => $isolation
                ? "legacy-cgi subprocess via '{$cgi}'"
                : 'in-process App::include (coroutine/mixed)',
            'strategies'       => ['pool', 'proc', 'fork', 'fcgi'],
            'pool' => [
                'size'         => App::cgiPoolSize(),
                'max_requests' => App::cgiPoolMaxRequests(),
                'env_allowlist'=> App::cgiPoolEnvAllowlist(),
            ],
            'legacy_bay' => [
                'guestbook' => '/phase10/guestbook  (stock mod_php, App::include)',
                'contract'  => '/phase10/contract?kind=json|status|html  (return-over-boundary)',
                'shell_cgi' => '/cgi-bin/status.sh   (ScriptAlias, RFC 3875)',
                'envcheck'  => '/phase10/envcheck    (httpoxy + allowlist proof)',
            ],
            'note' => 'Sweep all four with ZEAL_MODE=legacy-cgi ZEAL_CGI_MODE=pool|proc|fork; '
                    . 'fcgi needs an upstream php-fpm at ZEAL_FCGI_ADDR (down ⇒ 502, never a hang — upstream #289 fixed).',
        ];
    }

    /** Run the unmodified stock guestbook through App::include() (B1/B5). */
    public static function guestbook(): mixed
    {
        return App::include('/legacy/guestbook.php');
    }

    /** Return-contract probe across the boundary (B2). */
    public static function contract(): mixed
    {
        return App::include('/legacy/contract.php');
    }
}

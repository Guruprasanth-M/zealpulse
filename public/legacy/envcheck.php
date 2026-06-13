<?php
/**
 * Env-isolation proof for the CGI boundary (Phase-10 B4). A request `Proxy:`
 * header must NOT reach the subprocess env (httpoxy / CVE-2016-5385), and the
 * app's secrets must only reach it through cgiPoolEnvAllowlist(). Reports what
 * the subprocess actually sees via getenv().
 */
header('Content-Type: application/json');
echo json_encode([
    'pid'                   => getmypid(),
    'getenv_HTTP_PROXY'     => getenv('HTTP_PROXY'),         // expect false (httpoxy dropped)
    'server_HTTP_PROXY'     => $_SERVER['HTTP_PROXY'] ?? null,// request var is fine
    'getenv_ZEALPULSE_TAG'  => getenv('ZEALPULSE_TAG'),      // allowlisted ⇒ visible
    'getenv_SECRET_TOKEN'   => getenv('SECRET_TOKEN'),       // not allowlisted ⇒ false under hardening
], JSON_UNESCAPED_SLASHES) . "\n";

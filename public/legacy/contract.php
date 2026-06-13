<?php
/**
 * Universal-return-contract probe across the CGI process boundary (Phase-10 B2).
 * App::include() of this file honours the RETURN value — int→status, array→JSON,
 * string→HTML — identically in-process and over the subprocess IPC frame.
 *
 *   ?kind=json   → return array  → application/json
 *   ?kind=status → return 418    → HTTP 418 (no body)
 *   ?kind=html   → return string → text/html
 *   (default)    → echo + return → body.concat(return) per the contract
 */
$kind = $_GET['kind'] ?? 'json';
switch ($kind) {
    case 'status': return 418;
    case 'html':   return "<b>contract: html string</b> (pid " . getmypid() . ")";
    case 'json':   return ['ok' => true, 'kind' => 'json', 'pid' => getmypid(),
                           'gateway' => $_SERVER['GATEWAY_INTERFACE'] ?? 'in-process'];
    default:
        echo "echo-shell ";
        return "then-return (pid " . getmypid() . ")";
}

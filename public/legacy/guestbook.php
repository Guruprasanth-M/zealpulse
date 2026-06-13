<?php
/**
 * The legacy bay — a stock mod_php-era guestbook. ZERO framework code: only
 * superglobals ($_GET/$_POST/$_SERVER/$_SESSION) + echo, exactly as it would
 * have run under Apache + mod_php in 2009. ZealPulse runs it UNMODIFIED via
 * App::include('/legacy/guestbook.php') — in-process in coroutine modes, in a
 * CGI subprocess under legacy-cgi (pool/proc/fork). The universal return
 * contract turns this echo'd HTML into the response body.
 *
 * Storage is a flat file in the system temp dir (the mod_php way — no DB, no
 * service class). Concurrency-safe via flock.
 */

// A stock guestbook starts its own session (the #108 CGI handoff mints/threads
// the PHPSESSID so this session_start() in the subprocess sees the same id).
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

$store = sys_get_temp_dir() . '/zealpulse-guestbook.txt';

// POST → append an entry (classic superglobal form handling).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $name = trim((string) ($_POST['name'] ?? 'anon'));
    $msg  = trim((string) ($_POST['msg'] ?? ''));
    if ($msg !== '') {
        $line = date('c') . "\t" . str_replace(["\t", "\n"], ' ', $name)
              . "\t" . str_replace(["\t", "\n"], ' ', $msg) . "\n";
        $fp = fopen($store, 'a');
        if ($fp) { flock($fp, LOCK_EX); fwrite($fp, $line); flock($fp, LOCK_UN); fclose($fp); }
    }
}

// A visit counter in the session proves session continuity across the CGI
// boundary (Phase-10 B5): the login/visit session is readable inside the script.
$_SESSION['gb_visits'] = (int) ($_SESSION['gb_visits'] ?? 0) + 1;

$entries = is_file($store) ? array_filter(explode("\n", (string) file_get_contents($store))) : [];
$entries = array_slice(array_reverse($entries), 0, 20);

header('Content-Type: text/html; charset=utf-8');
echo "<!doctype html><meta charset=utf-8><title>Legacy Guestbook</title>";
echo "<h1>Legacy Guestbook</h1>";
echo "<p>Served by <b>" . htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'unknown')
   . "</b> · gateway <code>" . htmlspecialchars($_SERVER['GATEWAY_INTERFACE'] ?? 'in-process')
   . "</code> · pid <code>" . getmypid() . "</code> · your visit #"
   . (int) $_SESSION['gb_visits'] . "</p>";
echo "<form method=post action=''><input name=name placeholder=name> "
   . "<input name=msg placeholder=message size=40> <button>sign</button></form>";
echo "<ul>";
foreach ($entries as $e) {
    [$ts, $nm, $m] = array_pad(explode("\t", $e, 3), 3, '');
    echo "<li><time>" . htmlspecialchars($ts) . "</time> — <b>"
       . htmlspecialchars($nm) . "</b>: " . htmlspecialchars($m) . "</li>";
}
echo "</ul>";

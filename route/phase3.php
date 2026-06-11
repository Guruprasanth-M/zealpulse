<?php
/**
 * ZealPulse — Phase 3: static files & conditional GET (batches B1–B7).
 *
 * Features:
 *   /download/{name}  → Response::sendFile() of a report asset, with the full
 *                       conditional-GET + Range + weak-ETag stack (B1/B2/B3).
 *   public/css, /js, /robots.txt, /favicon.ico → native OpenSwoole static
 *                       handler (Last-Modified only, no ETag/Range) (B4).
 *
 * Known Phase-3 divergences respected (PROJECT.md §3, filed in the Phase-3
 * sweep): HEAD on sendFile leaks the body; .well-known is unservable;
 * Content-Disposition lacks the RFC 6266 filename* ext-value. ZealPulse uses
 * ASCII download names so the disposition bug doesn't bite.
 */
declare(strict_types=1);

use ZealPHP\App;
use ZealPulse\Http;
use ZealPulse\Reports;

$app = App::instance();

// ── B1/B2/B3 — report download via sendFile (ETag/Last-Modified/Range/304) ───
$app->route('/download/{name}', function ($name, $response) {
    $path = Reports::path((string) $name);
    if ($path === null) {
        http_response_code(404);
        return Http::json(['error' => 'report_not_found']);
    }
    // ASCII filename (avoids the RFC-6266 non-ASCII disposition gap).
    $response->sendFile($path, 'zealpulse-' . basename($path));
});

// ── B5 — directory/normalization: a reports index (real dashboard sub-page) ──
$app->route('/reports', function () {
    Http::secureHeaders();
    return <<<HTML
        <!doctype html><meta charset=utf-8><title>Reports — ZealPulse</title>
        <link rel="stylesheet" href="/css/app.css">
        <h1>Reports</h1>
        <ul><li><a href="/download/metrics-sample.csv">metrics-sample.csv</a>
        (sendFile: weak ETag, Range, conditional GET)</li></ul>
        HTML;
});

// Note: static assets (css/js/robots.txt/favicon.ico) are served by the native
// OpenSwoole static handler before PHP routing (B4) — no route needed.

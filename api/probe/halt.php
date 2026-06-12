<?php
/**
 * ZealPulse file-API (ZealAPI) — /api/probe/halt  (Phase 6 · B6).
 *
 * Demonstrates HaltException as a CLEAN halt: the handler echoes a buffered
 * body, sets the status, then throws HaltException to short-circuit the rest of
 * the handler — ZealAPI catches it (ZealAPI::runHandlerWithContract) and emits
 * the already-buffered body with $g->status. The worker survives (no 500).
 *
 * NOTE (framework version): on the released v0.4.8 the clean-halt catch lives in
 * the ZealAPI + template/include paths (here), so this is the release-portable
 * place to use it. A bare HaltException thrown from a plain route() closure is
 * handled as a clean halt only from the post-0.4.8 line (fixed on main; on
 * v0.4.8 it surfaces as 500) — see /p6/halt for the portable route-level halt.
 */
declare(strict_types=1);

$get = function () {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['kind' => 'HaltException', 'note' => 'buffered before the halt', 'halted' => true]);
    \ZealPHP\G::instance()->status = 200;
    throw new \ZealPHP\HaltException('clean halt — body preserved, worker survives');
};

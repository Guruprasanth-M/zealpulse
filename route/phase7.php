<?php
/**
 * ZealPulse — Phase 7: streaming / SSE / WebSocket / rooms (batches B1–B10).
 *
 *   B1  WS /live           — event feed broadcast to every viewer (+ replay-on-connect)
 *   B2  WS /incident?room= — incident rooms: presence, member list, capacity (→ 4013)
 *   B5  SSE /stream/metrics — board ticks (named events + heartbeat)
 *   B6  stream /stream/logs — live log tail that STOPS when the browser disconnects
 *   B7  generator /p7/board — SSR initial board, yielded progressively
 *       /realtime           — the HTML console that drives all of the above
 *
 * WSRouter (the production room API) is unusable on v0.4.8 — WSRouter::init()
 * crashes every worker at boot (upstream #415) — so rooms are built on plain
 * App::ws() + shared Store tables via ZealPulse\EventBus (cross-worker fan-out).
 *
 * Handlers stay thin; the fan-out / membership / ring logic lives in src/EventBus.php.
 */
declare(strict_types=1);

use ZealPHP\App;
use ZealPulse\EventBus;
use ZealPulse\Http;

$app = App::instance();

// ── B1 — WS live event feed: broadcast to every connected viewer ─────────────
$app->ws('/live',
    onOpen: function ($server, $request, $g) {
        $online = EventBus::subscribeLive($server, $request->fd);
        // Replay the recent ring so a fresh tab isn't blank, then a welcome.
        foreach (EventBus::recentEvents() as $e) {
            $server->push($request->fd, json_encode(['type' => 'replay'] + $e));
        }
        $server->push($request->fd, json_encode(['type' => 'welcome', 'online' => $online, 'ts' => time()]));
    },
    onMessage: function ($server, $frame, $g) {
        // A viewer-posted note fans out to everyone (incl. the sender).
        $text = trim((string) $frame->data);
        if ($text !== '') {
            EventBus::publishLive($server, ['type' => 'note', 'msg' => substr($text, 0, 200)]);
        }
    },
    onClose: function ($server, $fd, $g) {
        EventBus::unsubscribe($server, $fd);
    }
);

// ── B2 — WS incident rooms: join via ?room=&name=, presence + capacity ───────
$app->ws('/incident',
    onOpen: function ($server, $request, $g) {
        $room = substr((string) ($request->get['room'] ?? 'general'), 0, 63);
        $name = substr((string) ($request->get['name'] ?? ('user-' . $request->fd)), 0, 63);
        try {
            EventBus::joinRoom($server, $request->fd, $room, $name);
        } catch (\RuntimeException $e) {
            // Room full → close with WSRouter::CLOSE_CAPACITY (4013) parity.
            $server->push($request->fd, json_encode(['type' => 'error', 'error' => 'room_full', 'room' => $room]));
            $server->disconnect($request->fd, EventBus::CLOSE_CAPACITY, 'room full');
            return;
        }
        $server->push($request->fd, json_encode([
            'type'    => 'joined',
            'room'    => $room,
            'name'    => $name,
            'members' => EventBus::roomMembers($room),
        ]));
    },
    onMessage: function ($server, $frame, $g) {
        // A room message fans out to that room only. The sender's room is
        // resolved from the shared membership table (state lives in Store, not
        // on the handler — stateless, coroutine-safe).
        $row = \ZealPHP\Store::get('ws_rooms', (string) $frame->fd);
        if (is_array($row)) {
            EventBus::roomBroadcast($server, (string) $row['room'], [
                'type' => 'message',
                'from' => (string) $row['name'],
                'msg'  => substr((string) $frame->data, 0, 500),
            ]);
        }
    },
    onClose: function ($server, $fd, $g) {
        EventBus::leaveRoom($server, $fd);   // fires the presence-leave
    }
);

// ── B5 — SSE: board metrics tick (named events + heartbeat, stops on disconnect)
$app->route('/stream/metrics', function ($response) {
    $response->sse(function (callable $emit) {
        for ($i = 0; $i < 600; $i++) {            // bounded so the demo can't run forever
            $emit(json_encode([
                'online' => EventBus::onlineCount(),
                'events' => count(EventBus::recentEvents()),
                'mem'    => memory_get_usage(true),
                'i'      => $i,
            ]), 'metrics', (string) $i);          // named event 'metrics' + id (Last-Event-ID)
            if ($i % 10 === 9) {
                $emit('', 'heartbeat');           // keepalive ping every 10 ticks
            }
            \OpenSwoole\Coroutine::sleep(1);      // yields the worker (HOOK_ALL) — non-blocking
            if (connection_aborted()) {           // EventSource closed → stop
                break;
            }
        }
    });
});

// ── B6 — streamed live log tail: stops the moment the client disconnects ─────
$app->route('/stream/logs', function ($response) {
    $response->stream(function (callable $write) {
        $line = 0;
        while (true) {
            $ok = $write('log#' . (++$line) . ' ' . date('H:i:s') . " pulse=green\n");
            // $write() returns false when the client is gone; connection_aborted
            // is the explicit check the demo documents. Either ends the tail.
            if ($ok === false || connection_aborted() || $line >= 1000) {
                break;
            }
            \OpenSwoole\Coroutine::sleep(1);
        }
    });
});

// ── B7 — generator SSR: the initial board, streamed progressively ────────────
$app->route('/p7/board', function () {
    return (function () {
        yield "<!doctype html><meta charset=utf-8><title>Live board — ZealPulse</title>\n";
        yield '<link rel="stylesheet" href="/css/app.css">' . "\n";
        yield "<h1>ZealPulse · live board</h1>\n";
        yield '<p>online: ' . EventBus::onlineCount() . " · recent events:</p>\n<ul>\n";
        foreach (EventBus::recentEvents() as $e) {
            $msg = htmlspecialchars((string) $e['msg'], ENT_QUOTES);
            yield "  <li>[{$e['type']}] {$msg}</li>\n";   // each row flushed as its own chunk
        }
        yield "</ul>\n";
        yield '<p><a href="/realtime">open the realtime console →</a></p>' . "\n";
    })();
});

// ── the realtime console (HTML; JS lives in public/js/realtime.js) ───────────
$app->route('/realtime', function () {
    Http::secureHeaders();
    return <<<HTML
        <!doctype html><meta charset=utf-8><title>Realtime — ZealPulse</title>
        <link rel="stylesheet" href="/css/app.css">
        <h1>ZealPulse · realtime console (Phase 7)</h1>
        <section>
          <h2>Live feed (WS /live)</h2>
          <p>online: <span id="online">–</span></p>
          <input id="noteInput" placeholder="post a note to everyone">
          <button id="noteSend">send</button>
          <ul id="feed"></ul>
        </section>
        <section>
          <h2>Incident room (WS /incident)</h2>
          <input id="roomName" value="incident-42">
          <input id="userName" value="you">
          <button id="roomJoin">join</button>
          <p>members: <span id="members">–</span></p>
          <input id="roomInput" placeholder="message the room">
          <button id="roomSend">send</button>
          <ul id="roomFeed"></ul>
        </section>
        <section>
          <h2>SSE metrics (/stream/metrics)</h2>
          <pre id="metrics">connecting…</pre>
        </section>
        <script src="/js/realtime.js"></script>
        HTML;
});

// ── B7 (Phase-7 index) ───────────────────────────────────────────────────────
$app->route('/p7', function () {
    Http::secureHeaders();
    return <<<HTML
        <!doctype html><meta charset=utf-8><title>Phase 7 — streaming/WS</title>
        <link rel="stylesheet" href="/css/app.css">
        <h1>ZealPulse · Phase 7 — streaming / SSE / WebSocket</h1>
        <ul>
          <li><a href="/realtime">/realtime</a> — the live console (WS feed + room + SSE)</li>
          <li><a href="/p7/board">/p7/board</a> — generator SSR board (progressive)</li>
          <li><code>/stream/metrics</code> — SSE board ticks · <code>/stream/logs</code> — streamed log tail</li>
          <li><code>ws://…/live</code> — broadcast feed · <code>ws://…/incident?room=X&amp;name=Y</code> — rooms</li>
        </ul>
        <p><em>WSRouter (the production room API) is unusable on v0.4.8 — it crashes every worker at boot
        (upstream #415). ZealPulse implements rooms with plain <code>App::ws()</code> + shared <code>Store</code>.</em></p>
        HTML;
});

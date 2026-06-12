<?php
/**
 * ZealPulse — Phase 7: the live event bus (WebSocket fan-out + incident rooms).
 *
 * NOTE on WSRouter: the framework ships WSRouter / WS\Room as the production
 * room API, but on the released v0.4.8 `WSRouter::init()` crashes every worker
 * at boot (filed upstream #415 — onWorkerStart/onWorkerStop hooks declare
 * `int $workerId` while the framework calls `$fn($server, $workerId)`). So
 * ZealPulse implements the same thing with plain `App::ws()` + shared `Store`
 * tables (cross-worker, made before run()): a live-feed subscriber set, a
 * per-room membership table, and a recent-event ring. `$server->push()` and
 * `isEstablished()` are cross-worker, so a message arriving on any worker
 * fans out to every subscriber's fd regardless of which worker owns it.
 *
 * Tables (created in app.php BEFORE run()):
 *   ws_live    key=(string)$fd  {fd}                    — live-feed subscribers
 *   ws_rooms   key=(string)$fd  {room, name, fd}        — incident-room members
 *   live_ring  key=(string)$slot {seq, type, msg, ts}   — last RING_SIZE events
 * Online count = Store::count('ws_live') (single source, self-healing via the
 * stale-fd reaper). Counter zp_evt_seq drives the event-ring sequence.
 */
declare(strict_types=1);

namespace ZealPulse;

use ZealPHP\Store;
use ZealPHP\Counter;

final class EventBus
{
    public const ROOM_CAPACITY = 50;     // per-incident-room member cap (→ 4013 on overflow)
    public const RING_SIZE     = 50;     // recent events retained for replay-on-connect
    public const CLOSE_CAPACITY = 4013;  // WSRouter::CLOSE_CAPACITY parity

    /** Subscribe an fd to the global live feed; returns the new online count. */
    public static function subscribeLive($server, int $fd): int
    {
        Store::set('ws_live', (string) $fd, ['fd' => $fd]);
        return self::onlineCount();       // Store::count is the single source (self-healing)
    }

    /** Drop an fd from the live feed AND any room it was in (idempotent). */
    public static function unsubscribe($server, int $fd): void
    {
        Store::del('ws_live', (string) $fd);
        self::leaveRoom($server, $fd);    // also fires a presence-leave if in a room
    }

    /** Fan a JSON event out to every live-feed subscriber + append to the ring. */
    public static function publishLive($server, array $event): int
    {
        $event['ts'] ??= time();
        self::appendRing($event['type'] ?? 'event', (string) ($event['msg'] ?? ''));
        return self::pushToTable($server, 'ws_live', null, $event);
    }

    /** Join an incident room. Throws on capacity. Fires a presence-join. */
    public static function joinRoom($server, int $fd, string $room, string $name): void
    {
        if (self::roomSize($room) >= self::ROOM_CAPACITY) {
            throw new \RuntimeException('room_full');
        }
        Store::set('ws_rooms', (string) $fd, ['room' => $room, 'name' => $name, 'fd' => $fd]);
        self::roomBroadcast($server, $room, ['type' => 'presence', 'event' => 'join', 'name' => $name, 'size' => self::roomSize($room)]);
    }

    /** Leave whatever room this fd is in (idempotent); fires a presence-leave. */
    public static function leaveRoom($server, int $fd): void
    {
        $row = Store::get('ws_rooms', (string) $fd);
        if (!is_array($row)) {
            return;
        }
        Store::del('ws_rooms', (string) $fd);
        $room = (string) ($row['room'] ?? '');
        if ($room !== '') {
            self::roomBroadcast($server, $room, ['type' => 'presence', 'event' => 'leave', 'name' => (string) ($row['name'] ?? '?'), 'size' => self::roomSize($room)]);
        }
    }

    /** Fan a JSON event out to every member of one room. */
    public static function roomBroadcast($server, string $room, array $event): int
    {
        $event['ts'] ??= time();
        $event['room'] ??= $room;
        return self::pushToTable($server, 'ws_rooms', $room, $event);
    }

    /** Member display-names currently in a room. */
    public static function roomMembers(string $room): array
    {
        $names = [];
        foreach (Store::iterate('ws_rooms') as $row) {
            if (is_array($row) && ($row['room'] ?? null) === $room) {
                $names[] = (string) ($row['name'] ?? '?');
            }
        }
        return $names;
    }

    public static function roomSize(string $room): int
    {
        $n = 0;
        foreach (Store::iterate('ws_rooms') as $row) {
            if (is_array($row) && ($row['room'] ?? null) === $room) {
                $n++;
            }
        }
        return $n;
    }

    /** O(1) live-feed online count (one row per subscriber). */
    public static function onlineCount(): int
    {
        return Store::count('ws_live');
    }

    /** The last RING_SIZE events, oldest→newest (replayed to a fresh subscriber). */
    public static function recentEvents(): array
    {
        $rows = [];
        foreach (Store::iterate('live_ring') as $row) {
            if (is_array($row) && isset($row['seq'])) {
                $rows[] = $row;
            }
        }
        usort($rows, fn($a, $b) => ($a['seq'] ?? 0) <=> ($b['seq'] ?? 0));
        return array_map(fn($r) => ['type' => $r['type'] ?? 'event', 'msg' => $r['msg'] ?? '', 'ts' => $r['ts'] ?? 0], $rows);
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * Push $event (JSON) to every fd in $table; when $room is non-null, only to
     * rows whose 'room' matches. Skips dead fds (isEstablished guard) and reaps
     * any stale row whose fd is gone. Returns the delivered count.
     */
    private static function pushToTable($server, string $table, ?string $room, array $event): int
    {
        $json = json_encode($event);
        $sent = 0;
        foreach (Store::iterate($table) as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($room !== null && ($row['room'] ?? null) !== $room) {
                continue;
            }
            $fd = (int) ($row['fd'] ?? (is_numeric($key) ? $key : 0));
            if ($fd <= 0) {
                continue;
            }
            if ($server->isEstablished($fd)) {
                $server->push($fd, $json);
                $sent++;
            } else {
                Store::del($table, (string) $fd);   // reap a stale fd (lost close)
            }
        }
        return $sent;
    }

    private static function appendRing(string $type, string $msg): void
    {
        $seq = (new Counter(0, 'zp_evt_seq'))->increment();
        $slot = (string) ($seq % self::RING_SIZE);
        Store::set('live_ring', $slot, [
            'seq'  => $seq,
            'type' => substr($type, 0, 31),
            'msg'  => substr($msg, 0, 199),
            'ts'   => time(),
        ]);
    }
}

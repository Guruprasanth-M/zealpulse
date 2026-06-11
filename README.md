# ⚡ ZealPulse

A **real-time server-ops control room** built on [ZealPHP](https://github.com/sibidharan/zealphp) (OpenSwoole) —
one operator opens it and runs their fleet live. It is also a **full-surface demo**: every ZealPHP subsystem
(HTTP, request input, static/conditional GET, sessions, middleware, routing, streaming/SSE/WebSocket,
Store/Counter, lifecycle modes, CGI, timers/tasks/signals, security, templates/htmx, infra) is exercised by a
real feature — so "the app runs" means "the framework works in practice".

Built **phase by phase** against the ZealPHP verification roadmap. The canonical build spec — what's built, what
each phase exercises, and how it's verified — is **[PROJECT.md](./PROJECT.md)**.

## Run

```bash
composer install
php app.php                      # coroutine mode (recommended), :9100
ZEAL_MODE=mixed php app.php      # or mixed / coroutine-legacy / legacy-cgi
```

Open http://127.0.0.1:9100/.

## Status

| Phase | Feature | Status |
|---|---|---|
| 1 | Response core + dashboard shell | ✅ |
| 2 | Request input + file-API | ✅ |
| 3 | Static files + conditional GET + downloads | ✅ |
| 4 | Sessions / auth (fixation-safe) | ✅ |
| 5–14 | Middleware · routing · WS/SSE · Store/Counter · dual-DB (SQL + MongoDB) · modes · CGI · timers/tasks · security · htmx · ops | 🚧 in progress |

## Stack

- **Framework:** `sibidharan/zealphp` ^0.4.8 (OpenSwoole, coroutine HTTP server)
- **Data (optional, env-gated, degrades gracefully):** MySQL system-of-record (`Db\DbConnectionPool`),
  MongoDB firehose/analytics/GridFS (`zealphp/mongodb`), Redis (Store/Counter/sessions)
- **UI:** server-rendered templates + htmx; SSE/WebSocket for live updates

Self-contained composer project — portable: clone, `composer install`, `php app.php`.

> Requires the OpenSwoole + ext-zealphp runtime to run live (see ZealPHP install docs). The app boots in any
> lifecycle mode; features whose data service is absent skip-record gracefully.

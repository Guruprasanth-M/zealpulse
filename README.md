# ⚡ ZealPulse

**A real-time server-ops control room built on ZealPHP.** One operator opens it and runs their fleet live —
throughput, per-route latency, error rates, a streaming event feed, incident rooms with presence, uptime probes,
alerting, and a live log tail — every screen powered by a different part of the stack at full depth.

ZealPulse is also a **full-surface demonstration**: it is built **phase by phase** against the ZealPHP
verification roadmap so that every framework subsystem is exercised by a *real* feature. "The app runs correctly"
literally means "the framework works in practice." The canonical build spec — what's built, what each phase
exercises, and how it's verified live — is **[PROJECT.md](./PROJECT.md)**.

---

## The stack — three layers, one async runtime

ZealPulse is a showcase for how these three projects compose into a single non-blocking PHP application:

### 1. [OpenSwoole](https://openswoole.com) — the engine
A coroutine, event-driven runtime for PHP. Instead of one process per request (mod_php/PHP-FPM), OpenSwoole runs
a small pool of long-lived workers, each scheduling thousands of **coroutines** that yield on I/O. A blocking call
(`fread`, a DB query, an HTTP request) suspends just that coroutine and lets the worker make progress on others.
That's what makes a real-time dashboard — many open SSE/WebSocket connections plus live DB tails — practical in PHP.

### 2. [ZealPHP](https://github.com/sibidharan/zealphp) — the framework
A Flask-style web framework on top of OpenSwoole that brings back the **familiar PHP request model** without losing
the async engine. Routing with parameter injection, a universal return contract (array → JSON, string → HTML,
`Generator` → SSR stream), `$_GET`/`$_POST`/`$_SERVER` superglobals, sessions, a built-in middleware band, file-based
REST (`ZealAPI`), templates + htmx, WebSocket/SSE, `Store`/`Counter` shared memory, timers/tasks/signals, and four
**lifecycle modes** (`coroutine`, `mixed`, `coroutine-legacy`, `legacy-cgi`) so the same code can run modern-async or
mod_php-compatible. ZealPulse uses all of it.

### 3. [ZealPHP-MongoDB](https://github.com/sibidharan/zealphp-mongodb) — the async data firehose
A **Rust** extension that bridges the official [mongo-rust-driver](https://github.com/mongodb/mongo-rust-driver)
into PHP and makes it **non-blocking under OpenSwoole** (eventfd + `Event::add` + `Channel`). It's a drop-in
replacement for `mongodb/mongodb` — same `Client`/`Database`/`Collection`/BSON APIs — with **real transactions,
change streams, and GridFS** (replica set required). It hits C-driver performance parity and, with coroutine
parallelism, runs **3.4×–6.7× faster** under HTTP concurrency than Apache + the C driver. ZealPulse uses it as the
**event firehose + analytics + artifact store**.

---

## Dual-database architecture — two stores, two jobs, zero overlap

ZealPulse keeps **what must be correct** and **what must be fast & flexible** in different engines:

| | **MySQL** — system of record (`ZealPHP\Db\DbConnectionPool`) | **MongoDB** — firehose + analytics + artifacts (`zealphp-mongodb`) |
|---|---|---|
| **Holds** | users/credentials · incidents · alert **rules** · probe target configs · report **metadata** | raw event documents · request-metric time-series · probe **results** · alert **firings** · audit trail · GridFS report files + avatars |
| **Why** | fixed schema, multi-row transactions, joins | schemaless per-event payloads, aggregation-pipeline rollups, **change streams** for live push, **GridFS** for blobs |
| **Access** | pooled `pdo()` via `with()`, `transaction()` for multi-row writes | one `Client` per worker; coroutine non-blocking path; `Channel`+`go()` parallel queries for the board |
| **Live push** | — | `watch()` change stream → WebSocket broadcast → the **poll-free** live board |
| **Rule** | the same fact never lives in both; cross-refs carry the other store's id; the SQL write commits first, the Mongo follow-up logs+retries on failure | |

Both layers are **optional and env-gated** — every feature skip-records gracefully when its service is absent, so
the app boots and demos even with no databases attached.

---

## What you get (product → subsystem)

| Area | The operator sees | Powered by |
|---|---|---|
| **Live board** | throughput, per-route latency, error rates, updating in place | SSE + `Generator` SSR + `Store`/`Counter` + Mongo `aggregate` |
| **Event feed** | every request/deploy/alert as a live multi-viewer feed | WebSocket broadcast + pub/sub fan-out + Mongo change streams |
| **Incident rooms** | per-incident chat with presence, member list, auth | `WSRouter` / `WS\Room` (HMAC, rate-limit, backpressure) |
| **Uptime prober** | concurrent HTTP checks of N targets, per-target latency | `ZealPHP\HTTP` client + `App::parallel` coroutines |
| **Reports** | CSV/HTML generated off the request path, resumable downloads | task workers + `sendFile()` (ETag/Range/conditional GET) + GridFS |
| **Alerting** | threshold alerts fan out to every worker + a durable audit trail | `Store::publish`/`App::subscribe` + reliable streams |
| **Log tail** | live `tail -F` of access/debug logs, stops on disconnect | `$response->stream()` + `connection_aborted()` |
| **Admin area** | gated control surface: prune, toggle alerts, run probes | route groups + security middleware band + sessions |
| **Legacy bay** | a stock mod_php-era script (and a non-PHP CGI) running unmodified | `App::include()` + CGI strategies |
| **Modes lab** | the live lifecycle-mode matrix + an isolation burst probe | `App::mode()`/`isolation()` |
| **Ops surface** | `/healthz` `/readyz` `/_metrics` (Prometheus) `/_info`, structured logs, CLI | HealthCheck mw + `App::stats` + `PhpInfo` + `Logger` |

---

## Run

```bash
composer install
bash scripts/setup-env.sh up                 # one command: MongoDB (replica set) + MariaDB + Redis (no sudo/docker)
source ~/.zealpulse-env/zealpulse.env
php app.php                                   # coroutine mode (recommended), http://127.0.0.1:9100
ZEAL_MODE=mixed php app.php                   # or coroutine-legacy / legacy-cgi
```

`scripts/setup-env.sh` stands up **all three data services** from portable binaries as plain user processes and
writes the env ZealPulse reads — see **[SETUP.md](./SETUP.md)** for the full fresh-box walkthrough. The three:

| Service | Role | Accessed through |
|---|---|---|
| **MariaDB** | system of record (users, incidents, alert rules, report metadata) | `ZealPHP\Db\DbConnectionPool` (`pdo()`/`with()`/`transaction()`) |
| **MongoDB** (replica set `rs0`) | firehose · analytics rollups · change-stream live push · GridFS artifacts | `mongodb/mongodb` (C `ext-mongodb`; swap to the `zealphp-mongodb` Rust ext for the async path) |
| **Redis** | `Store`/`Counter` backend · sessions · `Store::publish`/`App::subscribe` pub-sub · WS federation | **the framework's own Redis layer** — `ZEALPHP_STORE_BACKEND=redis` + `ZEALPHP_REDIS_URL`; the framework's `PredisDriver`/`PhpredisDriver` handles the connection (the app never touches a raw client) |

> **Data services are optional** — every feature skip-records gracefully when its service is absent, so the app
> still boots and demos with nothing attached. **Runtime:** running live needs the OpenSwoole + ext-zealphp runtime;
> the async Mongo path additionally needs the `zealphp-mongodb` Rust ext (the C `ext-mongodb` works out of the box,
> blocking). Transactions / change streams / GridFS need the MongoDB **replica set** that `setup-env.sh` initializes.

---

## Build status

| Phase | Feature | Status |
|---|---|---|
| 1 | Response core + dashboard shell | ✅ |
| 2 | Request input + file-API | ✅ |
| 3 | Static files + conditional GET + downloads | ✅ |
| 4 | Sessions / auth (fixation-safe) | ✅ |
| 5 | Middleware suite (CORS/CSRF/RateLimit/Auth/RequestId/…) | ✅ |
| 6 | Routing & dispatch | ✅ |
| 7 | Streaming / SSE / WebSocket (live board, incident rooms) | ✅ |
| 8 | Store/Counter + **dual-DB data layer (MySQL + MongoDB)** | 🚧 |
| 9–14 | Lifecycle modes · CGI · timers/tasks/signals · security · htmx UI · ops surface | ◻️ |

Self-contained composer project — portable: clone, `composer install`, `php app.php`. See **[PROJECT.md](./PROJECT.md)**
for the full phase-by-phase plan and the per-API utilization checklist.

---

## Built with

- [sibidharan/zealphp](https://github.com/sibidharan/zealphp) — the framework (OpenSwoole-based)
- [OpenSwoole](https://openswoole.com) — the coroutine HTTP/async runtime
- [sibidharan/zealphp-mongodb](https://github.com/sibidharan/zealphp-mongodb) — the async Rust MongoDB driver
- MariaDB/MySQL (`Db\DbConnectionPool`) · **Redis via the framework's `Store`/`Counter`/session/pub-sub layer**
  (`PredisDriver`/`PhpredisDriver`) · htmx (UI). `scripts/setup-env.sh` stands all three up with no sudo/docker.

ZealPulse is developed alongside a ZealPHP verification effort — building it phase by phase surfaces real framework
bugs (e.g. a HIGH session-fixation fatal found while building the Phase-4 login) that get reported upstream.

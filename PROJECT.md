# ZealPulse — build roadmap & phase plan (the canonical AI build spec)

> **Read this first, every time, before building the next phase.** This is the single source of truth for
> *what ZealPulse is, what it uses, and exactly how it is built phase-by-phase (inch by inch)*. An AI picking up
> the build follows the **Build protocol** below and the **per-phase plan**, ticks the batch checklist as each
> behaviour is confirmed live, updates the **Status** line, commits, and moves to the next phase — **never skipping
> a batch, never re-filing a known divergence.**

---

## 1. What we are building

**ZealPulse** is a **real-time server-ops dashboard & live event board** built on ZealPHP (OpenSwoole). It is a
genuinely useful product — a single operator opens it and watches their ZealPHP fleet live: request throughput,
per-route metrics, a streaming event feed, online viewers, and a control surface — and at the same time it is the
**cross-phase validation surface**: every ZealPHP capability (all 14 verification phases) is exercised by a real
ZealPulse feature, so "the app runs correctly" == "the phase works in practice".

It is a **self-contained composer project** (`zealpulse/` with its own `composer.json` + `vendor/`). Portable:
move the folder anywhere → `composer install` → `php app.php`.

```
zealpulse/
  composer.json        # require sibidharan/zealphp ^0.4.8 ; PSR-4 ZealPulse\ -> src/
  app.php              # bootstrap: mode, documentRoot, Store tables, middleware, $app->run()
  src/                 # ZealPulse\ service classes (business logic; thin route handlers call these)
  route/phaseN.php     # ONE module per phase — auto-included by ZealPHP; each does $app = App::instance();
  api/                 # file-based REST endpoints (ZealAPI): api/<name>.php with $get/$post/...
  template/            # render()/renderStream() templates (HTML only; htmx-driven)
  public/              # docroot: index.php + css/ js/ img/ static assets
  PROJECT.md           # THIS FILE — the build roadmap
```

**Run modes** (env-driven, like the parity harness): `ZEAL_MODE=coroutine|mixed|coroutine-legacy|legacy-cgi`
(default `coroutine`), `ZEAL_PORT` (default 9100). Default = **coroutine** (the recommended mode; streaming is correct there).

---

## 2. ZealPHP capability inventory — everything ZealPulse uses

| Capability | ZealPHP API | ZealPulse feature | Phase |
|---|---|---|---|
| Response contract | route return: array→JSON, string→HTML, int→status, Generator→stream | every endpoint | 1, 13 |
| Header/cookie engine | `header()`, `setcookie()`, multi-append | security headers, theme cookie | 1 |
| Redirects | `Response::redirect()` (open-redirect guards) | `/go`, post-login | 1, 12 |
| Request input | `$_GET/$_POST/$_FILES/$_COOKIE`, `$g->*`, `getallheaders()` | filters, forms, avatar upload | 2 |
| `$_SERVER`/SAPI | `buildServerVars`, auth meta-vars | request context, basic-auth API | 2 |
| Static + conditional | native static handler + `Response::sendFile()` (ETag/Range/If-*) | assets, report/CSV download | 3 |
| Sessions | `session_start`, `$_SESSION`, `App::sessionHandler()` | login, per-user prefs | 4 |
| Middleware | `App::middlewareAlias`, `addMiddleware`, `group`, `when`, per-route | CORS/CSRF/RateLimit/Auth/RequestId | 5, 12 |
| Routing | `route`, `nsRoute`, `patternRoute`, `group`, path params | the whole URL map | 6 |
| Streaming/SSE/WS | Generator, `$response->stream()`, `$response->sse()`, `$app->ws()` | live feed, metrics stream, presence | 7 |
| Shared memory | `Store::make/get/set`, `Counter` | metrics table, hit counters | 8 |
| Cache/DB | `Cache`, `Db\*` | cached aggregates | 8 |
| Lifecycle modes | `App::mode()`, isolation knobs | `/modes` showcase + run matrix | 9 |
| CGI dispatch | `App::include()`, `cgiMode()` | legacy-script compat page | 10 |
| Timers/Tasks/Signals | `App::tick/after`, `task()`, `onSignal`, `addProcess` | metric aggregation, graceful stop | 11 |
| Security | CSRF, rate-limit, input validation, secure headers | hardening across the app | 12 |
| Templates/htmx | `App::render/renderToString/renderStream`, `hx-*` | the UI | 13 |
| Infra | `PhpInfo`, `Logger`, CLI, health | `/health`, `/_info`, `/_metrics` | 14 |

---

## 3. Known filed issues & held divergences — RESPECT, never re-file

These were found in the v0.4.8 Phase-1/2 + Phase-3 verification. ZealPulse **works around or tolerates** them; it
does **not** re-file them. (See `../PHASE1-2-RERUN-V048-VERDICT.md`, `../PHASE3-STATIC-CONDITIONAL-VERDICT.md`,
`../sibidharan.md`.)

| # | What | ZealPulse stance |
|---|---|---|
| #354 | Generator/SSR crashes the worker in `mixed` (Coroutine::sleep(0) outside a coroutine) | Run streaming in **coroutine** mode; document the limitation; never stream a generator in mixed. |
| #355 | Unsolicited PHPSESSID minted every request (mixed/legacy-cgi) | Accept it (session-backed app); note it. |
| #356 | `$_REQUEST` GET-wins in mixed/coroutine-legacy | Read `$_GET`/`$_POST` explicitly, never `$_REQUEST`. |
| #357 | `header_register_callback()` dropped in legacy-cgi | Don't rely on it in legacy-cgi. |
| #290 | 204/304 still emit CT + CL:0 (reopen-flagged) | Cosmetic; tolerate. |
| #306 | int ports + missing SERVER_ADDR (reopen-flagged) | Cast/guard if a port is read. |
| HELD | charset not auto-appended (CharsetMiddleware opt-in) | Set charset explicitly OR mount CharsetMiddleware (Phase 5). |
| HELD | non-standard in-range status → 200 | Only use IANA codes. |
| HELD | native static handler intercepts whitelisted paths (`/robots.txt`, `/favicon.ico`, `/css/`…) before PHP | Put real files in `public/`, or serve via `sendFile()` on a non-whitelisted path (Phase 3). |
| Phase-3 candidates (to confirm+file in the Phase-3 sweep) | `fw-sendfile-head-body`, `fw-wellknown-includecheck-block`, `fw-sendfile-content-disposition-rfc6266` | Confirm live in Phase 3; file per workflow. |

---

## 4. Per-phase build plan — inch by inch

> For each phase: **Feature** (what ZealPulse gains), **APIs**, **Batches** (the phase.md B-list to confirm live),
> **Done** (acceptance), **Status**. Build a phase → run it across the relevant modes → confirm each batch on the
> wire → tick the boxes → update Status → commit `zealpulse: Phase N — <feature>` → next phase.

### Phase 1 — HTTP response & headers · `route/phase1.php` · **Status: ✅ DONE (coroutine, v0.4.8)**
- **Feature:** dashboard shell + response core (security headers, status playground, prefs cookie, safe redirect, streamed feed, health).
- **APIs:** route return contract, `header()` multi-append, `setcookie()`, `Response::redirect()`, Generator stream.
- **Batches confirmed live:** ☑ B1 status (418/451 ok, 999→500, 204) · ☑ B2 header family (X-ZealPulse ×2, two `Link`) · ☑ B3 cookie (zp_theme; Max-Age/SameSite) · ☑ B4 framing (204 len 0; HEAD strips body, keeps CL) · ☑ B5 redirect (302 + offsite→`/` guard) · ☑ B6 charset/CT (array→`application/json`; text charset explicit) · ☑ B7 contract (array/string/int/Generator) · ☑ B8 re-verify (known #290/#354 respected, not re-filed).
- **Done:** all endpoints green in coroutine; mixed deferred for the generator route (#354).

### Phase 2 — Request input & SAPI · `route/phase2.php` + `api/events.php` + `src/Req.php` · **Status: ✅ DONE (coroutine + mixed, v0.4.8)**
- **Feature:** the input layer — `/search` metrics filter (GET), `/events/submit` (POST urlencoded + JSON), `/upload` avatar (`$_FILES`), `/whoami` request inspector, `/admin/probe` Basic-auth gate, `api/events` file-API (GET/POST).
- **APIs:** `$g->get/post/cookie/server` (mode-portable), `$_GET/$_POST/$_FILES/$_COOKIE`, `php://input`, `is_uploaded_file()`, `getallheaders()`, Basic-auth meta-vars, ZealAPI files.
- **Batches confirmed live:** ☑ B1 `$_GET` (route/min/`tags[]` array, both modes) · ☑ B2 `$_POST`+`php://input` (form + JSON, raw_len) · ☑ B3 `$_FILES` (single upload, `is_uploaded_file` true, forged `/etc/passwd`→false) · ☑ B4 `$_COOKIE` (zp_theme+sid parsed) · ☑ B5 `$_REQUEST` avoided — read `$_GET`/`$_POST` explicitly (#356) · ☑ B6 `$_SERVER` (port string-coerced, `SERVER_ADDR` absent confirmed #306) · ☑ B7 Basic-auth (`PHP_AUTH_USER`=ops decoded; 401 gate) · ☑ B8 `getallheaders()` (Authorization etc.) · ☑ B9 file-API/RequestInput path · ☑ B10 `G` aliasing + superglobal n/a in coroutine (`$_GET` populated=True mixed / False coroutine) · ☑ B11 re-verify (known issues respected).
- **Done:** forms/upload/auth/API all green in mixed; `$g->*` portable in coroutine; superglobal n/a in coroutine confirmed.

### Phase 3 — Static files & conditional GET · `route/phase3.php` · **Status: ◻️ planned**
- **Feature:** asset pipeline + a **report/CSV download** via `sendFile()` with ETag/Range/conditional-GET; a `.well-known/` health doc.
- **APIs:** native static handler (css/js), `Response::sendFile()` (conditional + range + weak ETag), `If-None-Match`/`If-Modified-Since`/`Range`.
- **Batches:** ☐ B1 sendFile core (ETag/Last-Modified/MIME) · ☐ B2 conditional GET (304/412) · ☐ B3 ranges (206/416/multipart) · ☐ B4 native static handler (Last-Modified only; whitelist interception) · ☐ B5 dir/URL normalization · ☐ B6 charset/compression · ☐ B7 re-verify.
- **Note:** confirm the 3 Phase-3 candidates (HEAD body-leak, `.well-known` unservable, Content-Disposition RFC 6266) live → file via the Phase-3 ultracode sweep.
- **Done:** download works with ETag/Range; whitelist interception documented.

### Phase 4 — Sessions · `route/phase4.php` + `src/Auth.php` · **Status: ◻️ planned**
- **Feature:** login/logout, session-backed identity, per-user dashboard prefs.
- **APIs:** `session_start()`, `$_SESSION`, `App::sessionHandler()` (file/Table), `App::sessionLifecycle()`.
- **Batches:** ☐ session create/read/write across modes · ☐ regeneration on login (fixation) · ☐ handler choice · ☐ GC.
- **Done:** login persists across requests; logout clears.

### Phase 5 — Middleware suite · `app.php` registrations + `src/Middleware/` · **Status: ◻️ planned**
- **Feature:** the ops/security layer — CORS (API), RequestId (every response), RateLimit (API), CSRF (forms), CharsetMiddleware (fixes the held charset gap), ETag (static), Auth (admin group).
- **APIs:** `App::middlewareAlias()`, `addMiddleware()`, `group()`, `App::when()`, per-route `middleware:`.
- **Batches:** ☐ global order · ☐ group nesting · ☐ per-route · ☐ `when` path-scope · ☐ short-circuit (403/redirect).
- **Done:** admin routes gated; API rate-limited; CSRF on forms; X-Request-Id echoed.

### Phase 6 — Routing & dispatch · `route/phase6.php` · **Status: ◻️ planned**
- **Feature:** the full URL map — path params, `nsRoute` (admin namespace), `patternRoute` (catch-all), `group` (admin), fallback.
- **APIs:** `route`, `nsRoute`, `nsPathRoute`, `patternRoute`, `group`, `setFallback`, `describeRoutes()`.
- **Batches:** ☐ match order · ☐ param injection by name · ☐ method dispatch · ☐ 404/405 · ☐ describeRoutes introspection.
- **Done:** all route kinds resolve; a `/routes` introspection page renders `describeRoutes()`.

### Phase 7 — Streaming / SSE / WebSocket · `route/phase7.php` + `src/EventBus.php` · **Status: ◻️ planned**
- **Feature:** the **live** part — a WebSocket `/live` feed broadcasting events to all viewers, an SSE `/stream/metrics` ticking metrics, presence (online count via Counter), generator SSR for the initial board.
- **APIs:** `$app->ws()` (onOpen/onMessage/onClose), `$response->sse()`, `$response->stream()`, Generator, `server->push()`/`getClientList()`.
- **Batches:** ☐ WS open/message/broadcast/close · ☐ SSE emit + HEAD-strip · ☐ generator stream · ☐ presence count.
- **Run:** coroutine (streaming correct); document mixed #354.
- **Done:** two browsers see each other's events live; metrics tick via SSE.

### Phase 8 — Store / Counter / Cache / DB · `app.php` tables + `src/Metrics.php` · **Status: ◻️ planned**
- **Feature:** the metrics engine — a `Store` table for per-route counts + a rolling event log, `Counter` for total hits / online viewers, cached aggregates.
- **APIs:** `Store::make/set/get/incr`, `Counter::increment`, `Cache`.
- **Batches:** ☐ Store schema + cross-worker visibility · ☐ Counter atomicity · ☐ incr semantics · ☐ cache TTL.
- **Done:** metrics survive across workers; counts are atomic under a burst.

### Phase 9 — Lifecycle modes & isolation · `route/phase9.php` · **Status: ◻️ planned**
- **Feature:** a `/modes` page showing the live mode + the per-mode behaviour matrix; a small concurrency probe proving per-coroutine isolation.
- **APIs:** `App::mode()`, `isolation()`, coroutine-legacy isolation knobs.
- **Batches:** ☐ mode reports correctly · ☐ superglobal n/a in coroutine · ☐ per-coroutine `$g` isolation under burst.
- **Done:** the matrix renders; isolation burst shows zero leak.

### Phase 10 — CGI dispatch · `route/phase10.php` + a legacy script · **Status: ◻️ planned**
- **Feature:** a "legacy script" compatibility page served via `App::include()` (CGI subprocess in legacy-cgi).
- **APIs:** `App::include()`, `cgiMode()`, `registerCgiBackend()`.
- **Batches:** ☐ include runs the file · ☐ return contract across the CGI boundary · ☐ env isolation.
- **Done:** a stock `.php` script runs unmodified and threads its result back.

### Phase 11 — Timers / Tasks / Signals / Sidecars · `app.php` lifecycle hooks · **Status: ◻️ planned**
- **Feature:** background metric aggregation (`tick` per worker), a delayed welcome job (`after`), heavy report generation off the request path (`task()`), graceful shutdown (`onSignal`), a sidecar pruner (`addProcess`).
- **APIs:** `App::tick/after`, `task()`, `onSignal`, `addProcess`, `onWorkerStart`.
- **Batches:** ☐ tick fires per worker · ☐ after once · ☐ task offloads + returns · ☐ signal graceful-stop · ☐ sidecar alive.
- **Done:** metrics aggregate on a timer; SIGTERM drains cleanly.

### Phase 12 — Security review · cross-cutting · **Status: ◻️ planned**
- **Feature:** harden everything — CSRF on all forms, rate-limit on auth, input validation/escaping, secure headers (Phase 1 helper), no open redirect (Phase 1 guard), trusted-proxy XFF handling.
- **Batches:** ☐ CSRF reject · ☐ rate-limit 429 · ☐ XSS escape in templates · ☐ open-redirect blocked · ☐ headers present.
- **Done:** a quick self-audit checklist passes.

### Phase 13 — Templates · file-execution · htmx · `template/` + `public/js/` · **Status: ◻️ planned**
- **Feature:** the real UI — `render()` layout, `renderStream()` progressive board, htmx (`hx-get`/`hx-post`/`hx-swap`, `hx-boost`), partials.
- **APIs:** `App::render/renderToString/renderStream`, streaming-template Closures, htmx conventions.
- **Batches:** ☐ render direct · ☐ renderToString capture · ☐ renderStream progressive · ☐ htmx swaps.
- **Done:** the dashboard is htmx-driven; SSR streams the initial board.

### Phase 14 — Framework infra & demo surface · `route/phase14.php` + `api/` · **Status: ◻️ planned**
- **Feature:** ops endpoints — `/health` (Phase 1), `/_metrics` (Prometheus-ish text), `/_info` (PhpInfo), structured logging, a small CLI command.
- **APIs:** `PhpInfo`, `Logger`, `Counter`/`Store` read, CLI.
- **Batches:** ☐ health · ☐ metrics text · ☐ info page · ☐ log lines · ☐ CLI command.
- **Done:** ops surface complete; logs structured.

---

## 5. Build protocol (the workflow — repeat per phase)

1. **Read** §3 (known issues) + the next phase's plan in §4.
2. **Build** `route/phaseN.php` (+ any `src/`, `api/`, `template/`, `public/` it needs). Thin handlers → `src/` services.
3. **Run** `ZEAL_MODE=<mode> ZEAL_PORT=9100 php app.php` for each mode the phase needs (coroutine always; superglobal modes for input phases).
4. **Confirm** every batch ☐ on the wire (curl headers+body; raw socket where framing matters). Tick ☑ as confirmed.
5. **Respect** §3 — if a known divergence shows, tolerate/work around it; **do not re-file**. A *new* divergence → note it for the phase's verification sweep (not the app build).
6. **Update** this file: flip the phase **Status** to ✅ DONE with the live result, tick its batch boxes.
7. **Commit** on `project/zealpulse`: `zealpulse: Phase N — <feature> (B1–Bn confirmed)`; push.
8. **Next** phase. Never skip a batch; never leave a phase half-confirmed.

## 6. Status board

| Phase | Feature | Status |
|---|---|---|
| 1 | Response core + dashboard shell | ✅ DONE (coroutine, v0.4.8) |
| 2 | Request input + file-API | ✅ DONE (coroutine + mixed, v0.4.8) |
| 3 | Static + conditional GET + download | ⏳ NEXT |
| 4 | Sessions / auth | ◻️ |
| 5 | Middleware suite | ◻️ |
| 6 | Routing & dispatch | ◻️ |
| 7 | Streaming / SSE / WebSocket | ◻️ |
| 8 | Store / Counter / Cache | ◻️ |
| 9 | Lifecycle modes & isolation | ◻️ |
| 10 | CGI dispatch | ◻️ |
| 11 | Timers / Tasks / Signals | ◻️ |
| 12 | Security review | ◻️ |
| 13 | Templates / htmx UI | ◻️ |
| 14 | Infra & ops surface | ◻️ |

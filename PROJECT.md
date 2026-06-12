# ZealPulse — build roadmap & phase plan (the canonical AI build spec)

> **Read this first, every time, before building the next phase.** This is the single source of truth for
> *what ZealPulse is, what it uses, and exactly how it is built phase-by-phase (inch by inch)*. An AI picking up
> the build follows the **Build protocol** (§5) and the **per-phase plan** (§4), ticks the batch checklist as each
> behaviour is confirmed live, updates the **Status** line, commits, and moves to the next phase — **never skipping
> a batch, never re-filing a known divergence.**
>
> **Mission statement (v2, 2026-06-11):** ZealPulse exercises the **ENTIRE app-facing ZealPHP v0.4.8 API surface —
> nothing left out.** The framework's published surface was tokenizer-audited in `../phase.md` (1522 functions /
> 130+ classes); §7 of this file is the **app-facing utilization appendix** derived from it — every public API an
> application can call is mapped to a real ZealPulse feature and ticked when it runs live. "Full capacity" is
> auditable, not aspirational.

---

## 1. What we are building

**ZealPulse** is a **real-time server-ops control room** built on ZealPHP (OpenSwoole). One operator opens it and
runs their fleet live — and every screen is powered by a different ZealPHP subsystem at full depth:

| Product area | What the operator gets | Powered by |
|---|---|---|
| **Live board** | request throughput, per-route latency, error rates — updating in place | SSE + Generator SSR + `fragment()` + Store/Counter |
| **Event feed** | every request/deploy/alert as a live scrolling feed, multi-viewer | WebSocket broadcast + pub/sub fan-out |
| **Incident room** | per-incident chat rooms with presence, member list, capacity, auth | `WSRouter` + `WS\Room` federation (HMAC, rate-limits, backpressure) |
| **Uptime prober** | concurrent HTTP checks of N targets with per-target latency | `ZealPHP\HTTP` client + `App::parallel`/`HTTP::all` |
| **Reports** | CSV/HTML report generation off the request path; resumable downloads | task workers + `sendFile()` ETag/Range/conditional-GET |
| **System of record (SQL)** | users, incidents, alert rules, probe targets, report metadata — fixed schema, transactions, joins | `Db\DbConnectionPool` (`pdo()`/`with()`/`transaction()`) |
| **Event firehose & analytics (MongoDB)** | every event as a flexible document; aggregation-pipeline rollups power the board; live tail via change streams | `zealphp/mongodb` async Rust driver (coroutine non-blocking) |
| **Artifact vault (GridFS)** | generated report files + uploaded avatars stored in Mongo GridFS with revisions | `selectGridFSBucket()` upload/download streams |
| **Alerting** | threshold alerts fan out to every worker + an audit trail that survives restarts | `Store::publish`/`App::subscribe` + reliable streams (`publishReliable`) |
| **Log tail** | live `tail -F` of the access/debug logs in the browser, stops on disconnect | `$response->stream()` + `connection_aborted()` |
| **Admin area** | gated control surface: prune data, toggle alerts, run probes | route groups + the security middleware band + sessions |
| **Legacy bay** | a stock mod_php-era script (and a non-PHP CGI) running unmodified | `App::include()` + CGI strategies + `registerCgiBackend` |
| **Modes lab** | the live lifecycle-mode matrix + an isolation burst probe | `App::mode()`/`isolation()` + the isolation knob set |
| **Ops surface** | `/healthz` `/readyz` `/_metrics` (Prometheus) `/_info` (phpinfo), structured logs, CLI | HealthCheck mw + `App::stats` + `PhpInfo` + `Logger` + CLI |

It is simultaneously the **cross-phase validation surface**: every ZealPHP capability (all 14 verification phases
of `../phase.md`) is exercised by a real feature, so "the app runs correctly" == "the phase works in practice".

It is a **self-contained composer project** (`zealpulse/` with its own `composer.json` + `vendor/`). Portable:
move the folder anywhere → `composer install` → `php app.php`.

```
zealpulse/
  composer.json        # require sibidharan/zealphp ^0.4.8 + zealphp/mongodb (github sibidharan/zealphp-mongodb) ; PSR-4 ZealPulse\ -> src/
  app.php              # bootstrap: mode, documentRoot, knobs, Store tables, middleware, lifecycle hooks, $app->run()
  src/                 # ZealPulse\ service classes (business logic; thin route handlers call these)
    Http.php Req.php Reports.php   # (built: P1-P3)
    Auth.php Metrics.php EventBus.php Alerts.php Prober.php LogTail.php Incidents.php Ops.php  # (planned)
    Sql.php Mongo.php Firehose.php Vault.php  # (planned) the dual data layer: SQL system-of-record + Mongo firehose/GridFS
  route/phaseN.php     # ONE module per phase — auto-included by ZealPHP; each does $app = App::instance();
  api/                 # file-based REST endpoints (ZealAPI): api/<name>.php with $get/$post/... + in-file $middleware
  task/                # task-worker handlers (heavy report build runs here, off the request path)
  template/            # render()/renderStream() templates (HTML only; htmx-driven; streaming Closures allowed)
  public/              # docroot: index.php + css/ js/ img/ + favicon.ico robots.txt + legacy/ (the legacy bay scripts)
  scripts/cgi/         # non-PHP CGI backends for cgiScriptAlias (e.g. status.sh, status.py)
  assets/              # non-docroot files served ONLY via sendFile() (reports, downloads — never static-handler reachable)
  PROJECT.md           # THIS FILE — the build roadmap
```

**Run modes** (env-driven, like the parity harness): `ZEAL_MODE=coroutine|mixed|coroutine-legacy|legacy-cgi`
(default `coroutine`), `ZEAL_PORT` (default 9100). Default = **coroutine** (the recommended mode; streaming is
correct there). `coroutine-legacy` requires ext-zealphp — the modes lab degrades gracefully when it's absent.

**Data services** (all optional — every feature skip-records gracefully when its service is absent):
`ZP_DB_DSN`/`ZP_DB_USER`/`ZP_DB_PASS` (MySQL via `Db\DbConnectionPool`) · `ZP_MONGO_URI` (default
`mongodb://127.0.0.1:27017`) + `ZP_MONGO_DB` (default `zealpulse`) via `zealphp/mongodb` (needs the Rust
`ext-zealphp-mongodb-ext`; transactions + change streams additionally need a **replica set** — server rule) ·
`ZEALPHP_REDIS_URL` (Store/Counter/sessions/WS federation).

### 1.1 Dual-database architecture — two stores, two jobs, zero overlap

| | **SQL (MySQL · `ZealPHP\Db\DbConnectionPool`)** | **MongoDB (`ZealPHP\MongoDB\*` · async Rust driver)** |
|---|---|---|
| **Role** | **System of record** — what must be correct | **Firehose + analytics + artifacts** — what must be fast and flexible |
| **Holds** | users/credentials · incident records · alert RULES · probe target configs · report METADATA | raw event documents (per-type payload shapes) · request-metric time-series · probe RESULT history · alert FIRINGS · audit documents · GridFS report files + avatars |
| **Why this store** | fixed schema, multi-row transactions, joins (incident ⟷ user ⟷ alert rule) | schemaless per-event payloads, aggregation pipelines for rollups, change streams for live push, GridFS for blobs |
| **Access pattern** | `pdo()` checkout via `with()` (auto-return), `transaction()` for multi-row writes, pooled per worker | one `Client` per worker (Rust ext pools connections); coroutine mode = non-blocking eventfd path; `Channel`+`go()` parallel queries for the board |
| **Write path** | request → service → `transaction()` | request → `Firehose::emit()` → `insertOne` (fire-and-forget via `App::go`) |
| **Read path** | admin CRUD pages, joins | board rollups (`aggregate`), feed scans (`find` cursors), `distinct` filters |
| **Live push** | — (poll-free UI comes from Mongo) | `watch()` change stream → WSRouter broadcast (the no-poll live board) |
| **Cross-store rule** | The SAME fact never lives in both. Cross-references carry the other store's id (`incident.mongo_event_id` / event doc `{incident_id: <sql pk>}`). SQL writes inside `transaction()`; the paired Mongo write follows AFTER commit (and a failed Mongo follow-up is logged + retried by the pruner sidecar — recorded, not hidden). | |

---

## 2. ZealPHP capability inventory — everything ZealPulse uses

> The quick map (capability → feature → phase). The **exhaustive per-API checklist is §7** — this table is the
> orientation view; §7 is the audit.

| Capability | ZealPHP API (headline) | ZealPulse feature | Phase |
|---|---|---|---|
| Response contract | array→JSON · string→HTML · int→status · Generator→stream · `ResponseInterface` passthrough · null/echo buffering | every endpoint | 1, 13 |
| Header/cookie engine | `header()` multi-append · `setcookie()`/`setrawcookie()` · `headers_list/sent/remove` · `header_register_callback` | security headers, theme cookie, request-id echo | 1 |
| Redirects | `Response::redirect()` (CWE-601 guards, 301/302/303/307/308) | `/go`, post-login, directory slash | 1, 12 |
| Request input | `$_GET/$_POST/$_FILES/$_COOKIE` · `$g->*` · `php://input` re-readable · `getallheaders()` · `filter_input` | filters, forms, avatar upload, inspector | 2 |
| `$_SERVER`/SAPI | `buildServerVars` surface · auth meta-vars · SAPI lifecycle shims (`connection_aborted`…) | request context, basic-auth API, log-tail disconnect | 2, 7 |
| PSR-7/17/15/18/16/3/11 | `LazyServerRequest` · `HTTP\Factory\*` · middleware pipeline · `HTTP\Client` · `SimpleCacheAdapter` · `Logger` | standards-clean integration everywhere | 2, 5, 8, 11, 14 |
| Static + conditional | native static handler + `Response::sendFile()` (weak ETag/Range/multipart/If-*) + `MimeResolver` | assets, report/CSV download | 3 |
| Sessions | `session_*` overrides · `$_SESSION`/`$g->session` · 5 handlers · strict mode · GC | login, per-user prefs, flash | 4 |
| Middleware | global + `middlewareAlias` + `group` + `when` + per-route + api in-file `$middleware` — **the full built-in set (§7.6)** | the entire ops/security layer | 5, 12 |
| Routing | `route`/`nsRoute`/`nsPathRoute`/`patternRoute`/`group` · params · fallback · error handlers · `describeRoutes` · hot-reload | the whole URL map + `/routes` + custom error pages | 6 |
| Streaming/SSE/WS | Generator · `stream()` · `sse()` · `ws()` · `WSRouter`+`Room` production surface | live feed, metrics stream, incident rooms, log tail | 7 |
| Shared state | `Store` (full op set, 5 backends) · `Counter` (3 backends) · `Cache`+tags | metrics engine, counters, aggregates | 8 |
| SQL system of record | `Db\DbConnectionPool` (`pdo`/`mysqli`/`with`/`transaction`/`stats`) | users, incidents, rules, targets, report metadata | 8 |
| MongoDB document layer | `zealphp/mongodb`: `Client`/`Database`/`Collection` (full CRUD+aggregation+indexes+bulk), sessions/transactions, **change streams**, **GridFS**, full BSON type set, coroutine-async | event firehose, rollups, live push, artifact vault | 8 (+3, 7, 11, 13) |
| Cross-worker messaging | `Store::publish`/`App::subscribe` (fire-and-forget) · `publishReliable`/`subscribeReliable` (streams) | alert fan-out + audit trail | 8 |
| Lifecycle modes | `App::mode()`/`isolation()` + every isolation knob + preload tiers | `/modes` lab + run matrix | 9 |
| CGI dispatch | `App::include()` · `cgiMode(pool/proc/fork/fcgi)` · custom backends · ScriptAlias | legacy bay | 10 |
| Concurrency & background | `tick`/`after` · `task()` · `parallel`/`parallelLimit` · `go` · `onSignal` · `addProcess` · `HTTP` client · `exec`/`rawExec` · `coproc` | aggregation, reports, prober, sidecar, graceful stop | 11 |
| Security | CSRF · rate-limit · IP ACL · body-size · concurrency cap · XFF trust · WS HMAC · unserialize whitelist | hardening across the app | 12 |
| Templates/htmx | `render`/`renderToString`/`renderStream`/`renderHtmx`/`fragment` · `HtmxResponse` builder · htmx request accessors | the UI | 13 |
| Infra | CLI (all sub-commands/flags) · `Logger` · `PhpInfo` · `App::stats` · access-log format · env knobs · worker recycle | ops surface + deployment | 14 |

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

**Standing rule for every later phase:** a *known* divergence that surfaces again is tolerated per this table; a
*new* divergence goes to the phase's verification sweep (the `../` workflow), never silently patched around.

---

## 4. Per-phase build plan — inch by inch

> For each phase: **Feature** (what ZealPulse gains), **APIs**, **Batches** (the phase.md B-list to confirm live),
> **Done** (acceptance), **Status**. Build a phase → run it across the relevant modes → confirm each batch on the
> wire → tick the boxes → update Status + the §7 appendix rows it lights up → commit
> `zealpulse: Phase N — <feature>` → next phase.

### Phase 1 — HTTP response & headers · `route/phase1.php` · **Status: ✅ DONE (coroutine, v0.4.8)**
- **Feature:** dashboard shell + response core (security headers, status playground, prefs cookie, safe redirect, streamed feed, health).
- **APIs:** route return contract, `header()` multi-append, `setcookie()`, `Response::redirect()`, Generator stream.
- **Batches confirmed live:** ☑ B1 status (418/451 ok, 999→500, 204) · ☑ B2 header family (X-ZealPulse ×2, two `Link`) · ☑ B3 cookie (zp_theme; Max-Age/SameSite) · ☑ B4 framing (204 len 0; HEAD strips body, keeps CL) · ☑ B5 redirect (302 + offsite→`/` guard) · ☑ B6 charset/CT (array→`application/json`; text charset explicit) · ☑ B7 contract (array/string/int/Generator) · ☑ B8 re-verify (known #290/#354 respected, not re-filed).
- **Done:** all endpoints green in coroutine; mixed deferred for the generator route (#354).
- **Full-capacity TODO (fold into later phases, tick in §7):** two-arg `$response->status($code,$reason)` (P6 error pages) · `setrawcookie()` (P4 session-adjacent raw cookie) · `header_register_callback()` late header (P14 ops, not legacy-cgi #357) · `http_response_code()` get-form (P6) · `headers_list()`/`headers_sent()`/`header_remove()` in the `/whoami` inspector (P2 follow-up) · `$response->end()` + `flush()` explicit paths (P7).

### Phase 2 — Request input & SAPI · `route/phase2.php` + `api/events.php` + `src/Req.php` · **Status: ✅ DONE (coroutine + mixed, v0.4.8)**
- **Feature:** the input layer — `/search` metrics filter (GET), `/events/submit` (POST urlencoded + JSON), `/upload` avatar (`$_FILES`), `/whoami` request inspector, `/admin/probe` Basic-auth gate, `api/events` file-API (GET/POST).
- **APIs:** `$g->get/post/cookie/server` (mode-portable), `$_GET/$_POST/$_FILES/$_COOKIE`, `php://input`, `is_uploaded_file()`, `getallheaders()`, Basic-auth meta-vars, ZealAPI files.
- **Batches confirmed live:** ☑ B1 `$_GET` (route/min/`tags[]` array, both modes) · ☑ B2 `$_POST`+`php://input` (form + JSON, raw_len) · ☑ B3 `$_FILES` (single upload, `is_uploaded_file` true, forged `/etc/passwd`→false) · ☑ B4 `$_COOKIE` (zp_theme+sid parsed) · ☑ B5 `$_REQUEST` avoided — read `$_GET`/`$_POST` explicitly (#356) · ☑ B6 `$_SERVER` (port string-coerced, `SERVER_ADDR` absent confirmed #306) · ☑ B7 Basic-auth (`PHP_AUTH_USER`=ops decoded; 401 gate) · ☑ B8 `getallheaders()` (Authorization etc.) · ☑ B9 file-API/RequestInput path · ☑ B10 `G` aliasing + superglobal n/a in coroutine (`$_GET` populated=True mixed / False coroutine) · ☑ B11 re-verify (known issues respected).
- **Done:** forms/upload/auth/API all green in mixed; `$g->*` portable in coroutine; superglobal n/a in coroutine confirmed.
- **Full-capacity TODO:** `filter_input()`/`filter_input_array()` in `/search` validation (uopz overrides → `$g` bags) · `move_uploaded_file()` persisting the avatar · multi-file + nested `$_FILES` array upload (#304 layout) · PSR-7 path: read the request once via `$g->psr_request` (`LazyServerRequest` fast-path + hydration) in the api layer · `php://input` re-read ×2 proof endpoint.

### Phase 3 — Static files & conditional GET · `route/phase3.php` + `src/Reports.php` · **Status: ✅ DONE (feature, coroutine v0.4.8) · verification sweep filed the 3 candidates**
- **Feature:** `/download/{name}` report download via `Response::sendFile()`; `/reports` index; real `public/robots.txt` + `favicon.ico` (native static handler).
- **APIs:** `Response::sendFile()` (weak ETag, Last-Modified, MIME, Range, conditional GET), native static handler.
- **Batches confirmed live:** ☑ B1 sendFile core (text/csv MIME, `W/"mtime-size"` ETag, Last-Modified, Accept-Ranges, Content-Disposition, CL) · ☑ B2 conditional GET (If-None-Match→304) · ☑ B3 Range (bytes=0-9→206 len 10) · ☑ B4 native handler (robots.txt: Last-Modified only, no ETag/Range) · ☑ B5 dir/normalization (/reports) · ☑ B6 charset/CT · ☑ B7 re-verify (closed Phase-3 issues hold).
- **Verification sweep:** the 3 candidates CONFIRMED live + FILED — HEAD body-leak (`fw-sendfile-head-body`), `.well-known` unservable (`fw-wellknown-includecheck-block`), Content-Disposition RFC 6266 (`fw-sendfile-content-disposition-rfc6266`). ZealPulse uses ASCII download names (disposition gap doesn't bite) and respects the others.
- **Done:** download + conditional + Range green; native-handler behaviour confirmed; parity sweep filed.
- **Full-capacity follow-up (P8 forward-ref):** once GridFS lands, the download center grows a second source — vault artifacts streamed via `openDownloadStream` → `$response->stream()` (chunked, no temp file) alongside the disk path's `sendFile()`; diff + record the two paths' header surfaces, and add the multipart-range + If-Range resume probes the first pass skipped.

### Phase 4 — Sessions & identity · `route/phase4.php` + `src/Auth.php` · **Status: ✅ DONE (coroutine, v0.4.8)**
- **Feature:** login/logout with fixation-safe session rotation, per-user dashboard prefs (theme/layout/refresh-rate), flash messages, a **session-handler switchboard** (env `ZEAL_SESSION_HANDLER=file|table|store|redis|memory`) so the same login works on every backend, an `/admin/sessions` live-session viewer.
- **APIs:** `session_start()`/`session_id()`/`session_regenerate_id(true)`/`session_destroy()`/`session_write_close()`/`session_name()`/`session_status()`/`session_unset()`/`session_abort()`/`session_commit()`, `session_set_cookie_params()`/`session_get_cookie_params()`, `session_create_id()`, `session_encode()`/`session_decode()` (the prefs export trick), `$_SESSION` ⇄ `$g->session` alias, `App::sessionLifecycle()`, `App::sessionHandler()` + `FileSessionHandler` (default) / `TableSessionHandler::register()` (cross-worker shared) / `StoreSessionHandler::register()` / `RedisSessionHandler` (when `ZEALPHP_REDIS_URL`) / `CoroutineMemorySessionHandler` (per-worker demo), `App::sessionTtl()`/`sessionMaxRows()`/`sessionDataSize()`/`sessionSavePath()`/`sessionStrictMode(true)`, `SessionStartMiddleware`, `ZEALPHP_SESSION_SECURE`/`ZEALPHP_SESSION_GC_INTERVAL`.
- **Batches:** ☑ B1 create/read/write/destroy (login persists across requests/workers) · ☑ B2 cookie attributes (`Set-Cookie ...; httponly; samesite=Lax` on first visit) · ☑ B3 regenerate-on-login (sid 26→64 chars on auth — fixation closed by the app's own `session_regenerate_id(true)`) + strict mode on · ☑ B4 handler switchboard (file + table live; redis n/a — no ext-redis) · ☑ B5 prefs persist (`session_encode`/`decode` whitelist) · ☑ B6 last-write recorded · ☑ B7 GC source-confirmed · ☑ B8 re-verify.
- **Done (live §9b re-validation 2026-06-11, env up):** `ops/pulse` → `authenticated:true`; identity + prefs persist across requests; **fixation-safe rotation on login** (26→64-char id); admin RBAC (`/admin/sessions` ops→200, viewer→403); logout destroys.
- **Known framework-bug interactions surfaced (all filed in zealphp-exp):** **#373** confirmed live — `App::sessionSavePath($sessDir)` (app.php:73) is **ignored**; sessions land in the hardcoded `/var/lib/php/sessions`, not the configured dir. The app is **not** exposed to **#371** (session fixation) for auth because `/login` does an explicit `session_regenerate_id(true)` on success (the app half of the two-half contract), minting a fresh app-controlled id. Avoid `session_abort()` here (**#372** wipes the session) and the positional `session_set_cookie_params()` form (**#375** drops HttpOnly).

### Phase 5 — Middleware suite (the FULL built-in band) · `app.php` registrations + `route/phase5.php` + `src/Middleware/` · **Status: ✅ DONE (coroutine, fresh clone HEAD 4322076, 2026-06-12)**
- **Live-validated (54 routes):** global band on EVERY response (`X-Request-Id` · `charset=utf-8` · `X-Content-Type-Options:nosniff` · `X-Frame-Options:DENY` · `Referrer-Policy`) · `//healthz`→200 (MergeSlashes) · `App::when('/api')` CORS preflight→204 · `App::when('/assets-dl')` → `Cache-Control: max-age=2628000, public` + weak ETag + `Accept-Ranges` · admin group `/admin/panel`→**401** (session-auth + admin-ip) · `/_info`→**401** (BasicAuth) · `/teapot`→**418** (Return) · `/about` BodyRewrite footer stamp · `/feedback` CSRF token form · `/healthz`→200 (HealthCheck). Aliases (throttle/trace/session-auth/admin-ip/csrf/referer-gate/reports-gate/upload-cap) factory-once-shared.
- **Feature:** the ops/security layer mounted for real — **every built-in middleware ZealPHP ships is used somewhere sensible** (global, `when()` path-scope, route group, per-route, or api in-file). Plus a `/middleware` introspection page rendering `App::describeRoutes()` (`{global, aliases, when, routes}`).
- **APIs & placement plan (32 built-ins — each gets ONE real home):**
  - **Global stack (first-registered = outermost):** `RequestIdMiddleware` (X-Request-Id assign/echo) → `MergeSlashesMiddleware` → `CharsetMiddleware` (closes the HELD charset gap) → `SetEnvIfMiddleware` (tag `ZP_BOT` from User-Agent) → `HeaderMiddleware` (security headers: X-Content-Type-Options/X-Frame-Options/Referrer-Policy) → `SessionStartMiddleware`.
  - **`App::when()` path scopes:** `/api` → `CorsMiddleware` (`ZEALPHP_CORS_ORIGINS`) + `RateLimitMiddleware`; `/assets-dl` → `RangeMiddleware` + `ETagMiddleware` + `CacheControlMiddleware` + `ExpiresMiddleware` + `MimeTypeMiddleware` + `ContentEncodingMiddleware` + `ContentLanguageMiddleware`; `/legacy` → `IniIsolationMiddleware` + `BlockPhpExtMiddleware`.
  - **Admin group (`$app->group('/admin', [...])`):** session-auth alias (`src/` auth middleware over `App::authChecker`) + `IpAccessMiddleware` (loopback + `ZP_ADMIN_CIDR`) + `RefererMiddleware` (form posts) + `ConcurrencyLimitMiddleware` (reports) + `BodySizeLimitMiddleware` (uploads).
  - **Per-route:** `BasicAuthMiddleware` (htpasswd file, APR1) on `/_info` · `CsrfMiddleware` on every form route · `ReturnMiddleware` on `/teapot` (418) · `RedirectMiddleware` legacy URL map (`/old-dash`→`/`) · `LocationHeaderMiddleware` behind-proxy port rewrite demo · `HostRouterMiddleware` vhost demo (`ops.localhost` → alt handler) · `BodyRewriteMiddleware` footer-stamp on `/about` · `RequestHeaderMiddleware` (inject `X-ZP-Tier: admin` for downstream) · `ScopedMiddleware::location()` wrapper demo · `HealthCheckMiddleware` (`/healthz` `/readyz`).
  - **Deliberately NOT mounted:** `CompressionMiddleware` (server-level `http_compression` already on — double-compress hazard, documented).
  - **Mechanics:** `App::middlewareAlias()` registry (incl. parameterized `'throttle:120'` form), per-route `middleware:`, group nesting, api in-file `$middleware`, stateless-middleware rule (per-request state in `$g`, never on the instance).
- **Batches:** ☐ B1 global order on the wire (Request-Id present on EVERY response incl. errors) · ☐ B2 group nesting + per-route compose (admin onion order verified) · ☐ B3 `when()` scopes fire only on their paths; OPTIONS preflight never gated · ☐ B4 short-circuit (403 from IpAccess, 401 from BasicAuth challenge, CSRF reject) preserves the response shape · ☐ B5 every mounted middleware probed individually (one curl proof each — 22+ probes) · ☐ B6 alias/parameterized factory runs once (shared instance — statelessness probed under burst) · ☐ B7 `/middleware` page renders `describeRoutes()` truthfully · ☐ B8 re-verify.
- **Done:** every built-in middleware demonstrably alive at its mount point; admin gated; API rate-limited + CORS'd; forms CSRF'd.

### Phase 6 — Routing & dispatch · `route/phase6.php` + `src/Routing.php` + `api/probe/{check,halt}.php` · **Status: ✅ DONE (coroutine, v0.4.8, 2026-06-12)**
- **Live-validated (69 routes):** every registrar kind resolves — `route('/p6/user/{id}')` · `nsRoute('admin','/dashboard')` · `nsPathRoute('docs','/{section}/{path}')` greedy tail · `patternRoute('#^/p6/files/(?P<path>.+)$#')` · `group('/team')` + nested `group('/ops')`. Param injection by name + defaults; **#240 reserved-name proof** (`/p6/shadow/{request}` injects the `Request` wrapper, never the URL value). Method semantics: `OPTIONS /p6/methods`→**204 + `Allow: OPTIONS, GET, HEAD, POST`**, HEAD→200, wrong method→**405 + Allow**, `PURGE`→**501**. Custom **404** (JSON `did_you_mean` + branded HTML, status 404) and **500** (negotiated, **no trace leak** — `displayErrors(false)`). `/p6/routes` truthful `describeRoutes()` map. ZealAPI deep pass `/api/probe/check` (per-method, fail-closed `requirePostAuth`→403, in-file `$middleware` trace). **HaltException clean halt** at `/api/probe/halt`→200 buffered body, worker survives. **Hot-reload:** under `ZEALPHP_DEV=1`, adding `route/_p6hot.php` made `/p6/hot` go 404→live with no restart; a route file with a top-level function was **refused** (its route never registered, the live table stayed intact).
- **Build findings (filed upstream, issues-only):** (1) on the released **v0.4.8** a bare `throw new HaltException()` from a plain `route()` closure surfaces as **500** — the clean-halt catch lives in the ZealAPI + template/include paths on 0.4.8 (route-level handling is post-0.4.8, present on HEAD 4322076 which returns a clean 200). **Filed [#414](https://github.com/sibidharan/zealphp/issues/414)** (fixed on main, unreleased). ZealPulse demonstrates HaltException via the ZealAPI path and halts routes via the return contract. (2) `HEAD` on any ZealAPI file → **406** (upstream **#411**, `REST::inputs` has no HEAD case) — GET/POST unaffected. (3) `App::$display_errors` defaults **true** (upstream **#412**) — ZealPulse sets `displayErrors(false)` for secure-by-default. (4) an `int` return is status-only (discards the buffered echo) — routes return the body value, not `echo`+`return <int>`.
- **Feature:** the full URL map — path params everywhere, an `admin` namespace, pattern catch-alls, a custom 404/500 experience (HTML + JSON negotiated), `/routes` introspection, and the **dev hot-reload story**.
- **APIs:** `route()` (params + defaults + `methods:` + `raw:`), `nsRoute()`, `nsPathRoute()`, `patternRoute()`, `group()` (nested), `setFallback()` (pretty 404 + suggestion), `setErrorHandler($status,$h)` + catch-all `setErrorHandler($h)` (param-injected `$status/$exception/$request/$response`), `renderError()`, `HaltException` (clean halt endpoint — worker survives), `App::describeRoutes()`, `App::devReload()`/`ZEALPHP_DEV` + `reloadRoutes()` (edit a route file live; top-level-function refusal respected), reserved-name param rule (#240: `request`/`response`/`app` bind injected objects), `App::apiNullNotFound()` + `apiWarnCollisions()`, ZealAPI auth hooks `App::authChecker()`/`adminChecker()`/`usernameProvider()` + `$this->isAuthenticated()/isAdmin()/getUsername()/requirePostAuth()/paramsExists()/json()/die()` in api files, `App::traceEnabled()` (TRACE 405 default), `X-HTTP-Method-Override` (POST only).
- **Batches:** ☑ B1 registrar kinds all resolve (route/ns/nsPath/pattern/group/implicit-public) in priority order · ☑ B2 param injection by name + defaults + reserved names (#240) · ☑ B3 method semantics (405+Allow, OPTIONS 204+Allow, HEAD auto, 501; TRACE gated — engine-unreachable per #413) · ☑ B4 fallback + error handlers (404 page w/ did-you-mean, 500 page no-leak, JSON negotiation via Accept) · ☑ B5 ZealAPI deep pass (per-method files, auth hooks fail-closed, `requirePostAuth`→403, in-file middleware; HEAD→406 is upstream #411) · ☑ B6 `HaltException` clean halt via the ZealAPI path (body preserved, worker survives; route-path version gap recorded) · ☑ B7 `/p6/routes` truthful `describeRoutes()` · ☑ B8 devReload live edit cycle (add route → appears; top-level-function file → refused, table intact) · ☑ B9 re-verify (all green; 4 build findings recorded above).
- **Done:** all route kinds + custom error pages + introspection live; hot-reload demonstrated.

### Phase 7 — Streaming / SSE / WebSocket / Rooms · `route/phase7.php` + `src/EventBus.php` + `src/Incidents.php` + `src/LogTail.php` · **Status: ◻️ planned**
- **Feature:** the **live core** — (a) WS `/live` event feed broadcasting to all viewers; (b) **incident rooms** `/incident/{id}` with presence, member list, capacity and auth via the full `WSRouter`/`Room` production surface; (c) SSE `/stream/metrics` ticking the board; (d) `/stream/logs` live log tail that STOPS when the browser disconnects; (e) generator SSR initial board; (f) the **Mongo change-stream bridge** — a worker-resident coroutine `watch()`es the events collection and pushes every new document into the WS feed (the no-poll live board; replica-set gated, falls back to pub/sub fan-out from P8 when standalone).
- **APIs:** `$app->ws($path, onMessage:, onOpen:, onClose:)` (frame lifecycle; PING/PONG auto), `$server->push()/isEstablished()/disconnect()/getClientList()`, **WSRouter**: `init()`/`initOptions()` (capacity, GC), `own()`/`ownAuthenticated()`/`release()`, `sendToClient()`, `broadcast()`, `onRoom()`, `room()` → **`WS\Room`**: `join()`/`leave()`/`push()`/`size()`/`members()`/`membersPaged()`/`onMessage()`/`onPresence()` + `CapacityException`, auth: `sessionPrincipal()`/`roomAuthorizer()`/`authorizeRoom()`/`requireRoomAuth()` + `WSAuthException` + `setChannelHmacSecret()`/`signPayload()`/`verifyPayload()` (`ZEALPHP_WS_HMAC`), limits: `setClientRateLimit()`/`setRoomRateLimit()`/`setFanoutConcurrency()` + backpressure (`pushWithBackpressure`/bounded fan-out), presence: `onlineCount()`/`onlineByServer()`/`stats()`, federation: Redis-backed multi-instance rooms (start two ZealPulse instances → same room) + `runStaleServerGC`; **SSE**: `$response->sse($emit)` (named events, retry, heartbeat); **stream**: `$response->stream($write)` + `connection_aborted()`/`connection_status()` disconnect detection + `ignore_user_abort()` contract; Generator SSR + `yield from` composition; HEAD-strip on streaming (#238 class).
- **Batches:** ☐ B1 WS open/message/broadcast/close (two browsers see each other live) · ☐ B2 rooms: join/leave/presence/members/capacity-reject/unauthorized-reject (HMAC + authorizer) · ☐ B3 room rate-limit + backpressure under a slow consumer (no worker stall) · ☐ B4 federation: 2 instances share a room via Redis (skip-record if no Redis) · ☐ B5 SSE: named events + reconnect (`Last-Event-ID` handling recorded) + heartbeat · ☐ B6 log tail stops on client disconnect (`connection_aborted()` flips ≤1 heartbeat after close) · ☐ B7 generator SSR board streams progressively; HEAD on every streaming endpoint → headers only · ☐ B8 `onlineCount`/`stats` truthful under churn · ☐ B9 change-stream bridge: an `insertOne` from a DIFFERENT worker/process appears in every connected browser ≤1s (resume token survives a bridge restart); standalone → pub/sub fallback proven instead (skip-record the stream) · ☐ B10 re-verify.
- **Run:** coroutine (streaming correct); document mixed #354.
- **Done:** two browsers chat in an incident room with live presence; metrics tick; log tail follows and dies with the tab.

### Phase 8 — Store / Counter / Cache / SQL / MongoDB / Messaging · `app.php` tables + `src/Metrics.php` + `src/Alerts.php` + `src/Sql.php` + `src/Mongo.php` + `src/Firehose.php` + `src/Vault.php` · **Status: ◻️ planned**
- **Feature:** the **data spine** — per-route counters and a rolling event ring in `Store`, atomic totals/online in `Counter`, cached aggregates with tag invalidation, the **SQL system of record** (users/incidents/rules/targets/report-metadata through the connection pool), the **MongoDB firehose + analytics layer** (every event a document; aggregation-pipeline rollups power the board; GridFS artifact vault; change-stream live push), alert fan-out via pub/sub, and a restart-surviving alert **audit trail** via reliable streams. The §1.1 dual-database contract is enforced here.
- **APIs — shared memory:** **Store** (made BEFORE `run()`): `make()` schema (string/int/float typed cols), `set/get/getStrict/del/exists/incr/decr/count/names/clear`, `mget/mset` (batch board read), `iterate/iteratePaged` (event ring scan), set-ops `sadd/srem/scard/sscanCursor/sdel` (per-route active-viewer sets), `compareAndSet` (config flips), `evalScript` (Redis-only — skip-record on Table), `ping`/`stats`; backends: `TableBackend` (default) / `RedisBackend` / `MemcachedBackend` / `TieredBackend` (`ZEALPHP_STORE_BACKEND=tiered` + `ZEALPHP_TIERED_INVALIDATION_SECRET` HMAC invalidation) / `CircuitBreakerBackend` (primary-Redis-down → Table fallback, `state()` surfaced on `/_metrics`); `Store::tieredAdvisory`/`tieredBootChecks`. **Counter**: `increment/decrement/get/set/reset`, `compareAndSet`, `incrementBounded` (online cap), `expire` (Redis TTL — skip-record on Atomic), `mincr` (multi-key tick), backends Atomic/Redis/Memcached (`ZEALPHP_REDIS_PREFER`). **Cache**: `init`, `getOrCompute` (stampede-guarded aggregate), `set/get/del/has/count/mget/mset`, `invalidateTag` (tag `route-metrics`), `flush/clear`, `stats`; `Cache\SimpleCacheAdapter` consumed through a PSR-16-typed service.
- **APIs — SQL (system of record, `src/Sql.php`):** `Db\DbConnectionPool` (`pdo()` factory + `mysqli()` alt-driver probe, `with()` checkout auto-return, `transaction()` for every multi-row write — incident create = incident row + first timeline ref + rule link in ONE transaction, `stats()`/`size()` on `/_metrics`, pool exhaustion behaviour under parallel load recorded, graceful absence when `ZP_DB_DSN` unset); schema: `users` / `incidents` / `alert_rules` / `probe_targets` / `reports`.
- **APIs — MongoDB (firehose + analytics + vault, `src/Mongo.php`/`Firehose.php`/`Vault.php`, `zealphp/mongodb`):** `Client` (per-worker singleton in `onWorkerStart`; Rust ext pools connections; `listDatabases`/`getPoolId` on the `/modes` lab), `Database` (`command` ping, `createCollection`, `listCollectionNames`, `aggregate` db-level, `withOptions`), **`Collection` — the full op set used for real**: `insertOne` (event emit, fire-and-forget via `App::go`) · `insertMany` (probe-round batch) · `find`/`findOne` (feed scans, cursors iterated lazily) · `updateOne`/`updateMany` (event tagging) · `replaceOne` · `deleteOne`/`deleteMany` (prune) · `findOneAndUpdate` (claim-next-report-job pattern) · `findOneAndDelete`/`findOneAndReplace` · `countDocuments`/`estimatedDocumentCount` · `distinct` (filter dropdowns) · **`aggregate`** ($match/$group/$sort/$bucket pipeline → the per-route/per-minute rollups that power the board) · `bulkWrite` (mixed upsert batch from the aggregation tick) · `createIndex`/`createIndexes`/`listIndexes`/`dropIndex(es)` (TTL index on events = self-pruning firehose; compound route+ts index) · `watch` (→ P7 live bridge) · `withOptions`/read-write concerns/`getTypeMap`; **BSON types used naturally**: `ObjectId` (event ids), `UTCDateTime` (all timestamps), `Regex` (feed search), `Binary` (probe payload sample), `Decimal128` (latency precision), `Document::fromPHP/toPHP`; **sessions/transactions**: `startSession` + `startTransaction`/`commitTransaction`/`abortTransaction` + `['session'=>$s]` (multi-doc incident-merge — **replica set required: skip-record on standalone with the thrown proof**); **change streams**: `$collection->watch()` resume tokens + `fullDocument: updateLookup` (replica-set gated, P7); **GridFS** (`Vault.php`): `selectGridFSBucket()` + `uploadFromStream`/`openUploadStream` (report artifacts from the P11 task), `openDownloadStream`/`downloadToStreamByName` (revisions), `rename`/`delete`/`drop`/`find` (vault admin page); **async bridge**: `AsyncBridge::isCoroutineMode()` — coroutine mode = non-blocking eventfd path (prove: a slow `aggregate` does NOT block a concurrent fast request), mixed/sync mode = `block_on` path (works, blocking recorded); not-implemented surface (`withTransaction` helper, sessions-on-watch, mapReduce) **throws `RuntimeException`** — probe one and record.
- **APIs — messaging:** `Store::publish()` + `App::subscribe()`/`onPubSub()`/`offPubSub()`/`unsubscribe()` (alert fan-out to every worker); `Store::publishReliable()` + `App::subscribeReliable()`/`onReliableMessage()` (consumer-group audit trail; pending-reclaim observed); `App::backpressureBootAdvisory()`/`redisBootChecks()` respected at boot.
- **Batches:** ☐ B1 Store CRUD + typed schema + cross-worker visibility (`-w 4`: write on one worker, read on another) · ☐ B2 batch + iteration (mget/mset board read; iteratePaged ring scan; set-ops viewer sets) · ☐ B3 Counter atomicity under a 200-request burst (exact total, no lost increments) + bounded/mincr/expire semantics · ☐ B4 Cache getOrCompute single-flight + TTL + tag invalidation · ☐ B5 backend matrix: same metrics page green on Table / Redis / Tiered / CircuitBreaker(+forced-open) — skip-record absent backends · ☐ B6 **SQL**: schema migrate on boot; incident create/update in ONE `transaction()` (rollback on forced failure leaves zero partial rows); `with()` returns connections under parallel load; pool `stats()` sane; `mysqli()` driver probe · ☐ B7 **Mongo CRUD+index**: firehose emit→find→update→delete round-trip; TTL index self-prunes; compound index used by the feed query (explain via `command`); BSON types round-trip (`ObjectId`/`UTCDateTime`/`Regex`/`Decimal128`) · ☐ B8 **Mongo analytics**: aggregation rollup matches a hand-computed control; `distinct` filters; `bulkWrite` upsert batch; cursor laziness (no full materialization on a 10k scan) · ☐ B9 **Mongo async proof**: under coroutine mode a deliberately-slow aggregate + a fast findOne run CONCURRENTLY (fast one returns first — non-blocking eventfd path); same pair in mixed = sequential (block_on recorded); 4-parallel `Channel`+`go()` board read faster than sequential (the README's 3.4× pattern reproduced) · ☐ B10 **Mongo transactions + change streams**: replica set present → multi-doc transaction commits/aborts atomically + watch() resumes from a token; standalone → both throw, skip-recorded with proof · ☐ B11 **GridFS vault**: report artifact upload→download byte-identical; by-name revisions; delete/rename; vault page lists via `find` · ☐ B12 **dual-DB contract**: the §1.1 rule audited — no fact in both stores; cross-refs resolve both directions; SQL-commit-then-Mongo-write ordering held under a forced mid-write crash (pruner retry path observed) · ☐ B13 pub/sub: alert published on worker A handled on ALL workers · ☐ B14 reliable streams: alert audit survives a restart; consumer group resumes; reclaim observed · ☐ B15 re-verify.
- **Done:** metrics exact under burst; SQL transactions atomic; Mongo firehose + rollups + vault live with the async proof recorded; alerts reach every worker; audit trail survives restart; backend switchboard green.

### Phase 9 — Lifecycle modes & isolation lab · `route/phase9.php` · **Status: ◻️ planned**
- **Feature:** the `/modes` lab — live mode matrix (which mode am I in, what's populated, which manager/dispatch path), an **isolation burst probe** (N concurrent requests each writing a unique value → zero cross-talk), a per-coroutine **process-state demo** (per-request timezone/locale/cwd that never leaks), and the boot-time preload story.
- **APIs:** `App::mode()` (4 presets) / `App::isolation()` (enum + string) / `App::superglobals()` / `enableCoroutine()` / `processIsolation()` / `hookAll()`, `Isolation::coerce()/isProcess()/cgiMode()`, boot guard `validateLifecycleCombination` behaviour (refused combos refuse), per-coroutine `$g`/`RequestContext` isolation (incl. v0.4.8 truthful `isset($g->x)`/`unset($g->x)`), coroutine-legacy knob set when ext-zealphp present: `silentRedeclare`/`includeIsolation`/`coroutineGlobalsIsolation`/`coroutineStaticsIsolation`/`functionIsolation`/`defineIsolation`/`keepGlobals`/`refreshGlobalsBaseline`/`globalScopeInclude`/`perRequestStateResetsActive`, the **six process-state knobs** `coroutineCwdIsolation`/`coroutineLocaleIsolation`/`coroutineUmaskIsolation`/`coroutineTimezoneIsolation`/`coroutineMbencIsolation`/`coroutineLibxmlIsolation` (demo: per-request `date_default_timezone_set` + `setlocale` under burst — zero leak), `ini_set`/`putenv`/`getenv` request-overlay isolation, preload tiers `App::preloadClasses()/preloadClassmap()/preloadDir()` (warm the hot WS/SSE classes at boot), `opcacheLegacyBootCheck()` advisory surfaced on `/modes`.
- **Batches:** ☐ B1 mode matrix page truthful in all 4 modes (superglobal population, session manager, dispatch path per mode) · ☐ B2 isolation burst: 40 concurrent × unique value → 0 leaks (coroutine + coroutine-legacy) · ☐ B3 process-state demo: request A sets TZ/locale, concurrent request B unaffected, next request clean (ext present; else skip-record with the OFF-state leak proven) · ☐ B4 refused combos refuse at boot with the documented message · ☐ B5 preload: cold-start first-burst clean (no cold-autoload 500s on the warmed classes) · ☐ B6 ini/putenv overlay isolation under burst · ☐ B7 re-verify.
- **Done:** the lab renders the matrix live; burst probes prove zero leak; graceful degradation without ext-zealphp recorded.

### Phase 10 — CGI dispatch & the legacy bay · `route/phase10.php` + `public/legacy/` + `scripts/cgi/` · **Status: ◻️ planned**
- **Feature:** the **legacy bay** — a stock mod_php-era guestbook script (`public/legacy/guestbook.php`, superglobals + `echo`, zero framework code) running unmodified via `App::include()`; a **non-PHP CGI** (`scripts/cgi/status.sh` via ScriptAlias) reporting host status RFC-3875-style; a strategy switchboard exercising **all four** CGI modes.
- **APIs:** `App::include('/legacy/guestbook.php')` (in-process in coroutine modes, subprocess in legacy-cgi), `setFallback` PHP_SELF/SCRIPT_NAME preamble pattern, `App::cgiMode('pool'|'proc'|'fork'|'fcgi')`, pool tuning `cgiPoolSize()`/`cgiPoolMaxRequests()`/`cgiTimeout()`/`cgiPoolEnvAllowlist()`/`cgiSubprocessAutoload()`/`cgiForkMaxConcurrent()`/`fcgiAddress()`, custom backends: `registerCgiBackend()` + `cgiScriptAlias('/cgi-bin/', 'scripts/cgi/')` + `cgiBackendAlias()` + per-route `backend:` + `resetCgiBackends()`, RFC 3875 env (`buildCgiEnv`), `Legacy\ApacheContext` shims, sessions across the CGI boundary, `php://input` bridge + upload bridge in the subprocess.
- **Batches:** ☐ B1 guestbook works in ALL FOUR strategies + in-process modes (pool/proc/fork/fcgi switchboard; respect the known fcgi #289-class hangs — skip-record per ledger) · ☐ B2 return contract across the process boundary (status/JSON/HTML from the included script) · ☐ B3 `status.sh` via ScriptAlias: RFC 3875 env present, `Status:` header threads back · ☐ B4 env isolation: request putenv overlay NOT in subprocess env; `cgiPoolEnvAllowlist` honoured; `HTTP_PROXY` never leaks (httpoxy) · ☐ B5 session continuity: login session readable inside the CGI script · ☐ B6 pool recycle: `cgiPoolMaxRequests` rotation observed without dropped requests · ☐ B7 re-verify.
- **Done:** legacy code + a shell CGI run as first-class citizens; strategy matrix recorded.

### Phase 11 — Timers / Tasks / Signals / Sidecars / Outbound · `app.php` lifecycle hooks + `task/` + `src/Prober.php` · **Status: ◻️ planned**
- **Feature:** the **background machine** — per-worker metric aggregation (`tick`), a boot warmup (`after`), heavy report builds on task workers, the **uptime prober** (concurrent HTTP checks of configured targets), a data-pruning **sidecar**, graceful-stop signal handling, an ops shell probe, and `App::stats` feeding `/_metrics`.
- **APIs:** `App::tick(5000, fn)` (aggregate Store→Cache per worker) / `App::after()` / `App::clearTimer()`, `App::onWorkerStart()`/`onWorkerStop()` (recycle-aware state flush; `ZEALPHP_MAX_REQUEST` recycle observed), task workers: `$server->task()` + `task/*.php` handlers + `dispatchTaskCallback` dual-arity + `adoptRequestContext()` (request context inside the task), `App::go()` (fire-and-forget coroutine), `App::parallel([...])` (input-order results, first-exception) + `App::parallelLimit($n)` (bounded prober fan-out), **outbound HTTP**: `HTTP::get/post/put/delete/request` + `HTTP::all([...])` (concurrent probe round) + `HTTPResponse` (`ok()/failed()/json()/status/headers` — transport failure ≠ HTTP error), PSR-18 `HTTP\Client::sendRequest()` (one standards-path probe) + `NetworkException`/`RequestException`, signals: `App::onSignal(SIGTERM, fn)` graceful drain + `SIGUSR1` stats dump (record the known workerOnly dead-path divergence — ledger), sidecar: `App::addProcess('zp-pruner', fn, workers: 1)` (hooked-I/O loop pruning old events; visible as `zealphp:zp-pruner` in `ps`), shell: `App::exec()` (coroutine-yielding `df`/`uptime` probe on `/admin/host`) vs `App::rawExec()` (blocking escape hatch) + the `hookExec` shim family (`shell_exec`/`system`/`passthru`/`exec` overridden when enabled), `coproc()`/`coprocess()` legacy fork demo (mixed mode ONLY — refused under sg=false, recorded), `App::stats()` (workers/store/cache/ws/memory/uptime snapshot).
- **Data-layer wiring (cross-ref P8):** the prober reads its target list from **SQL** (`probe_targets`) and writes each round's results to **Mongo** (`insertMany` time-series + TTL index); the report task aggregates from Mongo (`aggregate`), renders via `renderToString`, stores the artifact in **GridFS** (`Vault`), and records the metadata row in **SQL** (`reports`, inside `transaction()`) — one feature, both databases, each doing its §1.1 job.
- **Batches:** ☐ B1 tick fires per worker (aggregation visible; `clearTimer` stops it) · ☐ B2 after-once warmup · ☐ B3 task: report build offloads (Mongo aggregate → GridFS artifact → SQL metadata), completes, notifies via pub/sub → WS toast (request thread never blocks) · ☐ B4 prober: N targets probed concurrently (wall-clock ≈ slowest, not sum); failures isolated (`failed()` true, no throw); parallelLimit bounds confirmed; round lands in Mongo as ONE `insertMany` · ☐ B5 SIGTERM drains in-flight requests then exits; SIGUSR1 dumps stats · ☐ B6 sidecar alive in `ps`, prunes on schedule, dies with the server · ☐ B7 exec probes: `App::exec` yields (concurrent requests unblocked during a slow command), `rawExec` blocks (recorded), shim family return-shapes match natives · ☐ B8 `coproc` works in mixed / refused in coroutine (both recorded) · ☐ B9 worker recycle log line observed at low `ZEALPHP_MAX_REQUEST`; onWorkerStop flush ran · ☐ B10 re-verify.
- **Done:** reports build off-thread; prober rounds are concurrent; SIGTERM is graceful; sidecar prunes; stats live.

### Phase 12 — Security review · cross-cutting · **Status: ◻️ planned**
- **Feature:** harden everything, then **prove** it — CSRF on all forms, rate-limit + lockout on auth, input validation/escaping, secure headers, no open redirect, trusted-proxy XFF, WS HMAC + room auth, session strict mode, upload guards, body-size caps, the unserialize whitelist, and a fuzz pass.
- **APIs/probes:** `CsrfMiddleware` (reject missing/wrong token), `RateLimitMiddleware` 429 + `Retry-After` (loopback exemption OFF for the test, `ZEALPHP_RATE_LIMIT_LOOPBACK`), `App::trustedProxies()`+`clientIp()` (XFF only from trusted CIDR; rightmost-untrusted walk), `requestIsHttps`/X-Forwarded-Proto gating, `Response::redirect` guard shapes (`//evil`, `\\evil`, `javascript:`, cross-origin, userinfo bypass), template escaping discipline (`htmlspecialchars` everywhere user data renders — no auto-escape), upload forgery (`is_uploaded_file` false on forged path), `BodySizeLimitMiddleware` 413, `ConcurrencyLimitMiddleware` 503 under flood, dotfile/traversal/null-byte probes (403/400), TRACE 405, WS: HMAC-less client rejected, room authorizer rejects a non-member, session fixation re-probe (strict mode), CGI env (`HTTP_PROXY` absent in subprocess), access-log CRLF escape (forged-line probe), `serverTokens`/`poweredByHeader` minimal, security headers present on every response incl. 4xx/5xx.
- **Batches:** ☐ B1 injection set (CRLF/header-split/open-redirect/XSS-escape) · ☐ B2 authz set (CSRF/rate-limit/lockout/IP-ACL/Referer/admin-group bypass attempts) · ☐ B3 proxy-trust set (XFF spoof from untrusted peer ignored; trusted path honoured) · ☐ B4 resource set (body-size 413, concurrency 503, multipart-range cap, slow-client note) · ☐ B5 WS set (HMAC, room auth, client rate-limit) · ☐ B6 platform set (dotfiles, traversal, TRACE, uploads, unserialize whitelist, CGI env) · ☐ B7 a bounded radamsa/gabbi-style fuzz against the live app (no hangs, no stack-trace leak, no contract drift) · ☐ B8 re-verify + the self-audit checklist published at `/admin/security`.
- **Done:** the audit page is all-green; every probe has a recorded curl proof.

### Phase 13 — Templates · file-execution · htmx UI · `template/` + `public/js/` · **Status: ◻️ planned**
- **Feature:** the **real UI** — a layout-driven, htmx-boosted dashboard: progressive SSR first paint, fragment-level partial updates, out-of-band toasts, htmx-aware handlers that return only what the client asked for.
- **APIs:** `App::render()` (layout + pages), `App::renderToString()` (report HTML capture → task email/file), `App::renderStream()` (progressive board: shell → metrics → feed, `yield from` composition), streaming-template Closures (named-param injection), `App::fragment()` named regions (board tiles re-render independently) + fragment state, `App::renderHtmx()` (selector-driven partial), **`HtmxResponse` builder via `$response->htmx()`** — `pushUrl`/`replaceUrl`/`redirect`/`location`/`refresh`/`reswap`/`retarget`/`reselect`/`trigger`/`triggerAfterSwap`/`triggerAfterSettle`/`triggerJSON`/`oob` (toast pattern)/`response` — **every builder method used somewhere real**, htmx request accessors on `Request`: `isHtmx`/`isBoosted`/`isHistoryRestoreRequest`/`htmxTarget`/`htmxTrigger`/`htmxTriggerName`/`htmxCurrentUrl`/`htmxPrompt` (handlers branch full-page vs partial), `hx-boost` on `<body>`, `hx-get/post/target/swap/trigger` conventions, `TemplateUnavailableException` (custom 500 on a missing template), the universal return contract from template land (`return 404` inside a template file), separation rules (no inline JS/CSS — `public/js/`, `public/css/`).
- **Batches:** ☐ B1 render/renderToString/renderStream all three paths live · ☐ B2 fragments: a single tile re-renders via `App::fragment` without touching siblings · ☐ B3 htmx accessors: same URL returns full page (browser) vs fragment (hx request) · ☐ B4 HtmxResponse: every builder method demonstrated (swap strategies, retarget, URL push, OOB toast, client-event triggers incl. JSON payload) · ☐ B5 progressive SSR first paint (chunks observed on the wire in order) · ☐ B6 `TemplateUnavailableException` → friendly 500 · ☐ B7 escaping discipline holds in every template (cross-ref P12) · ☐ B8 re-verify.
- **Done:** the dashboard feels app-like (no full reloads), first paint streams, toasts arrive OOB.

### Phase 14 — Framework infra & ops surface · `route/phase14.php` + `api/` · **Status: ◻️ planned**
- **Feature:** the **ops & deployment story** — `/healthz` + `/readyz` (HealthCheckMiddleware), `/_metrics` (Prometheus text: counters, store stats, `App::stats()`, pool/WS/cache stats), `/_info` (PhpInfo HTML under BasicAuth), structured PSR-3 logging with the async sink, a custom access-log format, the full CLI lifecycle, env-knob documentation, a systemd unit + Docker recipe, and the demo-surface extras.
- **APIs:** `HealthCheckMiddleware`, `App::stats()` (+ per-subsystem `stats()`: Store/Cache/Counter-backend/DbPool/WSRouter), `Diagnostics\PhpInfo` (`phpinfo()` override renders HTML under the CLI SAPI), `Log\Logger` (PSR-3 levels + interpolation; `ZEALPHP_LOG_ASYNC` channel sink), `elog()`/`zlog()`/`error_log()` routing + `jTraceEx()`, `App::accessLogFormat()` custom tokens (incl. `%{X-Request-Id}i` correlation) + `access_log` files, CLI: `php app.php start|stop|status|restart|logs|help` + `-p/-H/-w/-d/--task-workers/--pid-file/--dev` + log filters `--access/--debug/--server/--zlog` + per-port PID files + per-user log-dir fallback + duplicate-start detection + orphan recovery, env precedence (flag > env > app default) across the `ZEALPHP_*` family (PORT/HOST/WORKERS/TASK_WORKERS/DAEMONIZE/PID_FILE/LOG_*/MAX_REQUEST/REDIS_URL/STORE_BACKEND/CORS_ORIGINS/SESSION_*/WS_HMAC/...), `App::serverTokens()`/`poweredByHeader()`/`serverAdmin()`/`canonicalName()`/`useCanonicalName()`/`hostnameLookups()`/`limitRequestFields()`/`limitRequestFieldSize()`/`limitRequestLine()` final knob pass, `GithubStars::register()` footer widget (demo-surface), `StringUtils` helpers consumed in `src/`, deployment: systemd unit (daemonize OFF under systemd), Docker, nginx front-proxy notes (trusted-proxy wiring cross-ref P12).
- **Batches:** ☐ B1 health/ready truthful (readyz flips during a forced backend outage) · ☐ B2 `/_metrics` Prometheus-parseable; every subsystem reports · ☐ B3 `/_info` renders full phpinfo HTML behind BasicAuth · ☐ B4 logging: levels, interpolation, async sink under load, request-id correlation in the access log · ☐ B5 CLI full pass: start/dup-start/status/logs/restart-preserves-daemonize/stop/orphan-claim + flag-vs-env precedence · ☐ B6 custom access-log format live · ☐ B7 systemd unit + Docker boot verified · ☐ B8 knob final pass (tokens/limits) + env matrix documented · ☐ B9 re-verify.
- **Done:** ZealPulse is deployable, observable, and operable by a stranger using only this section.

---

## 5. Build protocol (the workflow — repeat per phase)

1. **Read** §3 (known issues) + the next phase's plan in §4 + its §7 appendix rows.
2. **Build** `route/phaseN.php` (+ any `src/`, `api/`, `task/`, `template/`, `public/` it needs). Thin handlers → `src/` services. PSR-2, `declare(strict_types=1)` in `src/`.
3. **Run** `ZEAL_MODE=<mode> ZEAL_PORT=9100 php app.php` for each mode the phase needs (coroutine always; superglobal modes for input/session phases; legacy-cgi for P10; `-w 4` whenever cross-worker behaviour matters).
4. **Confirm** every batch ☐ on the wire (curl headers+body; raw socket where framing matters; two browsers for WS). Tick ☑ as confirmed. A capability whose backing service is absent (Redis/Memcached/MySQL/MongoDB/replica-set/ext-zealphp/ext-zealphp-mongodb-ext) is **skip-recorded with proof of the refusal**, never silently skipped.
5. **Respect** §3 — if a known divergence shows, tolerate/work around it; **do not re-file**. A *new* divergence → note it for the phase's verification sweep (not the app build).
6. **Update** this file: flip the phase **Status** to ✅ DONE with the live result, tick its batch boxes, tick the §7 appendix rows the phase lit up.
7. **Commit** on `project/zealpulse`: `zealpulse: Phase N — <feature> (B1–Bn confirmed)`; push.
8. **Next** phase. Never skip a batch; never leave a phase half-confirmed.

## 6. Status board

| Phase | Feature | Status |
|---|---|---|
| 1 | Response core + dashboard shell | ✅ DONE (coroutine, v0.4.8) |
| 2 | Request input + file-API | ✅ DONE (coroutine + mixed, v0.4.8) |
| 3 | Static + conditional GET + download center | ✅ DONE (coroutine, v0.4.8) |
| 4 | Sessions / auth | ✅ DONE (coroutine, v0.4.8) |
| 5 | Middleware suite (full built-in band) | ◻️ |
| 6 | Routing & dispatch + error pages + hot-reload | ◻️ |
| 7 | Streaming / SSE / WS / incident rooms | ◻️ |
| 8 | Store / Counter / Cache / SQL / MongoDB / messaging | ◻️ |
| 9 | Lifecycle modes & isolation lab | ◻️ |
| 10 | CGI dispatch + legacy bay | ◻️ |
| 11 | Timers / Tasks / Signals / Sidecars / Prober | ◻️ |
| 12 | Security review | ◻️ |
| 13 | Templates / htmx UI | ◻️ |
| 14 | Infra & ops surface + deployment | ◻️ |

---

## 7. Full-utilization appendix — every app-facing ZealPHP API → ZealPulse feature

> Derived from the `../phase.md` v0.4.8 function-level audit (1522 functions). This lists the **app-facing public
> surface** — what an application can call or configure. (Framework-internal/private functions are exercised
> implicitly and tracked in `../phase.md`, not here.) **Tick ☑ when the API runs live in ZealPulse.** A row that
> can't run in this environment (missing backing service) gets **SKIP + proof** instead — never a silent gap.
> Re-diff this appendix against the framework on every version bump.

### 7.1 `App` — boot, config & Apache-parity knobs
| ☐ | API | ZealPulse home | Phase |
|---|---|---|---|
| ☑ | `App::init` · `App::instance` · `$app->run()` | app.php bootstrap | 1 |
| ☑ | `App::documentRoot` | app.php (`public/`) | 1 |
| ☑ | `App::mode` (env-driven 4-preset switch) | app.php `ZEAL_MODE` | 1, 9 |
| ☐ | `App::isolation` (enum + string forms) · `Isolation::coerce/isProcess/cgiMode` | modes lab | 9 |
| ☑ | `App::superglobals` / `enableCoroutine` / `processIsolation` / `hookAll` (via mode presets) | app.php | 1, 9 |
| ☐ | `App::hookExec` | host probe shell shims | 11 |
| ☑ | `App::ignorePhpExt` | app.php (probe URLs) | 2 |
| ☐ | `App::pathInfo` | legacy bay PATH_INFO demo | 10 |
| ☐ | `App::directorySlash` · `directoryIndex` · `stripTrailingSlash` · `blockDotfiles` | static/dirs pass | 3 |
| ☑ | `App::staticHandlerLocations` (defaults relied on) | asset whitelist | 3 |
| ☐ | `App::fileETag` · `defaultMimeType` · `defaultCharset` | downloads + charset | 3, 5 |
| ☐ | `App::serverTokens` · `poweredByHeader` · `serverAdmin` · `canonicalName` · `useCanonicalName` · `hostnameLookups` | ops knob pass | 14 |
| ☐ | `App::limitRequestFields` / `limitRequestFieldSize` / `limitRequestLine` | ops knob pass | 14 |
| ☐ | `App::trustedProxies` · `clientIp` · `requestIsHttps` | proxy-trust set | 12 |
| ☐ | `App::accessLogFormat` | request-id correlated access log | 14 |
| ☐ | `App::traceEnabled` | TRACE probe | 6, 12 |
| ☐ | `App::displayErrors` / `display_errors` | error-page phase | 6 |
| ☐ | `App::sapiName` | `/modes` lab | 9 |
| ☐ | `App::preloadClasses` / `preloadClassmap` / `preloadDir` | boot warm of WS/SSE classes | 9 |
| ☐ | `App::stats` | `/_metrics` | 11, 14 |
| ☐ | `App::devReload` + `reloadRoutes` | dev hot-reload story | 6 |
| ☐ | `App::describeRoutes` | `/routes` + `/middleware` pages | 5, 6 |

### 7.2 Routing & dispatch
| ☐ | API | ZealPulse home | Phase |
|---|---|---|---|
| ☑ | `$app->route()` (params, `methods:`) | everywhere | 1 |
| ☐ | `route(..., raw: true)` | raw status playground | 6 |
| ☐ | `$app->nsRoute()` · `nsPathRoute()` | admin namespace | 6 |
| ☐ | `$app->patternRoute()` | catch-all + legacy redirects | 6 |
| ☐ | `$app->group()` (incl. nested) + `RouteGroup` registrars | admin group | 5, 6 |
| ☐ | `$app->setFallback()` | pretty 404 | 6 |
| ☐ | `App::setErrorHandler` (status + catch-all, param-injected) + `renderError` | custom error pages | 6 |
| ☐ | `HaltException` | clean-halt endpoint | 6 |
| ☐ | `App::ws()` | live feed + rooms | 7 |
| ☐ | per-route `middleware:` / `backend:` options | admin routes / legacy bay | 5, 10 |
| ☐ | `App::apiNullNotFound` · `apiWarnCollisions` | api layer config | 6 |
| ☐ | `App::authChecker` · `adminChecker` · `usernameProvider` | session-auth bridge | 4, 6 |
| ☐ | ZealAPI in-handler surface: `$this->json/die/paramsExists/isAuthenticated/isAdmin/getUsername/requirePostAuth/get_request_method/response` + injected `$server` | api/ files | 6 |
| ☐ | api in-file `$middleware` | api/admin-*.php | 5 |

### 7.3 Request surface
| ☐ | API | ZealPulse home | Phase |
|---|---|---|---|
| ☑ | `$g->get/post/cookie/files/server/request` (`G::instance`) | `src/Req.php` | 2 |
| ☑ | `$_GET/$_POST/$_FILES/$_COOKIE/$_SERVER` (superglobal modes) | forms/upload | 2 |
| ☑ | `php://input` (re-readable) | JSON submit | 2 |
| ☑ | `getallheaders()` / `apache_request_headers()` | `/whoami` | 2 |
| ☑ | `is_uploaded_file()` (+forge rejection) | upload | 2 |
| ☐ | `move_uploaded_file()` | avatar persist | 2 (TODO) |
| ☐ | `filter_input()` / `filter_input_array()` | `/search` validation | 2 (TODO) |
| ☐ | `Request` htmx accessors (`isHtmx/isBoosted/isHistoryRestoreRequest/htmxTarget/htmxTrigger/htmxTriggerName/htmxCurrentUrl/htmxPrompt`) | partial-vs-full branching | 13 |
| ☐ | PSR-7: `$g->psr_request` (`LazyServerRequest` fast-path/hydration/`with*`) | api layer read-once | 2 (TODO) |
| ☐ | PSR-17 factories (`Request/Response/ServerRequest/Stream/UploadedFile/Uri`) | prober + tests | 11 |
| ☐ | `Input\RequestInput` (via filter overrides + REST sanitize) | api inputs | 6 |

### 7.4 Response surface
| ☐ | API | ZealPulse home | Phase |
|---|---|---|---|
| ☑ | return contract: array/string/int/Generator/null+echo | everywhere | 1 |
| ☐ | `ResponseInterface` passthrough return | one PSR endpoint | 6 |
| ☑ | `\ZealPHP\header()` (multi-append + replace) · `setcookie()` | shell + prefs | 1 |
| ☐ | `setrawcookie()` | raw cookie demo | 4 |
| ☐ | `headers_list()` / `headers_sent()` / `header_remove()` | `/whoami` response view | 2 (TODO) |
| ☐ | `http_response_code()` (get + set) | status playground v2 | 6 |
| ☐ | `$response->status($code, $reason)` two-arg | error pages | 6 |
| ☑ | `$response->redirect()` (+ guards) | `/go` | 1, 12 |
| ☐ | `$response->json()` · `end()` · `flush()` explicit | api + stream endpoints | 6, 7 |
| ☐ | `$response->stream($write)` | log tail | 7 |
| ☐ | `$response->sse($emit)` | metrics stream | 7 |
| ☑ | `$response->sendFile($path, $filename?)` | download center | 3 |
| ☐ | `$response->htmx()` → `HtmxResponse` (ALL builder methods: pushUrl/replaceUrl/redirect/location/refresh/reswap/retarget/reselect/trigger/triggerAfterSwap/triggerAfterSettle/triggerJSON/oob/response) | htmx UI | 13 |
| ☐ | `header_register_callback()` | late ops header (not legacy-cgi #357) | 14 |
| ☐ | `connection_aborted()` / `connection_status()` / `ignore_user_abort()` | log-tail disconnect | 7 |
| ☐ | `set_time_limit()` no-op contract (recorded) | modes lab note | 9 |

### 7.5 Sessions
| ☐ | API | ZealPulse home | Phase |
|---|---|---|---|
| ☐ | `session_start/id/name/status/unset/destroy/write_close/commit/abort` | login flow | 4 |
| ☐ | `session_regenerate_id(true)` | fixation-safe login | 4 |
| ☐ | `session_set_cookie_params` / `session_get_cookie_params` | cookie attrs | 4 |
| ☐ | `session_create_id` · `session_encode` / `session_decode` | prefs export | 4 |
| ☐ | `$_SESSION` ⇄ `$g->session` alias | identity | 4 |
| ☐ | `App::sessionLifecycle` · `sessionTtl` · `sessionMaxRows` · `sessionDataSize` · `sessionSavePath` · `sessionStrictMode` | session config | 4 |
| ☐ | `App::sessionHandler` + File/Table/Store/Redis/CoroutineMemory handlers (`::register()` forms) | handler switchboard | 4 |
| ☐ | `SessionStartMiddleware` | global band | 5 |

### 7.6 Middleware (every built-in gets a home)
| ☐ | Built-in | Mount | Phase |
|---|---|---|---|
| ☐ | RequestId · MergeSlashes · Charset · SetEnvIf · Header · SessionStart | global stack | 5 |
| ☐ | Cors · RateLimit | `when('/api')` | 5 |
| ☐ | Range · ETag · CacheControl · Expires · MimeType · ContentEncoding · ContentLanguage | `when('/assets-dl')` | 3, 5 |
| ☐ | IniIsolation · BlockPhpExt | `when('/legacy')` | 5, 10 |
| ☐ | IpAccess · Referer · ConcurrencyLimit · BodySizeLimit (+ session-auth alias) | admin group | 5, 12 |
| ☐ | BasicAuth (htpasswd/APR1) | `/_info` | 5, 14 |
| ☐ | Csrf | form routes | 5, 12 |
| ☐ | Return (418 teapot) · Redirect (legacy URLs) · LocationHeader · HostRouter · BodyRewrite · RequestHeader · Scoped (`::location()`) | per-route demos | 5 |
| ☐ | HealthCheck (`/healthz` `/readyz`) | ops | 5, 14 |
| ☐ | Compression — **deliberately NOT mounted** (server `http_compression` on; documented) | n/a (recorded) | 5 |
| ☐ | `App::addMiddleware` · `middlewareAlias` (incl. `alias:param`) · `when` (prefix + `#regex#`) · group middleware · per-route · api in-file | wiring mechanics | 5 |

### 7.7 Real-time (WS / SSE / streams)
| ☐ | API | ZealPulse home | Phase |
|---|---|---|---|
| ☐ | `ws()` onOpen/onMessage/onClose + `$server->push/isEstablished/disconnect/getClientList` | `/live` feed | 7 |
| ☐ | `WSRouter::init/initOptions/serverId` | rooms boot | 7 |
| ☐ | `WSRouter::own/ownAuthenticated/release/sendToClient/broadcast/onRoom/localFds/reset` | feed + rooms | 7 |
| ☐ | `WSRouter::room()` → `Room::join/leave/push/size/members/membersPaged/onMessage/onPresence` + `CapacityException` | incident rooms | 7 |
| ☐ | `WSRouter::sessionPrincipal/roomAuthorizer/authorizeRoom/requireRoomAuth` + `WSAuthException` | room auth | 7, 12 |
| ☐ | `WSRouter::setChannelHmacSecret/signPayload/verifyPayload` (`ZEALPHP_WS_HMAC`) | channel HMAC | 7, 12 |
| ☐ | `WSRouter::setClientRateLimit/setRoomRateLimit/setFanoutConcurrency` + backpressure | limits | 7 |
| ☐ | `WSRouter::onlineCount/onlineByServer/stats/runStaleServerGC` + Redis federation (2 instances) | presence + scale-out | 7 |
| ☐ | Generator SSR + `yield from` composition | initial board | 7, 13 |

### 7.8 State, data & messaging
| ☐ | API | ZealPulse home | Phase |
|---|---|---|---|
| ☐ | `Store::make` (typed schema) + `set/get/getStrict/del/exists/incr/decr/count/names/clear` | metrics tables | 8 |
| ☐ | `Store::mget/mset` · `iterate/iteratePaged` · `sadd/srem/scard/sscanCursor/sdel` · `compareAndSet` · `ping` · `stats` · `evalScript` (Redis) | board + viewer sets | 8 |
| ☐ | backends: Table / Redis / Memcached / Tiered (+HMAC invalidation) / CircuitBreaker (`state()`) via `ZEALPHP_STORE_BACKEND` | backend switchboard | 8 |
| ☐ | `Counter`: increment/decrement/get/set/reset/compareAndSet/incrementBounded/expire/mincr (+ Atomic/Redis/Memcached backends) | totals + online | 8 |
| ☐ | `Cache::init/getOrCompute/set/get/del/has/mget/mset/count/invalidateTag/flush/clear/stats` + PSR-16 `SimpleCacheAdapter` | aggregates | 8 |
| ☐ | `Db\DbConnectionPool::pdo/mysqli/with/transaction/acquire/release/stats/size/close` (graceful absence) | SQL system of record (users/incidents/rules/targets/reports) | 8 |
| ☐ | `Store::publish` + `App::subscribe/onPubSub/offPubSub/unsubscribe` | alert fan-out | 8 |
| ☐ | `Store::publishReliable` + `App::subscribeReliable/onReliableMessage` | alert audit trail | 8 |
| ☐ | `App::backpressureBootAdvisory` / `redisBootChecks` / `Store::tieredAdvisory/tieredBootChecks` | boot checks respected | 8 |

### 7.9 Modes & isolation (app-facing)
| ☐ | API | ZealPulse home | Phase |
|---|---|---|---|
| ☐ | 4 presets live (coroutine/mixed/coroutine-legacy/legacy-cgi) + matrix page | modes lab | 9 |
| ☐ | refused combos refuse at boot (recorded) | modes lab | 9 |
| ☐ | `silentRedeclare/includeIsolation/coroutineGlobalsIsolation/coroutineStaticsIsolation/functionIsolation/defineIsolation/keepGlobals/refreshGlobalsBaseline/globalScopeInclude` (ext present) | isolation probe | 9 |
| ☐ | `coroutine{Cwd,Locale,Umask,Timezone,Mbenc,Libxml}Isolation` | process-state demo | 9 |
| ☐ | `ini_set`/`putenv`/`getenv` request-overlay isolation | burst probe | 9 |
| ☐ | v0.4.8 truthful `isset($g->x)`/`unset($g->x)` | `$g` probe | 9 |
| ☐ | `opcacheLegacyBootCheck` advisory surfaced | modes lab | 9 |

### 7.10 CGI & legacy
| ☐ | API | ZealPulse home | Phase |
|---|---|---|---|
| ☐ | `App::include()` (+ fallback PHP_SELF preamble pattern) | guestbook | 10 |
| ☐ | `cgiMode('pool'/'proc'/'fork'/'fcgi')` switchboard | legacy bay | 10 |
| ☐ | `cgiPoolSize/cgiPoolMaxRequests/cgiTimeout/cgiPoolEnvAllowlist/cgiSubprocessAutoload/cgiForkMaxConcurrent/fcgiAddress` | pool tuning | 10 |
| ☐ | `registerCgiBackend` + `cgiScriptAlias` + `cgiBackendAlias` + per-route `backend:` + `resetCgiBackends` | status.sh CGI | 10 |
| ☐ | sessions + `php://input` + uploads across the CGI boundary | guestbook forms | 10 |

### 7.11 Concurrency, background & outbound
| ☐ | API | ZealPulse home | Phase |
|---|---|---|---|
| ☐ | `App::tick` / `after` / `clearTimer` | aggregation | 11 |
| ☐ | `App::onWorkerStart` / `onWorkerStop` (+ recycle) | warmup/flush | 11 |
| ☐ | `$server->task()` + `task/*.php` + `adoptRequestContext` | report builds | 11 |
| ☐ | `App::go` · `App::parallel` · `App::parallelLimit` | prober fan-out | 11 |
| ☐ | `HTTP::get/post/put/delete/request/all` + `HTTPResponse` (`ok/failed/json`) | uptime prober | 11 |
| ☐ | PSR-18 `HTTP\Client` + `NetworkException`/`RequestException` | standards probe | 11 |
| ☐ | `App::onSignal` (SIGTERM drain, SIGUSR1 stats) | graceful stop | 11 |
| ☐ | `App::addProcess` (named sidecar, `ps` title) | zp-pruner | 11 |
| ☐ | `App::exec` (yielding) / `App::rawExec` (blocking) + hookExec shim family | host probe | 11 |
| ☐ | `coproc()`/`coprocess()` (mixed only; refusal recorded) | legacy fork demo | 11 |

### 7.12 Templates & UI
| ☐ | API | ZealPulse home | Phase |
|---|---|---|---|
| ☑ | `App::render` | shell (P1) | 1, 13 |
| ☐ | `App::renderToString` | report capture | 13 |
| ☐ | `App::renderStream` + streaming Closures | progressive board | 13 |
| ☐ | `App::fragment` (+ state) | board tiles | 13 |
| ☐ | `App::renderHtmx` | selector partials | 13 |
| ☐ | `TemplateUnavailableException` | friendly 500 | 13 |
| ☐ | `App::getCurrentFile` / `tryInclude` | template debug header | 13 |

### 7.13 Infra, ops & misc
| ☐ | API | ZealPulse home | Phase |
|---|---|---|---|
| ☐ | CLI: start/stop/status/restart/logs/help + all flags + PID/log-dir behaviour | ops runbook | 14 |
| ☐ | `Log\Logger` (PSR-3) + `elog/zlog/error_log` + async sink | structured logs | 14 |
| ☐ | `Diagnostics\PhpInfo` (`phpinfo()` override) | `/_info` | 14 |
| ☐ | `App::stats` + subsystem `stats()` fan-in | `/_metrics` | 14 |
| ☐ | env knob families (`ZEALPHP_*`) precedence proven | runbook | 14 |
| ☐ | `GithubStars::register` | footer widget | 14 |
| ☐ | `StringUtils` helpers | `src/` use | 14 |
| ☐ | `zapi()` / `get_config()` / `site_url()` / `site_host()` utils | where natural | 14 |
| ☐ | set/restore error & exception handlers + `register_shutdown_function` + `error_reporting` (per-request) | error pages + P9 probe | 6, 9 |
| ☐ | fatal→500 guard observed (deliberate fatal → 500, worker survives) | error-page phase | 6 |

### 7.14 MongoDB (`zealphp/mongodb` — async Rust driver, `ZealPHP\MongoDB\*`)
| ☐ | API | ZealPulse home | Phase |
|---|---|---|---|
| ☐ | `Client` (`__construct` URI, `selectDatabase/getDatabase`, `selectCollection/getCollection`, `listDatabases/listDatabaseNames`, `dropDatabase`, `getPoolId`, read/write-concern + preference + typeMap getters) | `src/Mongo.php` per-worker client; `/modes` lab pool info | 8 |
| ☐ | `Database` (`command` ping/explain, `aggregate`, `createCollection/dropCollection/drop`, `listCollections/listCollectionNames`, `modifyCollection/renameCollection`, `withOptions`, `selectGridFSBucket`) | boot migrate + vault | 8 |
| ☐ | `Collection` CRUD: `insertOne/insertMany/find/findOne/updateOne/updateMany/replaceOne/deleteOne/deleteMany` | firehose emit/scan/tag/prune | 8 |
| ☐ | `Collection` atomic ops: `findOneAndUpdate` (job claim) / `findOneAndDelete` / `findOneAndReplace` | report-job queue | 8, 11 |
| ☐ | `Collection` analytics: `aggregate` (pipeline rollups) · `countDocuments`/`estimatedDocumentCount` · `distinct` · `bulkWrite` | board rollups + filters | 8, 13 |
| ☐ | `Collection` indexes: `createIndex/createIndexes/listIndexes/dropIndex/dropIndexes` (TTL self-prune + compound) + `IndexInfo` | firehose indexes | 8 |
| ☐ | result objects: `InsertOneResult/InsertManyResult/UpdateResult/DeleteResult/BulkWriteResult` (counts asserted) | batch proofs | 8 |
| ☐ | cursors: `Cursor`/`AsyncCursor` lazy iteration + `toArray` · `ArrayCursor` sort/limit/skip | 10k feed scan | 8 |
| ☐ | sessions/transactions: `startSession` → `Session::startTransaction/commitTransaction/abortTransaction/endSession/isInTransaction` + `['session'=>$s]` (replica set; standalone = thrown proof) | incident merge | 8 |
| ☐ | change streams: `Collection/Database/Client::watch()` + `ChangeStream` (`current/next/getResumeToken/close`, `fullDocument: updateLookup`) | live-board bridge → WS | 7, 8 |
| ☐ | GridFS `Bucket`: `uploadFromStream/openUploadStream/uploadBytes/downloadToStream/downloadToStreamByName/openDownloadStream/openDownloadStreamByName/delete/deleteByName/rename/drop/find/findOne/getFilesCollection/getChunksCollection` | artifact vault + download center | 3, 8, 11 |
| ☐ | BSON types: `ObjectId/UTCDateTime/Regex/Binary/Decimal128/Int64/Timestamp/Javascript/MinKey/MaxKey/Document::fromPHP/fromJSON/toPHP/PackedArray` + `Serializable/Unserializable/Persistable` | event documents round-trip | 8 |
| ☐ | `ReadConcern`/`ReadPreference`/`WriteConcern` + `withOptions` | rollup read tuning | 8 |
| ☐ | `AsyncBridge::isCoroutineMode` + the dual-mode proof (coroutine = non-blocking eventfd; mixed = block_on, recorded) | async proof batch B9 | 8 |
| ☐ | exceptions: `ConnectionException/ConnectionTimeoutException/AuthenticationException/BulkWriteException/CommandException/ServerException/RuntimeException` (incl. the not-implemented throws) | failure-path probes | 8, 12 |

---

*Spec v2 authored 2026-06-11 against ZealPHP v0.4.8 @ `61b0a86` (the `../phase.md` function-level audit) +
`zealphp/mongodb` (github `sibidharan/zealphp-mongodb`, API verified from source 2026-06-11). When either package's
version bumps: re-diff §7 against the new API surface FIRST, then resume the phase build.*

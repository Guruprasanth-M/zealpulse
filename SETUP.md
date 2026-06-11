# ZealPulse — environment setup

Get a fresh box from zero to a running ZealPulse with both databases. No sudo, no Docker — everything runs as
user processes from portable binaries.

## 0. Prerequisites

- **PHP 8.3+** with `ext-mongodb` (C driver) and `pdo_mysql`/`mysqli` — already standard on most boxes
  (`php -m | grep -E 'mongodb|pdo_mysql'`).
- **The OpenSwoole + ext-zealphp runtime** to run live (see §3). The app *boots* in any lifecycle mode; without
  the runtime you can still develop/test PHP, but the HTTP server needs OpenSwoole.
- `composer`, `curl`, `tar`, and `mongosh` (the Mongo shell, for replica-set init/verification).

## 1. Install the app

```bash
git clone https://github.com/Guruprasanth-M/zealpulse.git
cd zealpulse
composer install
```

## 2. Stand up the databases (one command)

```bash
bash scripts/setup-env.sh up      # downloads + starts MongoDB (replica set rs0) + MariaDB, writes the env
source ~/.zealpulse-env/zealpulse.env
```

`setup-env.sh` is **idempotent** and **no-sudo**: it downloads portable MongoDB + MariaDB binaries into
`~/.zealpulse-env`, starts them as user processes, initializes a **single-node MongoDB replica set** (so
transactions, change streams, and GridFS work — they require a replica set, same as the official driver), creates
the `zealpulse` MySQL database + user, and writes the env file ZealPulse reads:

```
ZP_MONGO_URI=mongodb://127.0.0.1:27017/?replicaSet=rs0   ZP_MONGO_DB=zealpulse
ZP_DB_DSN=mysql:host=127.0.0.1;port=3307;dbname=zealpulse;charset=utf8mb4   ZP_DB_USER=zealpulse  ZP_DB_PASS=pulse
```

Other commands: `setup-env.sh status` (what's up), `setup-env.sh down` (stop, keep data), `setup-env.sh env`
(print the export lines). Knobs: `ZP_ENV_BASE`, `ZP_MONGO_PORT`, `ZP_DB_PORT`, `MONGO_VER`, `MARIADB_VER`.

> **Data services are optional.** Every ZealPulse feature skip-records gracefully when its service is absent, so the
> app still boots and demos with no databases — but the dual-DB phases (the live board, firehose, GridFS) only light
> up with the env sourced.

## 3. The OpenSwoole + ext-zealphp runtime

ZealPHP runs on OpenSwoole with the `ext-zealphp` per-coroutine isolation extension. On a box without a system
install, build them into a user prefix and load via `PHP_INI_SCAN_DIR` (no sudo):

```bash
# openswoole 22.1.x + ext-zealphp 0.3.x, NTS, against your php-dev headers
# (phpize / ./configure / make for each; copy the .so; point an ini at them).
# See the ZealPHP install docs: https://php.zeal.ninja  ·  pie install sibidharan/ext-zealphp
php -m | grep -E 'openswoole|zealphp'   # verify both load
```

## 4. Run

```bash
source ~/.zealpulse-env/zealpulse.env
php app.php                       # coroutine mode (recommended), http://127.0.0.1:9100
ZEAL_MODE=mixed php app.php       # or coroutine-legacy / legacy-cgi
```

Smoke test:

```bash
curl -s http://127.0.0.1:9100/health          # {"status":"ok",...}
mongosh --port 27017 --quiet --eval 'rs.status().ok'    # 1  (replica set up)
mysql -h127.0.0.1 -P3307 -uzealpulse -ppulse -e 'SELECT 1'   # MariaDB reachable
```

## 5. Async MongoDB (optional, for the full performance story)

This setup uses the **C `ext-mongodb`** driver — fully functional, but **blocking** (a Mongo call parks the worker).
For the non-blocking coroutine path (the 3.4–6.7× concurrency win), build the Rust **[zealphp-mongodb](https://github.com/sibidharan/zealphp-mongodb)**
extension — a drop-in for `mongodb/mongodb` that yields to the OpenSwoole scheduler:

```bash
# needs: rustup (no sudo: curl https://sh.rustup.rs | sh), php-dev headers, libclang (bindgen)
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh
# then build the ext per its README, load it, and DROP mongodb/mongodb from composer
# (the ext provides the same MongoDB\* API). The app code is unchanged — drop-in.
```

When the Rust ext is loaded, remove the Composer `mongodb/mongodb` lib (the ext supplies the API) and ZealPulse's
Mongo features run non-blocking under coroutines.

---

**TL;DR for a fresh box:** `git clone … && cd zealpulse && composer install && bash scripts/setup-env.sh up &&
source ~/.zealpulse-env/zealpulse.env && php app.php`.

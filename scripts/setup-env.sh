#!/usr/bin/env bash
# ZealPulse — automated data-service setup (no sudo, no docker, portable binaries).
#
# Stands up, as plain user processes:
#   • MongoDB  — single-node REPLICA SET (rs0) so transactions, change streams & GridFS work
#   • MariaDB  — user datadir, the `zealpulse` database + user
# and writes the env file ZealPulse reads. Idempotent: re-running skips downloads
# and won't double-start a server that's already up. PHP talks to both via the
# already-present ext-mongodb (C) and pdo_mysql/mysqli — no extra PHP build needed.
#
#   bash scripts/setup-env.sh up       # download (first run) + start + init + write env
#   bash scripts/setup-env.sh status   # show what's running
#   bash scripts/setup-env.sh down      # stop both servers (data kept)
#   bash scripts/setup-env.sh env       # print the export lines
#
# Env knobs: ZP_ENV_BASE (default ~/.zealpulse-env), ZP_MONGO_PORT (27017),
# ZP_DB_PORT (3307), MONGO_VER (7.0.14), MARIADB_VER (11.4.3).
set -uo pipefail

BASE="${ZP_ENV_BASE:-$HOME/.zealpulse-env}"
MONGO_PORT="${ZP_MONGO_PORT:-27017}"
DB_PORT="${ZP_DB_PORT:-3307}"
MONGO_VER="${MONGO_VER:-7.0.14}"
MARIADB_VER="${MARIADB_VER:-11.4.3}"
ARCH="$(uname -m)"                       # x86_64
ENVFILE="$BASE/zealpulse.env"
mkdir -p "$BASE"/{dl,mongo/data,mongo/log,maria/data,maria/log,run}

say(){ printf '\033[36m[setup]\033[0m %s\n' "$*"; }
err(){ printf '\033[31m[setup] %s\033[0m\n' "$*" >&2; }
is_up(){ ss -ltn 2>/dev/null | grep -q ":$1 "; }

# Candidate MongoDB build tags, newest-distro first; not every (version × tag)
# combo is published, so we try each until one returns 200 (2404 7.0 builds are
# spotty; 2204 binaries run fine on 24.04).
mongo_tags(){ . /etc/os-release 2>/dev/null; case "${VERSION_ID:-22.04}" in 24.*) echo "ubuntu2404 ubuntu2204";; 20.*) echo "ubuntu2004";; *) echo "ubuntu2204 ubuntu2004";; esac; }

setup_mongo(){
  local dir="$BASE/mongo"; local bin="$dir/bin/mongod"
  if [ ! -x "$bin" ]; then
    local got="" tag tarball
    for tag in $(mongo_tags); do
      tarball="mongodb-linux-${ARCH}-${tag}-${MONGO_VER}.tgz"
      say "trying MongoDB ${MONGO_VER} (${tag})…"
      if curl -fSL --retry 2 -o "$BASE/dl/$tarball" "https://fastdl.mongodb.org/linux/$tarball" 2>/dev/null; then got="$tag"; break; fi
    done
    [ -n "$got" ] || { err "MongoDB download failed for all tags ($(mongo_tags))"; return 1; }
    tar -xzf "$BASE/dl/mongodb-linux-${ARCH}-${got}-${MONGO_VER}.tgz" -C "$BASE/dl"
    mkdir -p "$dir/bin"; cp "$BASE/dl/mongodb-linux-${ARCH}-${got}-${MONGO_VER}/bin/"* "$dir/bin/"
    say "MongoDB binaries installed → $dir/bin"
  fi
  if is_up "$MONGO_PORT"; then say "MongoDB already listening on $MONGO_PORT"; else
    say "starting mongod (replSet rs0) on $MONGO_PORT…"
    "$bin" --dbpath "$dir/data" --port "$MONGO_PORT" --bind_ip 127.0.0.1 \
           --replSet rs0 --logpath "$dir/log/mongod.log" --fork >/dev/null 2>&1 \
      || { err "mongod failed to start (see $dir/log/mongod.log)"; tail -3 "$dir/log/mongod.log" 2>/dev/null; return 1; }
  fi
  # init the replica set once (idempotent: rs.status() ok → skip)
  if ! mongosh --quiet --port "$MONGO_PORT" --eval 'rs.status().ok' 2>/dev/null | grep -q 1; then
    say "initiating replica set rs0…"
    mongosh --quiet --port "$MONGO_PORT" --eval \
      "rs.initiate({_id:'rs0',members:[{_id:0,host:'127.0.0.1:$MONGO_PORT'}]})" >/dev/null 2>&1
    for i in $(seq 1 20); do mongosh --quiet --port "$MONGO_PORT" --eval 'db.hello().isWritablePrimary' 2>/dev/null | grep -q true && break; sleep 1; done
  fi
  mongosh --quiet --port "$MONGO_PORT" --eval 'db.hello().isWritablePrimary' 2>/dev/null | grep -q true \
    && say "MongoDB PRIMARY ready (rs0) on 127.0.0.1:$MONGO_PORT" || err "MongoDB not primary yet"
}

setup_maria(){
  local dir="$BASE/maria"; local base="$dir/dist"
  if [ ! -x "$base/bin/mariadbd" ] && [ ! -x "$base/bin/mysqld" ]; then
    local tarball="mariadb-${MARIADB_VER}-linux-systemd-${ARCH}.tar.gz"
    say "downloading MariaDB ${MARIADB_VER}…"
    curl -fSL --retry 3 -o "$BASE/dl/$tarball" "https://archive.mariadb.org/mariadb-${MARIADB_VER}/bintar-linux-systemd-${ARCH}/$tarball" \
      || { err "MariaDB download failed"; return 1; }
    mkdir -p "$base"; tar -xzf "$BASE/dl/$tarball" -C "$base" --strip-components=1
    say "MariaDB installed → $base"
  fi
  local mysqld; mysqld="$base/bin/mariadbd"; [ -x "$mysqld" ] || mysqld="$base/bin/mysqld"
  if [ ! -f "$dir/data/mysql/user.frm" ] && [ ! -d "$dir/data/mysql" ]; then
    say "initializing MariaDB datadir…"
    "$base/scripts/mariadb-install-db" --no-defaults --basedir="$base" --datadir="$dir/data" --auth-root-authentication-method=normal >/dev/null 2>&1 \
      || "$base/scripts/mysql_install_db" --no-defaults --basedir="$base" --datadir="$dir/data" >/dev/null 2>&1
  fi
  if is_up "$DB_PORT"; then say "MariaDB already listening on $DB_PORT"; else
    say "starting mariadbd on $DB_PORT…"
    "$mysqld" --no-defaults --basedir="$base" --datadir="$dir/data" --port="$DB_PORT" \
              --socket="$BASE/run/maria.sock" --bind-address=127.0.0.1 \
              --pid-file="$BASE/run/maria.pid" --log-error="$dir/log/maria.log" >/dev/null 2>&1 &
    for i in $(seq 1 25); do is_up "$DB_PORT" && break; sleep 1; done
  fi
  is_up "$DB_PORT" || { err "MariaDB failed to start (see $dir/log/maria.log)"; tail -3 "$dir/log/maria.log" 2>/dev/null; return 1; }
  say "creating zealpulse database + user…"
  "$base/bin/mariadb" --no-defaults -h127.0.0.1 -P"$DB_PORT" -uroot <<'SQL' 2>/dev/null
CREATE DATABASE IF NOT EXISTS zealpulse CHARACTER SET utf8mb4;
CREATE USER IF NOT EXISTS 'zealpulse'@'127.0.0.1' IDENTIFIED BY 'pulse';
GRANT ALL ON zealpulse.* TO 'zealpulse'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
  say "MariaDB ready on 127.0.0.1:$DB_PORT (db=zealpulse user=zealpulse)"
}

write_env(){
  cat > "$ENVFILE" <<EOF
# ZealPulse data-service env — source this before \`php app.php\`.
export ZP_MONGO_URI='mongodb://127.0.0.1:$MONGO_PORT/?replicaSet=rs0'
export ZP_MONGO_DB='zealpulse'
export ZP_DB_DSN='mysql:host=127.0.0.1;port=$DB_PORT;dbname=zealpulse;charset=utf8mb4'
export ZP_DB_USER='zealpulse'
export ZP_DB_PASS='pulse'
EOF
  say "env written → $ENVFILE"
}

case "${1:-up}" in
  up)
    setup_mongo; setup_maria; write_env
    say "DONE.  source $ENVFILE  then  php app.php"
    ;;
  status)
    is_up "$MONGO_PORT" && echo "mongo: UP ($MONGO_PORT)" || echo "mongo: down"
    is_up "$DB_PORT"    && echo "maria: UP ($DB_PORT)"    || echo "maria: down"
    ;;
  down)
    mongosh --quiet --port "$MONGO_PORT" --eval 'db.adminCommand({shutdown:1})' 2>/dev/null
    [ -f "$BASE/maria/dist/bin/mariadb-admin" ] && "$BASE/maria/dist/bin/mariadb-admin" --no-defaults -h127.0.0.1 -P"$DB_PORT" -uroot shutdown 2>/dev/null
    say "stopped (data kept under $BASE)"
    ;;
  env) cat "$ENVFILE" 2>/dev/null || err "no env yet — run: bash scripts/setup-env.sh up" ;;
  *) err "usage: setup-env.sh {up|status|down|env}"; exit 1 ;;
esac

#!/bin/bash
set -euo pipefail

MYSQL_DATABASE=geograph
MYSQL_USER=geograph

# # MySQL initialization

wait_for_mysql() {
    for _i in $(seq 1 60); do
        if mysqladmin ping --socket=/var/run/mysqld/mysqld.sock --silent 2>/dev/null; then
            return 0
        fi
        sleep 2
    done
    echo "[entrypoint] Timed out waiting for MySQL"
    exit 1
}

if [ -z "$(ls -A /var/lib/mysql 2>/dev/null)" ]; then
    echo "[entrypoint] Initializing MySQL data directory..."
    mysqld --initialize-insecure --user=mysql --datadir=/var/lib/mysql

    echo "[entrypoint] Starting MySQL for first-run setup..."
    mysqld --user=mysql --daemonize \
        --pid-file=/var/run/mysqld/mysqld.pid \
        --socket=/var/run/mysqld/mysqld.sock

    echo "[entrypoint] Waiting for MySQL..."
    wait_for_mysql

    echo "[entrypoint] Creating database and user..."
    mysql --socket=/var/run/mysqld/mysqld.sock <<SQL
CREATE DATABASE IF NOT EXISTS \`${MYSQL_DATABASE}\`;
CREATE USER IF NOT EXISTS '${MYSQL_USER}'@'%';
GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL
    echo "[entrypoint] First-run setup complete."
else
    echo "[entrypoint] Existing data directory found, skipping init."

    echo "[entrypoint] Starting MySQL..."
    mysqld --user=mysql --daemonize \
        --pid-file=/var/run/mysqld/mysqld.pid \
        --socket=/var/run/mysqld/mysqld.sock

    echo "[entrypoint] Waiting for MySQL..."
    wait_for_mysql
fi

echo "[entrypoint] MySQL is ready on port 3307."

# # Shutdown

shutdown() {
    echo "[entrypoint] Shutting down..."
    mysqladmin --socket=/var/run/mysqld/mysqld.sock shutdown
}
trap shutdown SIGTERM SIGINT

# # Sync loop

sync_loop() {
    echo "[entrypoint] Starting sync loop..."
    while true; do
        echo "[entrypoint] Running sync..."
        /app/sync.sh
        echo "[entrypoint] Sync complete, sleeping for 7 days..."
        sleep 604800
    done
}

# # Initial sync + loop

sync_loop &

# # API server

php -S 0.0.0.0:3308 /app/api.php &

echo "[entrypoint] API ready on port 3308."

# # Wait while listening for traps
sleep infinity &
wait $!

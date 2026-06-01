#!/bin/bash
set -euo pipefail

MYSQL_ROOT_PASSWORD=root
MYSQL_DATABASE=geograph
MYSQL_USER=geograph
MYSQL_PASSWORD=geograph

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
ALTER USER 'root'@'localhost' IDENTIFIED BY '${MYSQL_ROOT_PASSWORD}';
CREATE DATABASE IF NOT EXISTS \`${MYSQL_DATABASE}\`;
CREATE USER IF NOT EXISTS '${MYSQL_USER}'@'%' IDENTIFIED BY '${MYSQL_PASSWORD}';
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

echo "[entrypoint] MySQL is ready on port 3306."

# # Cron

SYNC_MINUTE=$(( RANDOM % 60 ))
echo "${SYNC_MINUTE} 5 * * * /app/sync.sh" | crontab -
echo "[entrypoint] Daily sync scheduled at ${SYNC_MINUTE} minutes past 05:00."
service cron start

# # Initial sync

/app/sync.sh
wait

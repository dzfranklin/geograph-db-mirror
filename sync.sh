#!/bin/bash
set -euo pipefail

MYSQL_CMD="/usr/bin/mysql -h127.0.0.1 -P3307 -ugeograph geograph"

echo "[sync $(date '+%Y-%m-%d %H:%M:%S')] Starting sync"

# # gridimage_search

cd /app

php /app/download.php || echo "[sync] download.php exited with error"

echo "[sync] gridimage_search sync done"

# # gridimage_snippet + snippet (single dump contains both tables, ~14MB)

SNIPPET_URL="https://data.geograph.org.uk/dumps/gridimage_snippet.mysql.gz"
SNIPPET_FILE="/tmp/gridimage_snippet.mysql.gz"

SNIPPET_TIMESTAMP_FILE="/var/lib/mysql/geograph/gridimage_snippet.last_sync"
TWENTY_HOURS_AGO=$(date -d '20 hours ago' +%s)

if [ -f "$SNIPPET_TIMESTAMP_FILE" ]; then
    LAST_SYNC=$(date -r "$SNIPPET_TIMESTAMP_FILE" +%s)
else
    LAST_SYNC=0
fi

if [ "$LAST_SYNC" -gt "$TWENTY_HOURS_AGO" ]; then
    echo "[sync] gridimage_snippet synced less than 20 hours ago, skipping"
else
    echo "[sync] Downloading gridimage_snippet..."
    /usr/bin/wget -q -O "$SNIPPET_FILE" "$SNIPPET_URL"

    echo "[sync] Importing gridimage_snippet..."
    /bin/zcat "$SNIPPET_FILE" | $MYSQL_CMD

    rm -f "$SNIPPET_FILE"

    touch "$SNIPPET_TIMESTAMP_FILE"
    echo "[sync] gridimage_snippet import done"
fi

echo "[sync] Applying setup.sql..."
$MYSQL_CMD < /app/setup.sql

echo "[sync] Refreshing gridimage_recent..."
$MYSQL_CMD -e "
    CREATE TABLE gridimage_recent_new (
        gridimage_id INT NOT NULL,
        point_ll POINT NOT NULL,
        PRIMARY KEY (gridimage_id),
        SPATIAL INDEX (point_ll)
    );
    INSERT INTO gridimage_recent_new (gridimage_id, point_ll)
    SELECT gridimage_id, point_ll
    FROM gridimage_search
    WHERE imagetaken >= YEAR(NOW()) - 5
      AND point_ll IS NOT NULL;
    RENAME TABLE gridimage_recent TO gridimage_recent_old, gridimage_recent_new TO gridimage_recent;
    DROP TABLE gridimage_recent_old;
"

echo "[sync $(date '+%Y-%m-%d %H:%M:%S')] All syncs complete"

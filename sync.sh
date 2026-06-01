#!/bin/bash
set -euo pipefail

MYSQL_CMD="/usr/bin/mysql -h127.0.0.1 -ugeograph -pgeograph geograph"

echo "[sync $(date '+%Y-%m-%d %H:%M:%S')] Starting sync"

# # gridimage_search

cd /app

php /app/download.php || echo "[sync] download.php exited with error"

echo "[sync] gridimage_search sync done"

# # gridimage_snippet + snippet (single dump contains both tables, ~14MB)

SNIPPET_URL="https://data.geograph.org.uk/dumps/gridimage_snippet.mysql.gz"
SNIPPET_FILE="/tmp/gridimage_snippet.mysql.gz"

echo "[sync] Downloading gridimage_snippet..."
/usr/bin/wget -q -O "$SNIPPET_FILE" "$SNIPPET_URL"

echo "[sync] Importing gridimage_snippet..."
/bin/zcat "$SNIPPET_FILE" | $MYSQL_CMD

rm -f "$SNIPPET_FILE"
echo "[sync] gridimage_snippet import done"

echo "[sync $(date '+%Y-%m-%d %H:%M:%S')] All syncs complete"

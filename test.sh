#!/bin/bash
set -e

mkdir -p .test/mysql-data

docker build -t geograph-db-mirror --platform linux/amd64 .

docker run --rm --platform linux/amd64 \
    -p 3306:3306 \
    -v "$(pwd)/.test/mysql-data:/var/lib/mysql" \
    --name geograph-db-mirror \
    geograph-db-mirror

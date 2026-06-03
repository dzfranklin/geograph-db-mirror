<?php

$db_hostname = '127.0.0.1';
$db_port = 3307;
$db_username = 'geograph';
$db_password = '';
$db_database = 'geograph';

function send_error($code, $message) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_URI'] === '/' || $_SERVER['REQUEST_URI'] === '') {
    http_response_code(200);
    header('Content-Type: text/plain');
    echo "ok";
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/query') {
    send_error(404, 'not found');
}

foreach (['minLat', 'minLng', 'maxLat', 'maxLng'] as $param) {
    if (!isset($_GET[$param]) || $_GET[$param] === '') {
        send_error(400, "missing parameter: $param");
    }
    if (!is_numeric($_GET[$param])) {
        send_error(400, "invalid parameter: $param");
    }
}

$minLat = (float) $_GET['minLat'];
$minLng = (float) $_GET['minLng'];
$maxLat = (float) $_GET['maxLat'];
$maxLng = (float) $_GET['maxLng'];

if ($minLat > $maxLat || $minLng > $maxLng) {
    send_error(400, 'min values must be <= max values');
}

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
if ($limit < 1) {
    send_error(400, 'limit must be >= 1');
}

$db = mysqli_connect('p:' . $db_hostname, $db_username, $db_password, $db_database, $db_port);
if (!$db) {
    send_error(500, 'database connection failed');
}
if (!mysqli_set_charset($db, 'utf8mb4')) {
    send_error(500, 'failed to set charset: ' . mysqli_error($db));
}

$excluded = ['point_ll', 'point_xy'];

function make_bbox_polygon_stmt($db, $recent, $limit) {
    // point_ll stores POINT(lng, lat) so X=lng, Y=lat
    // polygon corners: minLng minLat, maxLng minLat, maxLng maxLat, minLng maxLat, minLng minLat
    if ($recent) {
        // spatial filter on gridimage_recent, PK join to gridimage for full row
        $sql = "
            SELECT g.*, ST_X(r.point_ll) AS _lng, ST_Y(r.point_ll) AS _lat
            FROM gridimage_recent r
            JOIN gridimage g ON g.gridimage_id = r.gridimage_id
            WHERE MBRContains(
                ST_GeomFromText(CONCAT(
                    'POLYGON((',
                    ?, ' ', ?, ',',
                    ?, ' ', ?, ',',
                    ?, ' ', ?, ',',
                    ?, ' ', ?, ',',
                    ?, ' ', ?,
                    '))'
                )),
                r.point_ll
            )
            LIMIT ?
        ";
    } else {
        $sql = "
            SELECT *, ST_X(point_ll) AS _lng, ST_Y(point_ll) AS _lat
            FROM gridimage
            WHERE MBRContains(
                ST_GeomFromText(CONCAT(
                    'POLYGON((',
                    ?, ' ', ?, ',',
                    ?, ' ', ?, ',',
                    ?, ' ', ?, ',',
                    ?, ' ', ?, ',',
                    ?, ' ', ?,
                    '))'
                )),
                point_ll
            )
            LIMIT ?
        ";
    }
    $stmt = mysqli_prepare($db, $sql);
    if (!$stmt) {
        send_error(500, mysqli_error($db));
    }
    return $stmt;
}

function rows_to_features($rows, $excluded) {
    $features = [];
    foreach ($rows as $db_row) {
        $lng = (float) $db_row['_lng'];
        $lat = (float) $db_row['_lat'];

        $props = [];
        foreach ($db_row as $key => $value) {
            if (in_array($key, $excluded) || $key === '_lng' || $key === '_lat') {
                continue;
            }
            $props[$key] = $value;
        }

        $features[] = [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [$lng, $lat],
            ],
            'properties' => $props,
        ];
    }
    return $features;
}

// First do a plain query for limit+1 rows
$stmt = make_bbox_polygon_stmt($db, false, $limit + 1);
$fetch_limit = $limit + 1;
mysqli_stmt_bind_param($stmt, 'ddddddddddi',
    $minLng, $minLat,
    $maxLng, $minLat,
    $maxLng, $maxLat,
    $minLng, $maxLat,
    $minLng, $minLat,
    $fetch_limit
);
if (!mysqli_stmt_execute($stmt)) {
    send_error(500, mysqli_stmt_error($stmt));
}
$result = mysqli_stmt_get_result($stmt);
if ($result === false) {
    send_error(500, mysqli_error($db));
}
$plain_rows = [];
while ($row = mysqli_fetch_assoc($result)) {
    $plain_rows[] = $row;
}
mysqli_free_result($result);

// If fewer than limit+1 rows, the bbox is sparse enough — return them directly
if (count($plain_rows) <= $limit) {
    $features = rows_to_features($plain_rows, $excluded);
    mysqli_close($db);
    header('Content-Type: application/geo+json');
    $json = json_encode(['type' => 'FeatureCollection', 'features' => $features]);
    if ($json === false) {
        send_error(500, 'json_encode failed: ' . json_last_error_msg());
    }
    echo $json;
    exit;
}

// Dense bbox: sample by dividing into a grid, collecting 2 per cell, then randomly selecting limit
$grid_n = (int) ceil(sqrt($limit));
$latStep = ($maxLat - $minLat) / $grid_n;
$lngStep = ($maxLng - $minLng) / $grid_n;

$cell_limit = 2;
$recent_stmt = make_bbox_polygon_stmt($db, true, $cell_limit);
$all_stmt = make_bbox_polygon_stmt($db, false, $cell_limit);

$pool = [];

function query_cell($stmt, $db, $cellMinLng, $cellMinLat, $cellMaxLng, $cellMaxLat, $cell_limit) {
    mysqli_stmt_bind_param($stmt, 'ddddddddddi',
        $cellMinLng, $cellMinLat,
        $cellMaxLng, $cellMinLat,
        $cellMaxLng, $cellMaxLat,
        $cellMinLng, $cellMaxLat,
        $cellMinLng, $cellMinLat,
        $cell_limit
    );
    if (!mysqli_stmt_execute($stmt)) {
        send_error(500, mysqli_stmt_error($stmt));
    }
    $result = mysqli_stmt_get_result($stmt);
    if ($result === false) {
        send_error(500, mysqli_error($db));
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_free_result($result);
    return $rows;
}

for ($row_i = 0; $row_i < $grid_n; $row_i++) {
    for ($col_i = 0; $col_i < $grid_n; $col_i++) {
        $cellMinLat = $minLat + $row_i * $latStep;
        $cellMaxLat = $minLat + ($row_i + 1) * $latStep;
        $cellMinLng = $minLng + $col_i * $lngStep;
        $cellMaxLng = $minLng + ($col_i + 1) * $lngStep;

        $rows = query_cell($recent_stmt, $db, $cellMinLng, $cellMinLat, $cellMaxLng, $cellMaxLat, $cell_limit);
        if (count($rows) === 0) {
            $rows = query_cell($all_stmt, $db, $cellMinLng, $cellMinLat, $cellMaxLng, $cellMaxLat, $cell_limit);
        }

        foreach ($rows as $row) {
            $pool[] = $row;
        }
    }
}

shuffle($pool);
$features = rows_to_features(array_slice($pool, 0, $limit), $excluded);

mysqli_close($db);

header('Content-Type: application/geo+json');
$json = json_encode([
    'type' => 'FeatureCollection',
    'features' => $features,
]);
if ($json === false) {
    send_error(500, 'json_encode failed: ' . json_last_error_msg());
}
echo $json;

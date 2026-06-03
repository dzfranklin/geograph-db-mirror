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

function make_bbox_polygon_stmt($db, $limit) {
    // point_ll stores POINT(lng, lat) so X=lng, Y=lat
    // polygon corners: minLng minLat, maxLng minLat, maxLng maxLat, minLng maxLat, minLng minLat
    $stmt = mysqli_prepare($db, "
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
    ");
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
$stmt = make_bbox_polygon_stmt($db, $limit + 1);
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

// Dense bbox: sample by dividing into a grid and taking one row per cell
$grid_n = (int) ceil(sqrt($limit));
$latStep = ($maxLat - $minLat) / $grid_n;
$lngStep = ($maxLng - $minLng) / $grid_n;

$cell_stmt = make_bbox_polygon_stmt($db, 1);
$cell_limit = 1;

$seen_ids = [];
$features = [];

for ($row_i = 0; $row_i < $grid_n; $row_i++) {
    for ($col_i = 0; $col_i < $grid_n; $col_i++) {
        $cellMinLat = $minLat + $row_i * $latStep;
        $cellMaxLat = $minLat + ($row_i + 1) * $latStep;
        $cellMinLng = $minLng + $col_i * $lngStep;
        $cellMaxLng = $minLng + ($col_i + 1) * $lngStep;

        mysqli_stmt_bind_param($cell_stmt, 'ddddddddddi',
            $cellMinLng, $cellMinLat,
            $cellMaxLng, $cellMinLat,
            $cellMaxLng, $cellMaxLat,
            $cellMinLng, $cellMaxLat,
            $cellMinLng, $cellMinLat,
            $cell_limit
        );
        if (!mysqli_stmt_execute($cell_stmt)) {
            send_error(500, mysqli_stmt_error($cell_stmt));
        }

        $result = mysqli_stmt_get_result($cell_stmt);
        if ($result === false) {
            send_error(500, mysqli_error($db));
        }

        $db_row = mysqli_fetch_assoc($result);
        mysqli_free_result($result);

        if ($db_row === null) {
            continue;
        }

        $id = $db_row['gridimage_id'];
        if (isset($seen_ids[$id])) {
            continue;
        }
        $seen_ids[$id] = true;

        $features[] = rows_to_features([$db_row], $excluded)[0];
    }
}

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

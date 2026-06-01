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
$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;

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
    LIMIT ? OFFSET ?
");

if (!$stmt) {
    send_error(500, mysqli_error($db));
}

mysqli_stmt_bind_param($stmt, 'ddddddddddii',
    $minLng, $minLat,
    $maxLng, $minLat,
    $maxLng, $maxLat,
    $minLng, $maxLat,
    $minLng, $minLat,
    $limit, $offset
);
if (!mysqli_stmt_execute($stmt)) {
    send_error(500, mysqli_stmt_error($stmt));
}

$result = mysqli_stmt_get_result($stmt);
if ($result === false) {
    send_error(500, mysqli_error($db));
}

$excluded = ['point_ll', 'point_xy'];

$features = [];
while ($row = mysqli_fetch_assoc($result)) {
    $lng = (float) $row['_lng'];
    $lat = (float) $row['_lat'];

    $props = [];
    foreach ($row as $key => $value) {
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

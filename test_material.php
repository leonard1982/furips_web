<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/FirebirdConnection.php';

$config = require __DIR__ . '/config.php';
$connection = new FirebirdConnection($config['firebird']);
$result = $connection->query('SELECT FIRST 5 * FROM MATERIAL');

foreach ($result as $row) {
    printf("- %s\n", json_encode($row, JSON_UNESCAPED_UNICODE));
}
printf("Total filas: %d\n", count($result));

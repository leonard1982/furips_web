<?php

declare(strict_types=1);

date_default_timezone_set('America/Bogota');

$projectRoot = __DIR__;
$bdConfigFile = $projectRoot . DIRECTORY_SEPARATOR . 'bd.txt';

if (!is_file($bdConfigFile) || !is_readable($bdConfigFile)) {
    throw new RuntimeException('No se pudo leer bd.txt. Coloque la ruta completa a la base de Firebird en ese archivo.');
}

$dbPath = trim(file_get_contents($bdConfigFile));
if ($dbPath === '') {
    throw new RuntimeException('bd.txt esta vacio. Escriba la ruta completa a la base de datos (.GDB).');
}

$defaultWorkdir = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'furips';
$tempoDir = getenv('FURIPS_TEMPO_DIR') ?: $defaultWorkdir;
$jarPath = getenv('FURIPS_JAR_PATH') ?: $tempoDir . DIRECTORY_SEPARATOR . 'furips2025.jar';
$planFile = $tempoDir . DIRECTORY_SEPARATOR . 'globalsafe.txt';

$mysqlConfigFile = $projectRoot . DIRECTORY_SEPARATOR . 'mysql.txt';
$bdAnteriorFile = $projectRoot . DIRECTORY_SEPARATOR . 'bd_anterior.txt';

if (!is_file($mysqlConfigFile) || !is_readable($mysqlConfigFile)) {
    throw new RuntimeException('No se pudo leer mysql.txt. Incluya host, puerto, base, usuario y contraseña en ese archivo.');
}

if (!is_file($bdAnteriorFile) || !is_readable($bdAnteriorFile)) {
    throw new RuntimeException('No se pudo leer bd_anterior.txt. Coloque la ruta completa a la base anterior (.GDB).');
}

$mysqlLines = array_map('trim', explode("\n", file_get_contents($mysqlConfigFile)));
$mysqlConfig = [];
foreach ($mysqlLines as $line) {
    if ($line === '' || strpos($line, '#') === 0) {
        continue;
    }
    [$key, $value] = array_map('trim', explode('=', $line, 2) + ['', '']);
    if ($key !== '') {
        $mysqlConfig[strtolower($key)] = $value;
    }
}

$mandatory = ['host', 'port', 'database', 'user', 'password'];
foreach ($mandatory as $field) {
    if (!isset($mysqlConfig[$field]) || $mysqlConfig[$field] === '') {
        throw new RuntimeException("mysql.txt debe incluir la clave {$field} con un valor válido.");
    }
}

$bdAnteriorPath = trim(file_get_contents($bdAnteriorFile));
if ($bdAnteriorPath === '') {
    throw new RuntimeException('bd_anterior.txt está vacío. Escriba la ruta completa a la base de datos antigua (.GDB).');
}

return [
    'project_root' => $projectRoot,
    'firebird' => [
        'path' => $dbPath,
        'user' => getenv('FURIPS_DB_USER') ?: 'SYSDBA',
        'password' => getenv('FURIPS_DB_PASSWORD') ?: 'masterkey',
        'charset' => 'UTF8',
        'dsn' => getenv('FURIPS_DB_DSN') ?: (getenv('FURIPS_DB_HOST') ?: '127.0.0.1') . ':' . $dbPath,
    ],
    'firebird_previous' => [
        'path' => $bdAnteriorPath,
        'user' => getenv('FURIPS_DB_USER') ?: 'SYSDBA',
        'password' => getenv('FURIPS_DB_PASSWORD') ?: 'masterkey',
        'charset' => 'UTF8',
        'dsn' => getenv('FURIPS_DB_DSN') ?: (getenv('FURIPS_DB_HOST') ?: '127.0.0.1') . ':' . $bdAnteriorPath,
    ],
    'mysql' => [
        'host' => $mysqlConfig['host'],
        'port' => $mysqlConfig['port'],
        'database' => $mysqlConfig['database'],
        'user' => $mysqlConfig['user'],
        'password' => $mysqlConfig['password'],
    ],
    'tempo' => [
        'dir' => $tempoDir,
        'plan_file' => $planFile,
        'jar' => $jarPath,
    ],
    'storage' => [
        'jobs' => $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'jobs',
        'logs' => $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs',
        'exports' => $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'exports',
        'sql' => $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sql',
    ],
];

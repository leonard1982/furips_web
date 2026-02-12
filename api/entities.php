<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

function logDebug(string $message): void
{
    $logDir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    @file_put_contents(
        $logDir . '/api-debug.log',
        sprintf("[%s] ENTITIES: %s%s", (new DateTimeImmutable())->format(DATE_ATOM), $message, PHP_EOL),
        FILE_APPEND
    );
}

function utf8ize($value)
{
    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = utf8ize($item);
        }
        return $normalized;
    }

    if (is_string($value)) {
        if (!mb_check_encoding($value, 'UTF-8')) {
            return utf8_encode($value);
        }
        return $value;
    }

    return $value;
}
function respond(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $config = require __DIR__ . '/../config.php';
    require_once __DIR__ . '/../src/FirebirdConnection.php';
    require_once __DIR__ . '/../src/EntityRepository.php';
    logDebug('Configuración read y clases cargadas.');
    $connection = new FirebirdConnection($config['firebird']);
    logDebug('Connection creada.');
    $repo = new EntityRepository($connection);
    logDebug('Repository creado.');
    $entities = $repo->list();
    $entities = array_map('utf8ize', $entities);
    logDebug('Entidades recuperadas: ' . count($entities));

    logDebug('Respondiendo JSON con ' . count($entities) . ' entidades.');
    respond([
        'success' => true,
        'entities' => $entities,
    ]);
} catch (Throwable $e) {
    respond([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}

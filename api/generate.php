<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/FuripsJobManager.php';

function respond(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $config = require __DIR__ . '/../config.php';
    require_once __DIR__ . '/../src/FuripsJobManager.php';
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $startDate = $input['startDate'] ?? '';
    $endDate = $input['endDate'] ?? '';
    $entity = $input['entity'] ?? '';

    $manager = new FuripsJobManager($config);
    $result = $manager->run($startDate, $endDate, $entity);

    $outputs = array_map(static function (array $item) use ($result): array {
        $downloadAvailable = $item['exported'] !== null;
        return [
            'name' => $item['name'],
            'download_url' => $downloadAvailable
                ? 'download.php?jobId=' . rawurlencode($result['job_id']) . '&file=' . rawurlencode($item['name'])
                : null,
            'source' => $downloadAvailable ? $item['source'] : null,
        ];
    }, $result['outputs']);

    respond([
        'success' => true,
        'jobId' => $result['job_id'],
        'plan' => $result['plan'],
        'outputs' => $outputs,
        'log' => $result['log'],
        'message' => 'Furips generados correctamente.',
    ]);
} catch (Throwable $exception) {
    respond([
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
}

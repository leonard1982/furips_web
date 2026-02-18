<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
ob_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/FuripsJobManager.php';

function respond(array $payload): void
{
    $noise = trim((string) ob_get_contents());
    if ($noise !== '') {
        error_log('[generate.php] Output no esperado limpiado antes de JSON: ' . substr($noise, 0, 800));
    }
    if (ob_get_level() > 0) {
        ob_clean();
    }
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
        'sql_log' => $result['sql_log'] ?? null,
        'message' => 'Furips generados correctamente.',
    ]);
} catch (Throwable $exception) {
    respond([
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
}

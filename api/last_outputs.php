<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    $config = require __DIR__ . '/../config.php';
    $exportsDir = $config['storage']['exports'];
    $limit = 10;

    if (!is_dir($exportsDir)) {
        throw new RuntimeException('Directorio de exportaciones no encontrado.');
    }

    $items = [];
    $jobs = glob($exportsDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];

    foreach ($jobs as $jobPath) {
        $jobId = basename($jobPath);
        $files = glob($jobPath . DIRECTORY_SEPARATOR . '*') ?: [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $items[] = [
                'jobId' => $jobId,
                'name' => basename($file),
                'mtime' => filemtime($file),
                'download_url' => 'download.php?jobId=' . rawurlencode($jobId) . '&file=' . rawurlencode(basename($file)),
            ];
        }
    }

    usort($items, static function ($a, $b) {
        return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
    });

    $items = array_slice($items, 0, $limit);

    echo json_encode([
        'success' => true,
        'outputs' => $items,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

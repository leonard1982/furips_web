<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function fail(string $message): void
{
    header('HTTP/1.1 404 Not Found');
    echo $message;
    exit;
}

$jobId = $_GET['jobId'] ?? '';
$fileName = $_GET['file'] ?? '';

if ($jobId === '' || $fileName === '') {
    fail('Identificador de trabajo o nombre de archivo faltante.');
}

$config = require __DIR__ . '/config.php';
$jobFile = $config['storage']['jobs'] . DIRECTORY_SEPARATOR . $jobId . '.json';
if (!is_file($jobFile)) {
    fail('Trabajo no encontrado.');
}

$state = json_decode(file_get_contents($jobFile), true);
if (!is_array($state) || empty($state['outputs'])) {
    fail('No hay salidas registradas para este trabajo.');
}

$match = null;
foreach ($state['outputs'] as $output) {
    if (($output['name'] ?? '') === $fileName) {
        $match = $output;
        break;
    }
}

if ($match === null) {
    fail('Archivo no encontrado dentro del trabajo.');
}

$path = $match['exported'] ?? $match['source'];
if ($path === null || !is_file($path) || !is_readable($path)) {
    fail('El archivo ya no existe en disco.');
}

$mime = mime_content_type($path) ?: 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;

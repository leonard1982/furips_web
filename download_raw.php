<?php

declare(strict_types=1);

require __DIR__ . '/config.php';

function fail_raw(string $message): void
{
    header('HTTP/1.1 404 Not Found');
    echo $message;
    exit;
}

$config = require __DIR__ . '/config.php';
$baseDir = $config['tempo']['dir'];
$file = $_GET['file'] ?? '';

if ($file === '') {
    fail_raw('Archivo no especificado.');
}

$safeName = basename($file);
$path = $baseDir . DIRECTORY_SEPARATOR . $safeName;

if (!is_file($path) || !is_readable($path)) {
    fail_raw('Archivo no encontrado.');
}

$mime = mime_content_type($path) ?: 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $safeName . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;

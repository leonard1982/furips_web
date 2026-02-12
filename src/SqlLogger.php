<?php
declare(strict_types=1);

final class SqlLogger
{
    private $path;

    public function __construct(string $directory, string $jobId)
    {
        if ($directory === '' || !is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException("No se pudo crear el directorio de SQL logs: {$directory}");
            }
        }

        $this->path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $jobId . '.sql';
    }

    public function log(string $engine, string $sql): void
    {
        $timestamp = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $entry = sprintf("[%s][%s]%s%s%s", $timestamp, strtoupper($engine), PHP_EOL, trim($sql), PHP_EOL . PHP_EOL);
        file_put_contents($this->path, $entry, FILE_APPEND | LOCK_EX);
    }

    public function getPath(): string
    {
        return $this->path;
    }
}

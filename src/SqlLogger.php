<?php
declare(strict_types=1);

final class SqlLogger
{
    private $path;
    private $headerWritten = false;

    public function __construct(string $directory, string $jobId)
    {
        if ($directory === '' || !is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException("No se pudo crear el directorio de SQL logs: {$directory}");
            }
        }

        $this->path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $jobId . '.sql';
    }

    public function writeHeader(string $jobId, string $entityCode, string $entityName, string $startDate, string $endDate): void
    {
        if ($this->headerWritten) {
            return;
        }

        $timestamp = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $header = [
            '============================================================',
            'REGISTRO SQL - GENERACION FURIPS',
            "FECHA_HORA_GENERACION: {$timestamp}",
            "JOB_ID: {$jobId}",
            "ENTIDAD_CODIGO: {$entityCode}",
            "ENTIDAD_NOMBRE: {$entityName}",
            "FECHA_INICIO: {$startDate}",
            "FECHA_FIN: {$endDate}",
            '============================================================',
            '',
        ];

        file_put_contents($this->path, implode(PHP_EOL, $header) . PHP_EOL, LOCK_EX);
        $this->headerWritten = true;
    }

    public function log(string $engine, string $sql): void
    {
        $timestamp = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        [$database, $operation] = $this->normalizeEngine($engine);
        $entry = sprintf(
            "[%s][DB:%s][TIPO:%s]%s%s%s",
            $timestamp,
            $database,
            $operation,
            PHP_EOL,
            trim($sql),
            PHP_EOL . PHP_EOL
        );
        file_put_contents($this->path, $entry, FILE_APPEND | LOCK_EX);
    }

    public function getPath(): string
    {
        return $this->path;
    }

    private function normalizeEngine(string $engine): array
    {
        $value = strtolower(trim($engine));
        if ($value === 'firebird-exec') {
            return ['FIREBIRD', 'EXECUTE'];
        }
        if ($value === 'firebird') {
            return ['FIREBIRD', 'QUERY'];
        }
        if ($value === 'mysql') {
            return ['MYSQL', 'QUERY'];
        }

        return [strtoupper($value), 'QUERY'];
    }
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/SqlLogger.php';

final class FirebirdConnection
{
    private $config;

    /** @var SqlLogger|null */
    private $logger;

    public function __construct(array $firebirdConfig)
    {
        if (!extension_loaded('interbase')) {
            throw new RuntimeException('La extension interbase (ibase) no esta disponible en PHP.');
        }

        $this->config = $firebirdConfig;
    }

    public function setLogger(?SqlLogger $logger): void
    {
        $this->logger = $logger;
    }

    public function query(string $sql): array
    {
        $connection = ibase_connect(
            $this->config['dsn'],
            $this->config['user'],
            $this->config['password'],
            $this->config['charset']
        );

        if ($connection === false) {
            throw new RuntimeException('No se pudo establecer la conexion a Firebird: ' . ibase_errmsg());
        }

        $this->log('firebird', $sql);
        $result = ibase_query($connection, $sql);
        if ($result === false) {
            $error = ibase_errmsg();
            ibase_close($connection);
            throw new RuntimeException('Error en la consulta Firebird: ' . $error);
        }

        $rows = [];
        while ($row = ibase_fetch_assoc($result)) {
            $rows[] = $row;
        }

        $this->freeResult($result);
        ibase_close($connection);

        return $rows;
    }

    public function execute(string $sql): void
    {
        $connection = ibase_connect(
            $this->config['dsn'],
            $this->config['user'],
            $this->config['password'],
            $this->config['charset']
        );

        if ($connection === false) {
            throw new RuntimeException('No se pudo establecer la conexion a Firebird: ' . ibase_errmsg());
        }

        $this->log('firebird-exec', $sql);
        $result = ibase_query($connection, $sql);
        if ($result === false) {
            $error = ibase_errmsg();
            ibase_close($connection);
            throw new RuntimeException('Error en la consulta Firebird: ' . $error);
        }

        $this->freeResult($result);
        ibase_close($connection);
    }

    private function log(string $engine, string $sql): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->log($engine, $sql);
    }

    /**
     * Libera el resultado de consulta solo si es un recurso válido.
     */
    private function freeResult($result): void
    {
        if (is_resource($result)) {
            ibase_free_result($result);
        }
    }
}

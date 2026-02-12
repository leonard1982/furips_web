<?php

declare(strict_types=1);

require_once __DIR__ . '/SqlLogger.php';

final class MysqlConnection
{
    /**
     * @var \PDO
     */
    private $pdo;

    /**
     * @var SqlLogger|null
     */
    private $logger;

    public function __construct(array $config)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database']
        );

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $this->pdo = new \PDO($dsn, $config['user'], $config['password'], $options);
    }

    public function setLogger(?SqlLogger $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function query(string $sql): array
    {
        $this->log('mysql', $sql);

        return $this->pdo->query($sql)->fetchAll();
    }

    private function log(string $engine, string $sql): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->log($engine, $sql);
    }
}

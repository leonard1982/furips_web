<?php

declare(strict_types=1);

require_once __DIR__ . '/FirebirdConnection.php';

final class EntityRepository
{
    private $connection;

    public function __construct(FirebirdConnection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array<int, array{codigo:string, nombre:string}>
     */
    public function list(): array
    {
        $rows = $this->connection->query('SELECT codigo, nombre FROM entidad ORDER BY nombre');

        return array_map(static function (array $row): array {
            return [
                'codigo' => trim((string) ($row['CODIGO'] ?? $row['codigo'] ?? '')),
                'nombre' => trim((string) ($row['NOMBRE'] ?? $row['nombre'] ?? '')),
            ];
        }, $rows);
    }
}

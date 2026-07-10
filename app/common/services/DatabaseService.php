<?php
declare(strict_types=1);

namespace Common\Services;

use PDO;
use Phalcon\Config\ConfigInterface;

class DatabaseService
{
    private ?PDO $connection = null;

    public function __construct(private ConfigInterface $config)
    {
    }

    public function connection(): PDO
    {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        $this->connection = new PDO(
            sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                (string) $this->config->host,
                (string) $this->config->dbname
            ),
            (string) $this->config->username,
            (string) $this->config->password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        return $this->connection;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->connection()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $statement = $this->connection()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return $row ?: null;
    }
}

<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Persistence\Pdo;

use PDO;
use Phalcon\Config\ConfigInterface;

final class PdoConnection
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

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
        }

        $this->connection = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string) $this->config->host,
                (int) ($this->config->port ?? 3306),
                (string) $this->config->dbname
            ),
            (string) $this->config->username,
            (string) $this->config->password,
            $options
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

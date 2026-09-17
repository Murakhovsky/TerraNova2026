<?php

declare(strict_types=1);

namespace App\Infrastructure;

use PDO;

final class LegacyConnectionFactory
{
    public function create(
        string $host,
        int $port,
        string $database,
        string $user,
        string $password,
    ): PDO {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $database,
        );

        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}

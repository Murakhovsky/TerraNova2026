<?php

declare(strict_types=1);

namespace App\Infrastructure\Concurrency;

use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use RuntimeException;

final readonly class MySqlAdvisoryLock
{
    public function __construct(
        private PdoConnection $database,
    ) {
    }

    public function synchronized(string $key, callable $criticalSection, int $timeoutSeconds = 2): mixed
    {
        $key = trim($key);
        if ($key === '') {
            throw new RuntimeException('Lock key must be non-empty.');
        }

        $lockName = 'cos:' . substr(hash('sha256', $key), 0, 56);
        $connection = $this->database->connection();
        $acquire = $connection->prepare('SELECT GET_LOCK(:lock_name, :timeout_seconds)');
        $acquire->execute([
            'lock_name' => $lockName,
            'timeout_seconds' => max(0, min(30, $timeoutSeconds)),
        ]);

        if ((int) $acquire->fetchColumn() !== 1) {
            throw new RuntimeException('Could not acquire the requested COS advisory lock.');
        }

        try {
            return $criticalSection();
        } finally {
            $release = $connection->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute(['lock_name' => $lockName]);
        }
    }
}

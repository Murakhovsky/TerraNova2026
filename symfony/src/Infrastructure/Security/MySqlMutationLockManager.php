<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Application\Security\Contract\MutationLockManagerInterface;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use RuntimeException;
use Throwable;

final readonly class MySqlMutationLockManager implements MutationLockManagerInterface
{
    public function __construct(
        private PdoConnection $database,
    ) {
    }

    public function synchronized(
        string $scope,
        string $resourceId,
        callable $criticalSection,
        int $timeoutSeconds = 5,
    ): mixed {
        $scope = trim($scope);
        $resourceId = trim($resourceId);

        if ($scope === '' || $resourceId === '' || $timeoutSeconds < 0 || $timeoutSeconds > 30) {
            throw new RuntimeException('Invalid mutation lock request.');
        }

        $lockName = 'cos:' . substr(hash('sha256', $scope . ':' . $resourceId), 0, 58);
        $pdo = $this->database->connection();

        $acquire = $pdo->prepare('SELECT GET_LOCK(:lock_name, :timeout_seconds)');
        $acquire->bindValue('lock_name', $lockName);
        $acquire->bindValue('timeout_seconds', $timeoutSeconds, \PDO::PARAM_INT);
        $acquire->execute();

        if ((int) $acquire->fetchColumn() !== 1) {
            throw new RuntimeException('Mutation lock could not be acquired.');
        }

        try {
            return $criticalSection();
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $release->execute(['lock_name' => $lockName]);
            } catch (Throwable) {
            }
        }
    }
}

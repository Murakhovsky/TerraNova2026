<?php
declare(strict_types=1);

namespace Domains\Identity\Infrastructure\ReadModel\MySql;

use Domains\Identity\Infrastructure\Persistence\MySql\OrganizationMembershipSynchronizer;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use RuntimeException;
use Throwable;

/**
 * Compatibility administration facade that keeps membership-owned authorization in sync
 * while the legacy admin UI still writes role/status through tn_users.
 */
final class MembershipSynchronizedAdminDashboardService extends AdminDashboardService
{
    public function __construct(
        private PdoConnection $identityDatabase,
        private OrganizationMembershipSynchronizer $membershipSynchronizer,
    ) {
        parent::__construct($identityDatabase);
    }

    public function createUser(array $input): array
    {
        return $this->transactional(function () use ($input): array {
            $result = parent::createUser($input);
            if (($result['ok'] ?? false) !== true) {
                return $result;
            }

            $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
            $user = $this->identityDatabase->fetchOne(
                'SELECT id FROM tn_users WHERE email = :email LIMIT 1',
                ['email' => $email],
            );
            if ($user === null) {
                throw new RuntimeException('Created user could not be reloaded for membership synchronization.');
            }

            $this->membershipSynchronizer->syncHomeMembership((int) $user['id']);
            return $result;
        }, 'admin-user-create-membership');
    }

    public function updateUser(int $id, array $input, ?array $actor = null): array
    {
        return $this->transactional(function () use ($id, $input, $actor): array {
            $result = parent::updateUser($id, $input, $actor);
            if (($result['ok'] ?? false) !== true) {
                return $result;
            }

            $this->membershipSynchronizer->syncHomeMembership($id);
            return $result;
        }, 'admin-user-update-membership');
    }

    /** @param callable(): array $operation */
    private function transactional(callable $operation, string $label): array
    {
        $connection = $this->identityDatabase->connection();
        $ownsTransaction = !$connection->inTransaction();
        if ($ownsTransaction) {
            $connection->beginTransaction();
        }

        try {
            $result = $operation();
            if (($result['ok'] ?? false) !== true) {
                if ($ownsTransaction && $connection->inTransaction()) {
                    $connection->rollBack();
                }
                return $result;
            }

            if ($ownsTransaction) {
                $connection->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }
            error_log(sprintf('[%s] %s', $label, $error->getMessage()));
            return ['ok' => false, 'message' => 'Користувача не вдалося зберегти через помилку синхронізації доступу.'];
        }
    }
}

<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Persistence\MySql\Resilience;

use InvalidArgumentException;
use Kernel\Execution\ExecutionFailureException;
use Kernel\Resilience\Contract\CircuitBreakerStoreInterface;
use PDO;

final readonly class MysqlCircuitBreakerStore implements CircuitBreakerStoreInterface
{
    private const GLOBAL_ORGANIZATION = '_global';
    private const HALF_OPEN_PROBE_SECONDS = 30;

    public function __construct(private PDO $connection) {}

    public function assertAvailable(?string $organizationId, string $serviceKey): void
    {
        $organizationKey = $this->organizationKey($organizationId);
        $serviceKey = $this->serviceKey($serviceKey);

        $statement = $this->connection->prepare(
            'SELECT opened_until FROM cos_external_circuits '
            . 'WHERE organization_id = :organization_id AND service_key = :service_key LIMIT 1'
        );
        $statement->execute([
            'organization_id' => $organizationKey,
            'service_key' => $serviceKey,
        ]);
        $openedUntil = $statement->fetchColumn();
        if ($openedUntil === false || $openedUntil === null) {
            return;
        }

        $openCheck = $this->connection->prepare('SELECT :opened_until > UTC_TIMESTAMP(6)');
        $openCheck->execute(['opened_until' => $openedUntil]);
        if ((int) $openCheck->fetchColumn() === 1) {
            throw $this->openException($serviceKey);
        }

        // Cooldown expired. Exactly one caller receives a half-open probe lease; concurrent
        // callers keep seeing an open circuit until that probe succeeds or the lease expires.
        $probe = $this->connection->prepare(
            'UPDATE cos_external_circuits SET '
            . sprintf('opened_until = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL %d SECOND), ', self::HALF_OPEN_PROBE_SECONDS)
            . 'updated_at = UTC_TIMESTAMP(6) '
            . 'WHERE organization_id = :organization_id AND service_key = :service_key '
            . 'AND opened_until IS NOT NULL AND opened_until <= UTC_TIMESTAMP(6)'
        );
        $probe->execute([
            'organization_id' => $organizationKey,
            'service_key' => $serviceKey,
        ]);
        if ($probe->rowCount() !== 1) {
            throw $this->openException($serviceKey);
        }
    }

    public function recordSuccess(?string $organizationId, string $serviceKey): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO cos_external_circuits '
            . '(organization_id, service_key, consecutive_failures, opened_until, updated_at) '
            . 'VALUES (:organization_id, :service_key, 0, NULL, UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE consecutive_failures = 0, opened_until = NULL, updated_at = UTC_TIMESTAMP(6)'
        );
        $statement->execute([
            'organization_id' => $this->organizationKey($organizationId),
            'service_key' => $this->serviceKey($serviceKey),
        ]);
    }

    public function recordRetryableFailure(
        ?string $organizationId,
        string $serviceKey,
        int $failureThreshold,
        int $openSeconds,
    ): void {
        $failureThreshold = max(1, min($failureThreshold, 1000));
        $openSeconds = max(1, min($openSeconds, 86400));
        $statement = $this->connection->prepare(
            'INSERT INTO cos_external_circuits '
            . '(organization_id, service_key, consecutive_failures, opened_until, updated_at) '
            . 'VALUES (:organization_id, :service_key, 1, '
            . ($failureThreshold <= 1
                ? sprintf('DATE_ADD(UTC_TIMESTAMP(6), INTERVAL %d SECOND)', $openSeconds)
                : 'NULL')
            . ', UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'opened_until = IF(consecutive_failures + 1 >= ' . $failureThreshold . ', '
            . sprintf('DATE_ADD(UTC_TIMESTAMP(6), INTERVAL %d SECOND)', $openSeconds)
            . ', NULL), consecutive_failures = consecutive_failures + 1, updated_at = UTC_TIMESTAMP(6)'
        );
        $statement->execute([
            'organization_id' => $this->organizationKey($organizationId),
            'service_key' => $this->serviceKey($serviceKey),
        ]);
    }

    private function organizationKey(?string $organizationId): string
    {
        $organizationId = trim((string) $organizationId);
        return $organizationId !== '' ? $organizationId : self::GLOBAL_ORGANIZATION;
    }

    private function serviceKey(string $serviceKey): string
    {
        $serviceKey = trim($serviceKey);
        if ($serviceKey === '' || strlen($serviceKey) > 128) {
            throw new InvalidArgumentException('External service key must contain 1-128 characters.');
        }
        return $serviceKey;
    }

    private function openException(string $serviceKey): ExecutionFailureException
    {
        return ExecutionFailureException::externalUnavailable(
            sprintf('External circuit is open for %s.', $serviceKey),
        );
    }
}

<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Persistence\MySql\Queue;

use InvalidArgumentException;
use Kernel\Queue\Contract\RetryAwareJobQueueInterface;
use Kernel\Queue\Job;
use PDO;
use RuntimeException;
use Throwable;

final readonly class MysqlJobQueue implements RetryAwareJobQueueInterface
{
    private const CLAIM_SCAN_LIMIT = 16;

    public function __construct(
        private PDO $connection,
        private int $maxConcurrentJobsPerTenant = 4,
        private int $tenantLockTimeoutSeconds = 2,
    ) {
        if ($this->maxConcurrentJobsPerTenant < 1) {
            throw new InvalidArgumentException('Tenant job concurrency limit must be positive.');
        }
        if ($this->tenantLockTimeoutSeconds < 0 || $this->tenantLockTimeoutSeconds > 30) {
            throw new InvalidArgumentException('Tenant job lock timeout must be between 0 and 30 seconds.');
        }
    }

    public function enqueue(
        string $organizationId,
        string $type,
        array $payload,
        string $correlationId,
        ?string $idempotencyKey = null,
        int $maxAttempts = 5,
        int $timeoutSeconds = 60,
    ): string {
        $id = bin2hex(random_bytes(16));
        $statement = $this->connection->prepare(
            'INSERT IGNORE INTO cos_jobs (id, organization_id, type, payload, status, max_attempts, '
            . 'timeout_seconds, available_at, idempotency_key, correlation_id) VALUES '
            . "(:id, :organization_id, :type, :payload, 'PENDING', :max_attempts, :timeout_seconds, NOW(6), "
            . ':idempotency_key, :correlation_id)'
        );
        $statement->execute([
            'id' => $id,
            'organization_id' => $organizationId,
            'type' => $type,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'max_attempts' => max(1, $maxAttempts),
            'timeout_seconds' => max(1, $timeoutSeconds),
            'idempotency_key' => $idempotencyKey,
            'correlation_id' => $correlationId !== '' ? $correlationId : $id,
        ]);
        if ($statement->rowCount() === 1) return $id;

        if ($idempotencyKey !== null) {
            $existing = $this->connection->prepare(
                'SELECT id FROM cos_jobs WHERE organization_id = :organization_id AND idempotency_key = :key LIMIT 1'
            );
            $existing->execute(['organization_id' => $organizationId, 'key' => $idempotencyKey]);
            $existingId = $existing->fetchColumn();
            if ($existingId !== false) return (string) $existingId;
        }
        throw new RuntimeException('Unable to enqueue job.');
    }

    public function claim(string $workerId): ?Job
    {
        for ($scan = 0; $scan < self::CLAIM_SCAN_LIMIT; $scan++) {
            $lockName = null;
            $this->connection->beginTransaction();
            try {
                $row = $this->connection->query(
                    "SELECT * FROM cos_jobs WHERE status IN ('PENDING', 'FAILED') AND available_at <= NOW(6) "
                    . 'ORDER BY available_at, created_at LIMIT 1 FOR UPDATE SKIP LOCKED'
                )->fetch(PDO::FETCH_ASSOC);
                if ($row === false) {
                    $this->connection->commit();
                    return null;
                }

                $organizationId = (string) $row['organization_id'];
                $lockName = $this->tenantLockName($organizationId);
                if (!$this->acquireTenantLock($lockName)) {
                    $this->deferUnclaimedRow((string) $row['id']);
                    $this->connection->commit();
                    continue;
                }

                $this->purgeExpiredTenantLeases($organizationId);
                if ($this->activeTenantLeaseCount($organizationId) >= $this->maxConcurrentJobsPerTenant) {
                    $this->deferUnclaimedRow((string) $row['id']);
                    $this->connection->commit();
                    continue;
                }

                $this->insertTenantLease(
                    (string) $row['id'],
                    $organizationId,
                    (int) $row['timeout_seconds'],
                );

                $updated = $this->connection->prepare(
                    "UPDATE cos_jobs SET status = 'RUNNING', attempts = attempts + 1, locked_at = NOW(6), "
                    . 'locked_by = :worker WHERE id = :id AND status IN (\'PENDING\', \'FAILED\')'
                );
                $updated->execute(['worker' => $workerId, 'id' => $row['id']]);
                if ($updated->rowCount() !== 1) {
                    $this->connection->rollBack();
                    continue;
                }

                $this->connection->commit();
                $row['attempts'] = (int) $row['attempts'] + 1;
                $row['locked_by'] = $workerId;
                return $this->hydrate($row);
            } catch (Throwable $exception) {
                if ($this->connection->inTransaction()) $this->connection->rollBack();
                throw $exception;
            } finally {
                if ($lockName !== null) {
                    $this->releaseTenantLock($lockName);
                }
            }
        }

        return null;
    }

    public function complete(Job $job): void
    {
        $statement = $this->connection->prepare(
            "UPDATE cos_jobs SET status = 'COMPLETED', completed_at = NOW(6), locked_at = NULL, locked_by = NULL, "
            . 'last_error = NULL WHERE id = :id AND status = \'RUNNING\' AND locked_by = :worker'
        );
        $statement->execute(['id' => $job->id, 'worker' => $job->claimedBy]);
        if ($statement->rowCount() === 1) {
            $this->releaseTenantLease($job);
        }
    }

    public function fail(Job $job, string $error): void
    {
        $this->failWithRetryPolicy($job, $error, true);
    }

    public function failWithRetryPolicy(Job $job, string $error, bool $retryable): void
    {
        $dead = !$retryable || $job->attempts >= $job->maxAttempts;
        $delay = min(3600, 10 * (2 ** max(0, $job->attempts - 1)));
        $statement = $this->connection->prepare(
            'UPDATE cos_jobs SET status = :status, available_at = DATE_ADD(NOW(6), INTERVAL :delay SECOND), '
            . 'locked_at = NULL, locked_by = NULL, last_error = :error '
            . 'WHERE id = :id AND status = \'RUNNING\' AND locked_by = :worker'
        );
        $statement->execute([
            'status' => $dead ? 'DEAD' : 'FAILED',
            'delay' => $dead ? 0 : $delay,
            'error' => mb_substr($error, 0, 65535),
            'id' => $job->id,
            'worker' => $job->claimedBy,
        ]);
        if ($statement->rowCount() === 1) {
            $this->releaseTenantLease($job);
        }
    }

    public function recoverTimedOut(): int
    {
        $statement = $this->connection->prepare(
            "UPDATE cos_jobs SET status = CASE WHEN attempts >= max_attempts THEN 'DEAD' ELSE 'FAILED' END, "
            . "available_at = NOW(6), locked_at = NULL, locked_by = NULL, last_error = 'Worker lease timed out' "
            . "WHERE status = 'RUNNING' AND locked_at < DATE_SUB(NOW(6), INTERVAL timeout_seconds SECOND)"
        );
        $statement->execute();
        $recovered = $statement->rowCount();

        $cleanup = $this->connection->prepare(
            'DELETE FROM cos_tenant_execution_leases WHERE expires_at <= NOW(6)'
        );
        $cleanup->execute();

        return $recovered;
    }

    public function replayDead(?string $organizationId = null, ?string $jobId = null): int
    {
        $where = ["status = 'DEAD'"];
        $params = [];
        if ($organizationId !== null) {
            $where[] = 'organization_id = :organization_id';
            $params['organization_id'] = $organizationId;
        }
        if ($jobId !== null) {
            $where[] = 'id = :job_id';
            $params['job_id'] = $jobId;
        }
        $statement = $this->connection->prepare(
            "UPDATE cos_jobs SET status = 'PENDING', attempts = 0, available_at = NOW(6), "
            . 'locked_at = NULL, locked_by = NULL, completed_at = NULL, last_error = NULL WHERE '
            . implode(' AND ', $where)
        );
        $statement->execute($params);
        return $statement->rowCount();
    }

    private function deferUnclaimedRow(string $jobId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE cos_jobs SET available_at = DATE_ADD(NOW(6), INTERVAL 1 SECOND) '
            . "WHERE id = :id AND status IN ('PENDING', 'FAILED')"
        );
        $statement->execute(['id' => $jobId]);
    }

    private function acquireTenantLock(string $lockName): bool
    {
        $statement = $this->connection->prepare(
            sprintf('SELECT GET_LOCK(:lock_name, %d)', $this->tenantLockTimeoutSeconds)
        );
        $statement->execute(['lock_name' => $lockName]);
        return (int) $statement->fetchColumn() === 1;
    }

    private function releaseTenantLock(string $lockName): void
    {
        try {
            $statement = $this->connection->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $statement->execute(['lock_name' => $lockName]);
        } catch (Throwable) {
            // Named locks are connection-scoped and are also released when the connection closes.
        }
    }

    private function tenantLockName(string $organizationId): string
    {
        return 'cos.jobs.tenant.' . substr(hash('sha256', $organizationId), 0, 40);
    }

    private function purgeExpiredTenantLeases(string $organizationId): void
    {
        $statement = $this->connection->prepare(
            'DELETE FROM cos_tenant_execution_leases '
            . 'WHERE organization_id = :organization_id AND expires_at <= NOW(6)'
        );
        $statement->execute(['organization_id' => $organizationId]);
    }

    private function activeTenantLeaseCount(string $organizationId): int
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM cos_tenant_execution_leases '
            . 'WHERE organization_id = :organization_id AND expires_at > NOW(6)'
        );
        $statement->execute(['organization_id' => $organizationId]);
        return (int) $statement->fetchColumn();
    }

    private function insertTenantLease(string $jobId, string $organizationId, int $timeoutSeconds): void
    {
        $ttl = max(30, min(86400, $timeoutSeconds + 30));
        $statement = $this->connection->prepare(
            'INSERT INTO cos_tenant_execution_leases (lease_id, organization_id, expires_at, created_at) '
            . sprintf("VALUES (:lease_id, :organization_id, DATE_ADD(NOW(6), INTERVAL %d SECOND), NOW(6))", $ttl)
        );
        $statement->execute([
            'lease_id' => $this->tenantLeaseId($jobId),
            'organization_id' => $organizationId,
        ]);
    }

    private function releaseTenantLease(Job $job): void
    {
        $statement = $this->connection->prepare(
            'DELETE FROM cos_tenant_execution_leases '
            . 'WHERE lease_id = :lease_id AND organization_id = :organization_id'
        );
        $statement->execute([
            'lease_id' => $this->tenantLeaseId($job->id),
            'organization_id' => $job->organizationId,
        ]);
    }

    private function tenantLeaseId(string $jobId): string
    {
        return 'job:' . hash('sha256', $jobId);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Job
    {
        return new Job(
            (string) $row['id'],
            (string) $row['organization_id'],
            (string) $row['type'],
            json_decode((string) $row['payload'], true, flags: JSON_THROW_ON_ERROR),
            (int) $row['attempts'],
            (int) $row['max_attempts'],
            (int) $row['timeout_seconds'],
            (string) $row['correlation_id'],
            $row['idempotency_key'] !== null ? (string) $row['idempotency_key'] : null,
            (string) ($row['locked_by'] ?? ''),
        );
    }
}

<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Persistence\MySql\Queue;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Event\EventBus;
use Kernel\Queue\Contract\RetryAwareJobQueueInterface;
use Kernel\Queue\Event\AsyncOperationChanged;
use Kernel\Queue\Job;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use PDO;
use RuntimeException;
use Throwable;

final readonly class MysqlJobQueue implements RetryAwareJobQueueInterface
{
    private const CLAIM_RACE_RETRIES = 8;

    /**
     * Never let generic queue recovery replay a previously claimed canonical
     * Agent or an Action that could run a Tool with a remote side effect.
     * Pinned canonical Runs remain protected even after rollout is disabled.
     */
    private const CANONICAL_OWNED_JOB_SQL = <<<'SQL'
(
    (jobs.type = 'AGENT_RUN' AND (
        EXISTS (
            SELECT 1 FROM cos_kernel_execution_rollouts rollout
            WHERE rollout.organization_id = jobs.organization_id
              AND rollout.runtime_kind = 'agent' AND rollout.mode = 'canonical'
        )
        OR EXISTS (
            SELECT 1 FROM cos_workflow_runs workflow
            WHERE workflow.organization_id = jobs.organization_id
              AND workflow.run_id = CONCAT('ka:', SUBSTRING(SHA2(
                  CONCAT(jobs.organization_id, CHAR(0), 'agent', CHAR(0), jobs.id), 256
              ), 1, 32))
              AND workflow.definition_id = 'kernel.agent_execution'
        )
    ))
    OR (jobs.type = 'ACTION_EXECUTION' AND EXISTS (
        SELECT 1 FROM cos_actions action_record
        WHERE action_record.organization_id = jobs.organization_id
          AND action_record.id = JSON_UNQUOTE(JSON_EXTRACT(
              IF(JSON_VALID(jobs.payload), jobs.payload, '{}'), '$.action_id'
          ))
          AND action_record.type = 'kernel.tool.invoke'
    ))
)
SQL;

    public function __construct(
        private PDO $connection,
        private TransactionManagerInterface $transactions,
        private EventBus $events,
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
        return $this->transactions->transactional(function () use (
            $organizationId,
            $type,
            $payload,
            $correlationId,
            $idempotencyKey,
            $maxAttempts,
            $timeoutSeconds,
        ): string {
            $id = bin2hex(random_bytes(16));
            $effectiveCorrelationId = $correlationId !== '' ? $correlationId : $id;
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
                'correlation_id' => $effectiveCorrelationId,
            ]);

            if ($statement->rowCount() === 1) {
                $this->publishChanged($organizationId, $id, 'PENDING', $effectiveCorrelationId);

                return $id;
            }

            if ($idempotencyKey !== null) {
                $existing = $this->connection->prepare(
                    'SELECT id FROM cos_jobs WHERE organization_id = :organization_id AND idempotency_key = :key LIMIT 1'
                );
                $existing->execute(['organization_id' => $organizationId, 'key' => $idempotencyKey]);
                $existingId = $existing->fetchColumn();
                if ($existingId !== false) {
                    return (string) $existingId;
                }
            }

            throw new RuntimeException('Unable to enqueue job.');
        });
    }

    public function claim(string $workerId): ?Job
    {
        $lockWaitNanoseconds = 0;

        // The SQL admission filter prevents a noisy tenant from occupying the front of the
        // queue. We still serialize the final per-tenant lease check because concurrent
        // workers can race after the eligibility snapshot.
        for ($race = 0; $race < self::CLAIM_RACE_RETRIES; $race++) {
            $lockName = null;
            $this->connection->beginTransaction();
            try {
                $row = $this->connection->query($this->claimSql())->fetch(PDO::FETCH_ASSOC);
                if ($row === false) {
                    $this->connection->commit();

                    return null;
                }

                $organizationId = (string) $row['organization_id'];
                $lockName = $this->tenantLockName($organizationId);
                $lockStarted = hrtime(true);
                $lockAcquired = $this->acquireTenantLock($lockName);
                $lockWaitNanoseconds += max(0, hrtime(true) - $lockStarted);
                if (!$lockAcquired) {
                    $this->connection->rollBack();
                    continue;
                }

                $this->purgeExpiredTenantLeases($organizationId);
                if ($this->activeTenantLeaseCount($organizationId) >= $this->maxConcurrentJobsPerTenant) {
                    // Another worker filled the last slot after our SELECT. Do not mutate the
                    // job or consume an attempt; retry admission against a fresh snapshot.
                    $this->connection->rollBack();
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

                $row['attempts'] = (int) $row['attempts'] + 1;
                $row['locked_by'] = $workerId;
                $this->publishChanged(
                    $organizationId,
                    (string) $row['id'],
                    'RUNNING',
                    (string) $row['correlation_id'],
                );
                $this->connection->commit();

                return $this->hydrate($row, $lockWaitNanoseconds / 1_000_000);
            } catch (Throwable $exception) {
                if ($this->connection->inTransaction()) {
                    $this->connection->rollBack();
                }
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
        $this->transactions->transactional(function () use ($job): void {
            $statement = $this->connection->prepare(
                "UPDATE cos_jobs SET status = 'COMPLETED', completed_at = NOW(6), locked_at = NULL, locked_by = NULL, "
                . 'last_error = NULL WHERE id = :id AND status = \'RUNNING\' AND locked_by = :worker'
            );
            $statement->execute(['id' => $job->id, 'worker' => $job->claimedBy]);
            if ($statement->rowCount() === 1) {
                $this->releaseTenantLease($job);
                $this->publishChanged($job->organizationId, $job->id, 'COMPLETED', $job->correlationId);
            }
        });
    }

    public function fail(Job $job, string $error): void
    {
        $this->failWithRetryPolicy($job, $error, true);
    }

    public function failWithRetryPolicy(Job $job, string $error, bool $retryable): void
    {
        $this->transactions->transactional(function () use ($job, $error, $retryable): void {
            $dead = !$retryable || $job->attempts >= $job->maxAttempts;
            $delay = min(3600, 10 * (2 ** max(0, $job->attempts - 1)));
            $status = $dead ? 'DEAD' : 'FAILED';
            $statement = $this->connection->prepare(
                "UPDATE cos_jobs jobs SET jobs.status = CASE WHEN "
                . self::CANONICAL_OWNED_JOB_SQL
                . " THEN 'DEAD' ELSE :status END, "
                . 'jobs.available_at = DATE_ADD(NOW(6), INTERVAL :delay SECOND), '
                . 'jobs.locked_at = NULL, jobs.locked_by = NULL, jobs.last_error = :error '
                . "WHERE jobs.id = :id AND jobs.status = 'RUNNING' AND jobs.locked_by = :worker"
            );
            $statement->execute([
                'status' => $status,
                'delay' => $dead ? 0 : $delay,
                'error' => mb_substr($error, 0, 65535),
                'id' => $job->id,
                'worker' => $job->claimedBy,
            ]);
            if ($statement->rowCount() === 1) {
                $this->releaseTenantLease($job);
                $check = $this->connection->prepare(
                    'SELECT status FROM cos_jobs WHERE organization_id = :org AND id = :id'
                );
                $check->execute(['org' => $job->organizationId, 'id' => $job->id]);
                $this->publishChanged(
                    $job->organizationId, $job->id, (string) $check->fetchColumn(), $job->correlationId
                );
            }
        });
    }

    public function recoverTimedOut(): int
    {
        return $this->transactions->transactional(function (): int {
            $affected = $this->connection->query(
                "SELECT jobs.organization_id,jobs.id,jobs.correlation_id "
                . "FROM cos_jobs jobs WHERE jobs.status = 'RUNNING' "
                . "AND jobs.locked_at < DATE_SUB(NOW(6), INTERVAL jobs.timeout_seconds SECOND) "
                . 'FOR UPDATE'
            )->fetchAll(PDO::FETCH_ASSOC);

            $statement = $this->connection->prepare(
                "UPDATE cos_jobs jobs SET jobs.status = CASE "
                . "WHEN " . self::CANONICAL_OWNED_JOB_SQL . " THEN 'DEAD' "
                . "WHEN jobs.attempts >= jobs.max_attempts THEN 'DEAD' ELSE 'FAILED' END, "
                . "jobs.available_at = NOW(6), jobs.locked_at = NULL, "
                . "jobs.locked_by = NULL, jobs.last_error = 'Worker lease timed out' "
                . "WHERE jobs.status = 'RUNNING' "
                . "AND jobs.locked_at < DATE_SUB(NOW(6), INTERVAL jobs.timeout_seconds SECOND)"
            );
            $statement->execute();
            $recovered = $statement->rowCount();

            $this->connection->prepare(
                'DELETE FROM cos_tenant_execution_leases WHERE expires_at <= NOW(6)'
            )->execute();

            $check = $this->connection->prepare(
                'SELECT status FROM cos_jobs WHERE organization_id = :org AND id = :id'
            );
            foreach ($affected as $row) {
                $check->execute(['org' => $row['organization_id'], 'id' => $row['id']]);
                $status = (string) $check->fetchColumn();
                if (!in_array($status, ['FAILED', 'DEAD'], true)) {
                    continue;
                }
                $this->publishChanged(
                    (string) $row['organization_id'], (string) $row['id'],
                    $status, (string) $row['correlation_id']
                );
            }
            return $recovered;
        });
    }

    public function replayDead(?string $organizationId = null, ?string $jobId = null): int
    {
        return $this->transactions->transactional(function () use ($organizationId, $jobId): int {
            $where = ["jobs.status = 'DEAD'", 'NOT ' . self::CANONICAL_OWNED_JOB_SQL];
            $params = [];
            if ($organizationId !== null) {
                $where[] = 'jobs.organization_id = :organization_id';
                $params['organization_id'] = $organizationId;
            }
            if ($jobId !== null) {
                $where[] = 'jobs.id = :job_id';
                $params['job_id'] = $jobId;
            }

            $selector = $this->connection->prepare(
                'SELECT jobs.organization_id,jobs.id,jobs.correlation_id '
                . 'FROM cos_jobs jobs WHERE ' . implode(' AND ', $where) . ' FOR UPDATE'
            );
            $selector->execute($params);
            $affected = $selector->fetchAll(PDO::FETCH_ASSOC);

            $statement = $this->connection->prepare(
                "UPDATE cos_jobs jobs SET jobs.status = 'PENDING', jobs.attempts = 0, "
                . 'jobs.available_at = NOW(6), jobs.locked_at = NULL, '
                . 'jobs.locked_by = NULL, jobs.completed_at = NULL, jobs.last_error = NULL WHERE '
                . implode(' AND ', $where)
            );
            $statement->execute($params);
            $replayed = $statement->rowCount();

            foreach ($affected as $row) {
                $this->publishChanged(
                    (string) $row['organization_id'], (string) $row['id'],
                    'PENDING', (string) $row['correlation_id']
                );
            }
            return $replayed;
        });
    }

    private function publishChanged(
        string $organizationId,
        string $jobId,
        string $status,
        string $correlationId,
    ): void {
        $this->events->publish(AsyncOperationChanged::create(
            $organizationId,
            $jobId,
            $status,
            $correlationId,
        ));
    }

    private function claimSql(): string
    {
        $limit = $this->maxConcurrentJobsPerTenant;
        $activeLeaseCount = "(SELECT COUNT(*) FROM cos_tenant_execution_leases leases "
            . "WHERE leases.organization_id = jobs.organization_id AND leases.expires_at > NOW(6))";

        return "SELECT jobs.* FROM cos_jobs jobs "
            . "WHERE jobs.status IN ('PENDING', 'FAILED') AND jobs.available_at <= NOW(6) "
            . "AND (jobs.attempts = 0 OR NOT " . self::CANONICAL_OWNED_JOB_SQL . ") "
            . "AND {$activeLeaseCount} < {$limit} "
            . "ORDER BY {$activeLeaseCount} ASC, jobs.available_at ASC, jobs.created_at ASC "
            . 'LIMIT 1 FOR UPDATE SKIP LOCKED';
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
    private function hydrate(array $row, float $lockWaitMs = 0.0): Job
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
            isset($row['available_at']) ? new DateTimeImmutable((string) $row['available_at']) : null,
            max(0.0, $lockWaitMs),
        );
    }
}

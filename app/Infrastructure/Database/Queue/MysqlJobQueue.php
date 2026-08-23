<?php
declare(strict_types=1);

namespace Infrastructure\Database\Queue;

use DateTimeImmutable;
use Kernel\Queue\Contract\JobQueueInterface;
use Kernel\Queue\Job;
use PDO;
use RuntimeException;
use Throwable;

final readonly class MysqlJobQueue implements JobQueueInterface
{
    public function __construct(private PDO $connection) {}

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

            $updated = $this->connection->prepare(
                "UPDATE cos_jobs SET status = 'RUNNING', attempts = attempts + 1, locked_at = NOW(6), "
                . 'locked_by = :worker WHERE id = :id AND status IN (\'PENDING\', \'FAILED\')'
            );
            $updated->execute(['worker' => $workerId, 'id' => $row['id']]);
            if ($updated->rowCount() !== 1) {
                $this->connection->rollBack();
                return null;
            }
            $this->connection->commit();
            $row['attempts'] = (int) $row['attempts'] + 1;
            $row['locked_by'] = $workerId;
            return $this->hydrate($row);
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) $this->connection->rollBack();
            throw $exception;
        }
    }

    public function complete(Job $job): void
    {
        $statement = $this->connection->prepare(
            "UPDATE cos_jobs SET status = 'COMPLETED', completed_at = NOW(6), locked_at = NULL, locked_by = NULL, "
            . 'last_error = NULL WHERE id = :id AND status = \'RUNNING\''
        );
        $statement->execute(['id' => $job->id]);
    }

    public function fail(Job $job, string $error): void
    {
        $dead = $job->attempts >= $job->maxAttempts;
        $delay = min(3600, 10 * (2 ** max(0, $job->attempts - 1)));
        $statement = $this->connection->prepare(
            'UPDATE cos_jobs SET status = :status, available_at = DATE_ADD(NOW(6), INTERVAL :delay SECOND), '
            . 'locked_at = NULL, locked_by = NULL, last_error = :error WHERE id = :id AND status = \'RUNNING\''
        );
        $statement->execute([
            'status' => $dead ? 'DEAD' : 'FAILED',
            'delay' => $dead ? 0 : $delay,
            'error' => mb_substr($error, 0, 65535),
            'id' => $job->id,
        ]);
    }

    public function recoverTimedOut(): int
    {
        $statement = $this->connection->prepare(
            "UPDATE cos_jobs SET status = CASE WHEN attempts >= max_attempts THEN 'DEAD' ELSE 'FAILED' END, "
            . "available_at = NOW(6), locked_at = NULL, locked_by = NULL, last_error = 'Worker lease timed out' "
            . "WHERE status = 'RUNNING' AND locked_at < DATE_SUB(NOW(6), INTERVAL timeout_seconds SECOND)"
        );
        $statement->execute();
        return $statement->rowCount();
    }

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

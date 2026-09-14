<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Persistence\MySql\Action;

use DateTimeImmutable;
use Kernel\Action\Action;
use Kernel\Action\ActionExecutionClaim;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\ActionRepositoryInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\StaleActionExecutionClaimException;
use PDO;
use Throwable;

final readonly class MysqlActionRepository implements ActionRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function save(Action $action): Action
    {
        $statement = $this->connection->prepare(
            'INSERT IGNORE INTO cos_actions (id, organization_id, type, target_type, target_id, parameters, '
            . 'source_type, source_id, status, execution_mode, risk_level, idempotency_key, correlation_id, available_at) '
            . 'VALUES (:id, :organization_id, :type, :target_type, :target_id, :parameters, :source_type, :source_id, '
            . ':status, :execution_mode, :risk_level, :idempotency_key, :correlation_id, :available_at)'
        );
        $statement->execute([
            'id' => $action->id,
            'organization_id' => $action->organizationId,
            'type' => $action->type,
            'target_type' => $action->targetType,
            'target_id' => $action->targetId,
            'parameters' => json_encode($action->parameters, JSON_THROW_ON_ERROR),
            'source_type' => $action->sourceType,
            'source_id' => $action->sourceId,
            'status' => $action->status->value,
            'execution_mode' => $action->executionMode,
            'risk_level' => $action->riskLevel,
            'idempotency_key' => $action->idempotencyKey,
            'correlation_id' => $action->correlationId !== '' ? $action->correlationId : $action->id,
            'available_at' => $action->status === ActionStatus::Queued ? (new DateTimeImmutable())->format('Y-m-d H:i:s.u') : null,
        ]);

        if ($statement->rowCount() === 1) {
            return $action;
        }
        if ($action->idempotencyKey !== null) {
            $existing = $this->findByIdempotencyKey($action->organizationId, $action->idempotencyKey);
            if ($existing !== null) return $existing;
        }
        return $this->find($action->organizationId, $action->id) ?? $action;
    }

    public function find(string $organizationId, string $id): ?Action
    {
        $statement = $this->connection->prepare('SELECT * FROM cos_actions WHERE organization_id = :organization_id AND id = :id');
        $statement->execute(['organization_id' => $organizationId, 'id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function existsByIdempotencyKey(string $organizationId, string $key): bool
    {
        return $this->findByIdempotencyKey($organizationId, $key) !== null;
    }

    public function transition(string $organizationId, string $id, ActionStatus $from, ActionStatus $to): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE cos_actions SET status = :to_status, available_at = CASE WHEN :queued = 1 THEN NOW(6) ELSE available_at END '
            . 'WHERE organization_id = :organization_id AND id = :id AND status = :from_status'
        );
        $statement->execute([
            'organization_id' => $organizationId, 'id' => $id, 'from_status' => $from->value,
            'to_status' => $to->value, 'queued' => $to === ActionStatus::Queued ? 1 : 0,
        ]);
        return $statement->rowCount() === 1;
    }

    public function claimNext(string $workerId): ?ActionExecutionClaim
    {
        return $this->claimWhere(
            "status = 'QUEUED' AND (available_at IS NULL OR available_at <= NOW(6))",
            [],
            $workerId,
        );
    }

    public function claim(string $organizationId, string $id, string $workerId): ?ActionExecutionClaim
    {
        return $this->claimWhere(
            "organization_id = :organization_id AND id = :id AND status = 'QUEUED' "
            . 'AND (available_at IS NULL OR available_at <= NOW(6))',
            ['organization_id' => $organizationId, 'id' => $id],
            $workerId,
        );
    }

    private function claimWhere(string $where, array $parameters, string $workerId): ?ActionExecutionClaim
    {
        return $this->transactional(function () use ($where, $parameters, $workerId): ?ActionExecutionClaim {
            $select = $this->connection->prepare(
                'SELECT * FROM cos_actions WHERE ' . $where
                . ' ORDER BY available_at, created_at LIMIT 1 FOR UPDATE SKIP LOCKED'
            );
            $select->execute($parameters);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return null;
            }

            $update = $this->connection->prepare(
                "UPDATE cos_actions SET status = 'RUNNING', started_at = NOW(6) "
                . "WHERE id = :id AND organization_id = :organization_id AND status = 'QUEUED'"
            );
            $update->execute(['id' => $row['id'], 'organization_id' => $row['organization_id']]);
            if ($update->rowCount() !== 1) {
                return null;
            }

            $attemptStatement = $this->connection->prepare(
                'SELECT COALESCE(MAX(attempt), 0) + 1 FROM cos_action_attempts WHERE action_id = :action_id'
            );
            $attemptStatement->execute(['action_id' => $row['id']]);
            $attempt = (int) $attemptStatement->fetchColumn();
            $this->connection->prepare(
                "INSERT INTO cos_action_attempts (action_id, organization_id, attempt, worker_id, status, started_at) "
                . "VALUES (:action_id, :organization_id, :attempt, :worker_id, 'RUNNING', NOW(6))"
            )->execute([
                'action_id' => $row['id'], 'organization_id' => $row['organization_id'],
                'attempt' => $attempt, 'worker_id' => $workerId,
            ]);

            $row['status'] = ActionStatus::Running->value;
            return new ActionExecutionClaim($this->hydrate($row), $attempt, $workerId);
        });
    }

    public function finish(ActionExecutionClaim $claim, ExecutionResult $result): void
    {
        $this->transactional(function () use ($claim, $result): void {
            $action = $claim->action;
            $status = $result->successful ? ActionStatus::Completed : ActionStatus::Failed;

            $actionLock = $this->connection->prepare(
                'SELECT status FROM cos_actions '
                . 'WHERE id = :id AND organization_id = :organization_id FOR UPDATE'
            );
            $actionLock->execute([
                'id' => $action->id,
                'organization_id' => $action->organizationId,
            ]);
            $databaseStatus = $actionLock->fetchColumn();
            if ($databaseStatus !== ActionStatus::Running->value) {
                throw new StaleActionExecutionClaimException($claim);
            }

            $attemptLock = $this->connection->prepare(
                'SELECT attempt, worker_id, status FROM cos_action_attempts '
                . 'WHERE action_id = :action_id ORDER BY attempt DESC LIMIT 1 FOR UPDATE'
            );
            $attemptLock->execute(['action_id' => $action->id]);
            $attempt = $attemptLock->fetch(PDO::FETCH_ASSOC);
            if (
                $attempt === false
                || (int) $attempt['attempt'] !== $claim->attempt
                || (string) ($attempt['worker_id'] ?? '') !== $claim->workerId
                || (string) $attempt['status'] !== 'RUNNING'
            ) {
                throw new StaleActionExecutionClaimException($claim);
            }

            $attemptUpdate = $this->connection->prepare(
                'UPDATE cos_action_attempts SET status = :status, result = :result, error = :error, finished_at = NOW(6) '
                . "WHERE action_id = :action_id AND attempt = :attempt AND worker_id = :worker_id AND status = 'RUNNING'"
            );
            $attemptUpdate->execute([
                'action_id' => $action->id,
                'attempt' => $claim->attempt,
                'worker_id' => $claim->workerId,
                'status' => $result->successful ? 'COMPLETED' : 'FAILED',
                'result' => json_encode($result->data, JSON_THROW_ON_ERROR),
                'error' => $result->error,
            ]);
            if ($attemptUpdate->rowCount() !== 1) {
                throw new StaleActionExecutionClaimException($claim);
            }

            $actionUpdate = $this->connection->prepare(
                'UPDATE cos_actions SET status = :status, executed_at = :executed_at, failed_at = :failed_at, last_error = :error '
                . "WHERE id = :id AND organization_id = :organization_id AND status = 'RUNNING'"
            );
            $actionUpdate->execute([
                'id' => $action->id,
                'organization_id' => $action->organizationId,
                'status' => $status->value,
                'executed_at' => $result->successful ? (new DateTimeImmutable())->format('Y-m-d H:i:s.u') : null,
                'failed_at' => !$result->successful ? (new DateTimeImmutable())->format('Y-m-d H:i:s.u') : null,
                'error' => $result->error,
            ]);
            if ($actionUpdate->rowCount() !== 1) {
                throw new StaleActionExecutionClaimException($claim);
            }
        });
    }

    public function requeueStale(int $olderThanSeconds): int
    {
        $cutoff = (new DateTimeImmutable())->modify(sprintf('-%d seconds', max(30, $olderThanSeconds)));

        return $this->transactional(function () use ($cutoff): int {
            $select = $this->connection->prepare(
                "SELECT id, organization_id FROM cos_actions "
                . "WHERE status = 'RUNNING' AND started_at < :cutoff ORDER BY started_at FOR UPDATE"
            );
            $select->execute(['cutoff' => $cutoff->format('Y-m-d H:i:s.u')]);
            $rows = $select->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                return 0;
            }

            $closeAttempts = $this->connection->prepare(
                "UPDATE cos_action_attempts SET status = 'FAILED', error = 'Worker lease expired', finished_at = NOW(6) "
                . "WHERE action_id = :action_id AND status = 'RUNNING'"
            );
            $requeue = $this->connection->prepare(
                "UPDATE cos_actions SET status = 'QUEUED', available_at = NOW(6), started_at = NULL, last_error = 'Worker lease expired' "
                . "WHERE id = :id AND organization_id = :organization_id AND status = 'RUNNING' AND started_at < :cutoff"
            );

            $count = 0;
            foreach ($rows as $row) {
                $closeAttempts->execute(['action_id' => $row['id']]);
                $requeue->execute([
                    'id' => $row['id'],
                    'organization_id' => $row['organization_id'],
                    'cutoff' => $cutoff->format('Y-m-d H:i:s.u'),
                ]);
                $count += $requeue->rowCount();
            }

            return $count;
        });
    }

    private function findByIdempotencyKey(string $organizationId, string $key): ?Action
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM cos_actions WHERE organization_id = :organization_id AND idempotency_key = :key LIMIT 1'
        );
        $statement->execute(['organization_id' => $organizationId, 'key' => $key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    private function hydrate(array $row): Action
    {
        return new Action(
            (string) $row['id'], (string) $row['organization_id'], (string) $row['type'],
            $row['target_type'] !== null ? (string) $row['target_type'] : null,
            $row['target_id'] !== null ? (string) $row['target_id'] : null,
            json_decode((string) $row['parameters'], true, flags: JSON_THROW_ON_ERROR),
            (string) $row['source_type'], (string) $row['source_id'], (string) $row['execution_mode'],
            (string) $row['risk_level'], $row['idempotency_key'] !== null ? (string) $row['idempotency_key'] : null,
            new DateTimeImmutable((string) $row['created_at']), ActionStatus::from((string) $row['status']),
            (string) $row['correlation_id'],
        );
    }

    private function transactional(callable $operation): mixed
    {
        $ownsTransaction = !$this->connection->inTransaction();
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            $result = $operation();
            if ($ownsTransaction) {
                $this->connection->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }
}

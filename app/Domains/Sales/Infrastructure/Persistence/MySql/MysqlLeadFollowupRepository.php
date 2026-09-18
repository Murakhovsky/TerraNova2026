<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Domains\Sales\Application\Contract\LeadFollowupRepositoryInterface;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Application\DTO\ScheduleLeadFollowupCommand;
use PDO;
use Throwable;

final readonly class MysqlLeadFollowupRepository implements LeadFollowupRepositoryInterface
{
    private const OPERATION = 'lead_followup';

    public function __construct(private PDO $connection)
    {
    }

    public function schedule(ScheduleLeadFollowupCommand $command): OperationResult
    {
        try {
            $receipt = $this->connection->prepare(
                'SELECT mutation_id FROM sales_operation_receipts '
                . 'WHERE organization_id=:org AND operation_type=:type AND idempotency_key=:key LIMIT 1'
            );
            $receipt->execute([
                'org' => $command->organizationId,
                'type' => self::OPERATION,
                'key' => $command->idempotencyKey,
            ]);
            $existing = $receipt->fetchColumn();
            if ($existing !== false) {
                return OperationResult::success((string) $existing, ['duplicate' => true]);
            }

            $dueAt = $command->dueAt->format('Y-m-d H:i:s');
            $statement = $this->connection->prepare(
                'INSERT INTO tn_lead_activities (lead_id,user_id,activity_type,title,body,due_at,completed_at) '
                . 'SELECT l.id,l.assigned_user_id,"task",:title,:body,:due_at,NULL '
                . 'FROM tn_leads l WHERE l.id=:lead_id AND l.organization_id=:organization_id'
            );
            $statement->execute([
                'lead_id' => $command->leadReference,
                'organization_id' => $command->organizationId,
                'title' => $command->title,
                'body' => $command->body,
                'due_at' => $dueAt,
            ]);
            if ($statement->rowCount() !== 1) {
                return OperationResult::failure('Lead was not found in the current organization.');
            }

            $activityId = (string) $this->connection->lastInsertId();
            $this->connection->prepare(
                'UPDATE tn_leads SET next_contact_at=:due_at,updated_at=NOW() '
                . 'WHERE id=:lead_id AND organization_id=:organization_id'
            )->execute([
                'lead_id' => $command->leadReference,
                'organization_id' => $command->organizationId,
                'due_at' => $dueAt,
            ]);

            $this->connection->prepare(
                'INSERT INTO sales_operation_receipts(organization_id,operation_type,idempotency_key,mutation_id) '
                . 'VALUES(:org,:type,:key,:mutation_id)'
            )->execute([
                'org' => $command->organizationId,
                'type' => self::OPERATION,
                'key' => $command->idempotencyKey,
                'mutation_id' => $activityId,
            ]);

            return OperationResult::success($activityId, [
                'duplicate' => false,
                'due_at' => $command->dueAt->format(DATE_ATOM),
            ]);
        } catch (Throwable $exception) {
            return OperationResult::failure($exception->getMessage());
        }
    }
}

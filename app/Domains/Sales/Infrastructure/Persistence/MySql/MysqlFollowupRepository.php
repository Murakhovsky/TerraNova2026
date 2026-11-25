<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Domains\Sales\Application\Contract\FollowupRepositoryInterface;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Application\DTO\ScheduleFollowupCommand;
use PDO;
use Infrastructure\Platform\Persistence\ExternalReferenceStoreInterface;
use Throwable;

final readonly class MysqlFollowupRepository implements FollowupRepositoryInterface
{
    public function __construct(private PDO $connection, private ExternalReferenceStoreInterface $references)
    {
    }

    public function schedule(ScheduleFollowupCommand $command): OperationResult
    {
        $ownsTransaction = !$this->connection->inTransaction();
        try {
            if ($ownsTransaction) $this->connection->beginTransaction();
            $lock=$this->connection->prepare('SELECT status FROM tn_client_cases WHERE organization_id=? AND id=? FOR UPDATE');
            $lock->execute([$command->organizationId,$command->dealReference]);
            $status=$lock->fetchColumn();
            if ($status===false) throw new \DomainException('Deal not found.');
            $reference = 'action:' . $command->idempotencyKey;
            $externalId = $this->references->find($command->organizationId, 'aida', 'followup', $reference);
            if ($externalId !== null) {
                $read=$this->connection->prepare('SELECT title,body,due_at FROM tn_client_case_activities WHERE organization_id=? AND client_case_id=? AND id=? AND activity_type="followup"');
                $read->execute([$command->organizationId,$command->dealReference,$externalId]);
                $row=$read->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)||$row['title']!==$command->title||$row['body']!==$command->body||$row['due_at']!==$command->dueAt->format('Y-m-d H:i:s')) throw new \DomainException('Followup receipt conflict or changed native state.');
                if ($ownsTransaction) $this->connection->commit();
                return OperationResult::success((string) $externalId, ['duplicate' => true]);
            }

            if (in_array($status,['closed','lost'],true)) throw new \DomainException('Terminal deal cannot receive a followup.');
            if ($command->dueAt<=new \DateTimeImmutable()) throw new \DomainException('Followup must be in the future.');
            $dueAt = $command->dueAt->format('Y-m-d H:i:s');
            $receipts=new MysqlSalesMutationReceiptRepository($this->connection);
            $key=hash('sha256',$command->idempotencyKey);
            $pending='pending:'.bin2hex(random_bytes(16));
            if (!$receipts->claim($command->organizationId,'native.followup.schedule',$key,$pending)) throw new \DomainException('Followup receipt already claimed.');
            $statement = $this->connection->prepare(
                "INSERT INTO tn_client_case_activities (organization_id, client_case_id, person_id, user_id, activity_type, title, body, due_at) "
                . "SELECT :organization_id, id, person_id, assigned_user_id, 'followup', :title, :body, :due_at FROM tn_client_cases "
                . 'WHERE id = :id AND organization_id = :organization_scope'
            );
            $statement->execute([
                'id' => $command->dealReference,
                'title' => $command->title,
                'body' => $command->body,
                'due_at' => $dueAt,
                'organization_id' => $command->organizationId,
                'organization_scope' => $command->organizationId,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new \RuntimeException('Deal was not found in the current organization.');
            }
            $externalId = (string) $this->connection->lastInsertId();
            $this->references->put($command->organizationId, 'aida', 'followup', $externalId, $reference);
            if (!$receipts->complete($command->organizationId,'native.followup.schedule',$key,$pending,$externalId)) throw new \RuntimeException('Followup receipt completion failed.');
            $this->connection->prepare('UPDATE tn_client_cases SET next_contact_at=(
                SELECT MIN(a.due_at) FROM tn_client_case_activities a WHERE a.organization_id=:activity_org AND a.client_case_id=:activity_deal AND a.activity_type="followup" AND a.completed_at IS NULL AND a.cancelled_at IS NULL
                ),last_activity_at=NOW() WHERE organization_id=:org AND id=:deal')->execute([
                    'activity_org'=>$command->organizationId,'activity_deal'=>$command->dealReference,'org'=>$command->organizationId,'deal'=>$command->dealReference,
                ]);
            if ($ownsTransaction) $this->connection->commit();
            return OperationResult::success($externalId, ['duplicate' => false, 'due_at' => $command->dueAt->format(DATE_ATOM)]);
        } catch (Throwable $exception) {
            if (!$ownsTransaction) throw $exception;
            if ($this->connection->inTransaction()) $this->connection->rollBack();
            return OperationResult::failure('Followup scheduling rejected.');
        }
    }
}

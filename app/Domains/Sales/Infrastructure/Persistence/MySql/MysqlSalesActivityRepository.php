<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Domains\Sales\Application\Contract\SalesActivityRepositoryInterface;
use Domains\Sales\Application\DTO\RecordCompletedCallCommand;
use PDO;
use RuntimeException;

final readonly class MysqlSalesActivityRepository implements SalesActivityRepositoryInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function recordCompletedCall(RecordCompletedCallCommand $command): string
    {
        $statement = $this->connection->prepare(
            "INSERT INTO tn_client_case_activities "
            . '(organization_id, client_case_id, person_id, user_id, activity_type, title, body, completed_at) '
            . "SELECT :organization_id, id, :person_id, :user_id, 'call', :title, :body, NOW() "
            . 'FROM tn_client_cases WHERE id = :case_id AND organization_id = :organization_scope'
        );
        $statement->execute([
            'organization_id' => $command->organizationId,
            'organization_scope' => $command->organizationId,
            'case_id' => $command->dealReference,
            'person_id' => $command->personReference,
            'user_id' => $command->userReference,
            'title' => $command->title,
            'body' => $command->body,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Sales case was not found in the current organization.');
        }
        $activityId = (string) $this->connection->lastInsertId();
        $this->connection->prepare(
            'UPDATE tn_client_cases SET next_contact_at = NULL, last_activity_at = NOW() '
            . 'WHERE id = :case_id AND organization_id = :organization_id'
        )->execute(['case_id' => $command->dealReference, 'organization_id' => $command->organizationId]);
        $this->connection->prepare(
            'INSERT INTO sales_communications '
            . '(id, organization_id, deal_id, person_id, activity_id, channel, direction, sender, recipient, body, external_id, metadata, occurred_at) '
            . 'VALUES (:id, :organization_id, :deal_id, :person_id, :activity_id, "PHONE", "OUTBOUND", :sender, :recipient, :body, :external_id, :metadata, NOW())'
        )->execute([
            'id' => bin2hex(random_bytes(16)), 'organization_id' => $command->organizationId,
            'deal_id' => $command->dealReference, 'person_id' => $command->personReference, 'activity_id' => $activityId,
            'sender' => $command->actorId, 'recipient' => (string) $command->personReference, 'body' => $command->body,
            'external_id' => 'call:' . $command->eventId,
            'metadata' => json_encode(['duration_seconds' => $command->durationSeconds, 'result' => $command->result], JSON_THROW_ON_ERROR),
        ]);
        return $activityId;
    }
}

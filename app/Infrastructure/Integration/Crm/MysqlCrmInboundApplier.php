<?php
declare(strict_types=1);

namespace Infrastructure\Integration\Crm;

use Domains\Sales\Application\Contract\CrmInboundApplierInterface;
use Domains\Sales\Application\DTO\CrmInboxItem;
use Domains\Sales\Automation\Event\ClientCaseChanged;
use Domains\Sales\Automation\Event\DealStageChanged;
use Domains\Sales\Automation\Event\LeadChanged;
use PDO;
use RuntimeException;

final readonly class MysqlCrmInboundApplier implements CrmInboundApplierInterface
{
    private const EVENT_MAP = [
        'deal.updated' => ClientCaseChanged::TYPE,
        'deal.stage_changed' => DealStageChanged::TYPE,
        'lead.updated' => LeadChanged::TYPE,
        'lead.changed' => LeadChanged::TYPE,
        'message.received' => \Domains\Sales\Automation\Event\SalesEventType::MESSAGE_RECEIVED,
    ];

    public function __construct(private PDO $connection)
    {
    }

    public function apply(CrmInboxItem $item): array
    {
        $mappedEventType = self::EVENT_MAP[strtolower($item->eventType)] ?? null;
        if ($mappedEventType === null) {
            throw new RuntimeException('Unsupported CRM event type: ' . $item->eventType);
        }
        $entityType = strtolower(trim((string) ($item->payload['entity_type'] ?? 'deal')));
        $externalId = trim((string) ($item->payload['external_id'] ?? $item->payload['aggregate_id'] ?? ''));
        if ($externalId === '') throw new RuntimeException('CRM payload requires external_id.');
        $localId = $entityType==='message'
            ? $this->localReference($item,'deal',trim((string)($item->payload['deal_external_id']??$item->payload['deal_id']??'')))
            : $this->localReference($item, $entityType, $externalId);
        $changes = is_array($item->payload['changes'] ?? null) ? $item->payload['changes'] : [];

        if (in_array($entityType, ['deal', 'client_case'], true)) {
            $pipelineStatement = $this->connection->prepare('SELECT pipeline_id FROM tn_client_cases WHERE id=:id AND organization_id=:organization_id LIMIT 1');
            $pipelineStatement->execute(['id'=>$localId,'organization_id'=>$item->organizationId]);
            $pipelineId = (string)($pipelineStatement->fetchColumn() ?: '');
            $this->updateAllowed(
                'tn_client_cases',
                ['status', 'priority', 'next_contact_at'],
                $item->organizationId,
                $localId,
                $changes,
            );
            $aggregateType = 'deal';
        } elseif ($entityType === 'lead') {
            $this->updateAllowed(
                'tn_leads',
                ['status', 'client_case_id'],
                $item->organizationId,
                $localId,
                $changes,
            );
            $aggregateType = 'lead';
        } elseif ($entityType === 'message') {
            if(trim((string)($item->payload['body']??''))==='')throw new RuntimeException('Incoming CRM message requires body.');
            $aggregateType='deal';
        } else {
            throw new RuntimeException('Unsupported CRM entity type: ' . $entityType);
        }

        $sync = $this->connection->prepare(
            'INSERT INTO cos_sync_state '
            . '(organization_id, provider, entity_type, external_id, direction, status, payload_hash, attempts, last_attempt_at, synced_at) '
            . "VALUES (:organization_id, :provider, :entity_type, :external_id, 'INBOUND', 'SYNCED', :payload_hash, 1, NOW(6), NOW(6)) "
            . "ON DUPLICATE KEY UPDATE status = 'SYNCED', payload_hash = VALUES(payload_hash), attempts = attempts + 1, "
            . 'last_error = NULL, last_attempt_at = NOW(6), synced_at = NOW(6)'
        );
        $sync->execute([
            'organization_id' => $item->organizationId,
            'provider' => $item->provider,
            'entity_type' => $entityType,
            'external_id' => $externalId,
            'payload_hash' => hash('sha256', json_encode($item->payload, JSON_THROW_ON_ERROR)),
        ]);
        return [
            'event_type' => $mappedEventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $localId,
            'payload' => [...$item->payload, 'changes' => array_diff_key($changes, ['stage'=>true,'stage_id'=>true,'pipeline_id'=>true]), 'requested_stage' => $changes['stage_id'] ?? $changes['stage'] ?? null, 'requested_message' => $entityType==='message', 'pipeline_id' => $pipelineId ?? null, 'provider' => $item->provider, 'external_id' => $externalId],
        ];
    }

    private function localReference(CrmInboxItem $item, string $entityType, string $externalId): string
    {
        if ($item->provider === 'aida' && ctype_digit($externalId)) return $externalId;
        $statement = $this->connection->prepare(
            'SELECT cos_reference FROM cos_external_references WHERE organization_id = :organization_id '
            . 'AND provider = :provider AND entity_type = :entity_type AND external_id = :external_id LIMIT 1'
        );
        $statement->execute([
            'organization_id' => $item->organizationId,
            'provider' => $item->provider,
            'entity_type' => $entityType,
            'external_id' => $externalId,
        ]);
        $reference = $statement->fetchColumn();
        if ($reference === false) throw new RuntimeException('CRM entity has no local mapping.');
        return str_starts_with((string) $reference, 'entity:') ? substr((string) $reference, 7) : (string) $reference;
    }

    private function updateAllowed(string $table, array $allowed, string $organizationId, string $id, array $changes): void
    {
        $exists = $this->connection->prepare(
            'SELECT 1 FROM ' . $table . ' WHERE id = :id AND organization_id = :organization_id LIMIT 1'
        );
        $exists->execute(['id' => $id, 'organization_id' => $organizationId]);
        if ($exists->fetchColumn() === false) {
            throw new RuntimeException('CRM target does not exist in this organization.');
        }
        $sets = [];
        $parameters = ['id' => $id, 'organization_id' => $organizationId];
        foreach ($changes as $field => $value) {
            if (!in_array($field, $allowed, true)) continue;
            $sets[] = $field . ' = :' . $field;
            $parameters[$field] = $value;
        }
        if ($sets === []) return;
        $statement = $this->connection->prepare(
            'UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE id = :id AND organization_id = :organization_id'
        );
        $statement->execute($parameters);
        if ($statement->rowCount() > 1) throw new RuntimeException('CRM synchronization affected multiple records.');
    }
}

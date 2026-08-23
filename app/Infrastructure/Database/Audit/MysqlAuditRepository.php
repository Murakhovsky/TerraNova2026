<?php
declare(strict_types=1);

namespace Infrastructure\Database\Audit;

use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use PDO;

final readonly class MysqlAuditRepository implements AuditRepositoryInterface
{
    public function __construct(private PDO $connection) {}
    public function append(AuditEntry $entry): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO cos_audit_log (id, organization_id, category, actor_type, actor_id, subject_type, '
            . 'subject_id, action, reason, input_references, changes, result, metadata, correlation_id, created_at) '
            . 'VALUES (:id, :organization_id, :category, :actor_type, :actor_id, :subject_type, :subject_id, '
            . ':action, :reason, :input_references, :changes, :result, :metadata, :correlation_id, :created_at)'
        );
        $data = $entry->data;
        $statement->execute([
            'id' => $entry->id, 'organization_id' => $entry->organizationId, 'category' => $entry->category,
            'actor_type' => $entry->actorType, 'actor_id' => $entry->actorId,
            'subject_type' => $entry->subjectType, 'subject_id' => $entry->subjectId,
            'action' => $data['action'] ?? null, 'reason' => $entry->reason,
            'input_references' => json_encode($data['input_references'] ?? [], JSON_THROW_ON_ERROR),
            'changes' => json_encode($data['changes'] ?? [], JSON_THROW_ON_ERROR),
            'result' => json_encode($data['result'] ?? [], JSON_THROW_ON_ERROR),
            'metadata' => json_encode($data['metadata'] ?? [], JSON_THROW_ON_ERROR),
            'correlation_id' => $entry->correlationId,
            'created_at' => $entry->createdAt->format('Y-m-d H:i:s.u'),
        ]);
    }
}

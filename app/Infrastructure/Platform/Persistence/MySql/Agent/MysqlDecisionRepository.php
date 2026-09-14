<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Persistence\MySql\Agent;

use DateTimeImmutable;
use Kernel\Agent\AgentDefinition;
use Kernel\Agent\AgentInvocation;
use Kernel\Agent\AgentResult;
use Kernel\Agent\Contract\DecisionRepositoryInterface;
use PDO;

final readonly class MysqlDecisionRepository implements DecisionRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function save(string $runId, AgentDefinition $agent, AgentInvocation $invocation, AgentResult $result): string
    {
        $id = bin2hex(random_bytes(16));
        $statement = $this->connection->prepare(
            'INSERT INTO cos_decisions (id, organization_id, type, source_type, source_id, subject_type, subject_id, '
            . 'decision, reason, confidence, evidence, context_reference, correlation_id, created_at) VALUES '
            . "(:id, :organization_id, :type, 'AGENT', :source_id, :subject_type, :subject_id, :decision, :reason, "
            . ':confidence, :evidence, :context_reference, :correlation_id, :created_at)'
        );
        $statement->execute([
            'id' => $id,
            'organization_id' => $invocation->organizationId,
            'type' => $agent->name . '.decision',
            'source_id' => $runId,
            'subject_type' => $invocation->subjectType,
            'subject_id' => $invocation->subjectId,
            'decision' => $result->decision,
            'reason' => $result->reason,
            'confidence' => $result->confidence,
            'evidence' => json_encode($result->evidence, JSON_THROW_ON_ERROR),
            'context_reference' => json_encode([
                'agent_run_id' => $runId,
                'references' => $invocation->contextReferences,
            ], JSON_THROW_ON_ERROR),
            'correlation_id' => $invocation->correlationId,
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ]);
        return $id;
    }
}

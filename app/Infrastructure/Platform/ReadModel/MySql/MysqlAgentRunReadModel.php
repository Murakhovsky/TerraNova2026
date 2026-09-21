<?php

declare(strict_types=1);

namespace Infrastructure\Platform\ReadModel\MySql;

use DateTimeImmutable;
use JsonException;
use Kernel\Action\ActionStatus;
use Kernel\Agent\AgentActionProjection;
use Kernel\Agent\AgentRunProjection;
use Kernel\Agent\Contract\AgentRunReadModelInterface;
use Kernel\Agent\Model\AgentRunStatus;
use PDO;

final readonly class MysqlAgentRunReadModel implements AgentRunReadModelInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function recentForOrganization(string $organizationId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $statement = $this->connection->prepare(
            'SELECT * FROM cos_agent_runs WHERE organization_id=:organization_id '
            . 'ORDER BY created_at DESC LIMIT ' . $limit
        );
        $statement->execute(['organization_id' => $organizationId]);

        return array_map($this->hydrateRun(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function recentForSubject(
        string $organizationId,
        string $subjectType,
        string $subjectId,
        int $limit = 20,
    ): array {
        $limit = max(1, min(100, $limit));
        $types = [$subjectType];
        $parts = explode('.', $subjectType);
        $legacy = end($parts);
        if (is_string($legacy) && $legacy !== '' && $legacy !== $subjectType) {
            $types[] = $legacy;
        }

        $statement = $this->connection->prepare(
            'SELECT * FROM cos_agent_runs '
            . 'WHERE organization_id=:organization_id AND subject_type IN (:subject_type,:legacy_type) '
            . 'AND subject_id=:subject_id ORDER BY created_at DESC LIMIT ' . $limit
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'subject_type' => $types[0],
            'legacy_type' => $types[1] ?? $types[0],
            'subject_id' => $subjectId,
        ]);

        return array_map($this->hydrateRun(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(string $organizationId, string $runId): ?AgentRunProjection
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM cos_agent_runs WHERE organization_id=:organization_id AND id=:id LIMIT 1'
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'id' => $runId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrateRun($row);
    }

    public function actionsForRun(string $organizationId, string $runId): array
    {
        $statement = $this->connection->prepare(
            'SELECT a.id,a.organization_id,a.source_id,a.type,a.target_type,a.target_id,a.parameters,'
            . 'a.status,a.execution_mode,a.risk_level,a.created_at,'
            . 'ap.id approval_id,ap.status approval_status '
            . 'FROM cos_actions a '
            . 'LEFT JOIN cos_approvals ap ON ap.id=('
            . 'SELECT cap.id FROM cos_approvals cap '
            . 'WHERE cap.organization_id=a.organization_id AND cap.action_id=a.id '
            . 'ORDER BY cap.created_at DESC LIMIT 1'
            . ') '
            . "WHERE a.organization_id=:organization_id AND a.source_type='AGENT' AND a.source_id=:run_id "
            . 'ORDER BY a.created_at ASC'
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'run_id' => $runId,
        ]);

        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = ActionStatus::tryFrom((string) ($row['status'] ?? ''));
            if ($status === null) {
                continue;
            }

            $items[] = new AgentActionProjection(
                id: (string) $row['id'],
                organizationId: (string) $row['organization_id'],
                runId: (string) $row['source_id'],
                type: (string) $row['type'],
                targetType: $this->nullable($row['target_type'] ?? null),
                targetId: $this->nullable($row['target_id'] ?? null),
                parameters: $this->decode((string) ($row['parameters'] ?? '{}')),
                status: $status,
                executionMode: (string) $row['execution_mode'],
                riskLevel: (string) $row['risk_level'],
                createdAt: new DateTimeImmutable((string) $row['created_at']),
                approvalId: $this->nullable($row['approval_id'] ?? null),
                approvalStatus: $this->nullable($row['approval_status'] ?? null),
            );
        }

        return $items;
    }

    /** @param array<string,mixed> $row */
    private function hydrateRun(array $row): AgentRunProjection
    {
        return new AgentRunProjection(
            id: (string) $row['id'],
            organizationId: (string) $row['organization_id'],
            agentName: (string) $row['agent_name'],
            agentVersion: (string) $row['agent_version'],
            subjectType: $this->nullable($row['subject_type'] ?? null),
            subjectId: $this->nullable($row['subject_id'] ?? null),
            status: $this->status((string) $row['status']),
            output: $this->decode((string) ($row['output'] ?? '{}')),
            contextReference: $this->decode((string) ($row['context_reference'] ?? '{}')),
            confidence: $row['confidence'] === null ? null : (float) $row['confidence'],
            provider: (string) $row['provider'],
            model: (string) $row['model'],
            inputTokens: $row['input_tokens'] === null ? null : (int) $row['input_tokens'],
            outputTokens: $row['output_tokens'] === null ? null : (int) $row['output_tokens'],
            costAmount: $row['cost_amount'] === null ? null : (float) $row['cost_amount'],
            costCurrency: $this->nullable($row['cost_currency'] ?? null),
            durationMs: $row['duration_ms'] === null ? null : (int) $row['duration_ms'],
            error: $this->nullable($row['error'] ?? null),
            correlationId: (string) $row['correlation_id'],
            startedAt: $this->date($row['started_at'] ?? null),
            finishedAt: $this->date($row['finished_at'] ?? null),
            createdAt: new DateTimeImmutable((string) $row['created_at']),
        );
    }

    private function status(string $status): AgentRunStatus
    {
        return match (strtoupper(trim($status))) {
            'QUEUED' => AgentRunStatus::QUEUED,
            'RUNNING' => AgentRunStatus::RUNNING,
            'COMPLETED' => AgentRunStatus::COMPLETED,
            'FAILED', 'INVALID_OUTPUT' => AgentRunStatus::FAILED,
            'CANCELLED' => AgentRunStatus::CANCELLED,
            'WAITING' => AgentRunStatus::WAITING,
            default => AgentRunStatus::CREATED,
        };
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        if ($json === '') {
            return [];
        }

        try {
            $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($value) ? $value : [];
    }

    private function nullable(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        $value = $this->nullable($value);

        return $value === null ? null : new DateTimeImmutable($value);
    }
}

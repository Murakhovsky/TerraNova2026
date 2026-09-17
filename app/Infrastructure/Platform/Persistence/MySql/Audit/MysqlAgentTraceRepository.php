<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Persistence\MySql\Audit;

use DateTimeImmutable;
use Kernel\Shared\Domain\OrganizationId;
use PDO;
use Platform\Audit\Contract\AgentTraceRepositoryInterface;
use Platform\Audit\Model\ActivityStatus;
use Platform\Audit\Model\AgentRunHistory;
use Platform\Audit\Model\TraceEvent;
use Platform\Audit\Model\TraceEventType;

final readonly class MysqlAgentTraceRepository implements AgentTraceRepositoryInterface
{
    public function __construct(private PDO $connection)
    {
    }

    public function find(string $runId): ?AgentRunHistory
    {
        $statement = $this->connection->prepare(
            'SELECT t.sequence, t.event_type, t.payload, t.status, t.duration_ms, t.cost_amount, t.cost_unit, '
            . 't.error, t.occurred_at, r.organization_id, r.agent_name, r.correlation_id '
            . 'FROM cos_agent_trace_events t '
            . 'INNER JOIN cos_agent_runs r ON r.id = t.run_id '
            . 'WHERE t.run_id = :run_id ORDER BY t.sequence ASC'
        );
        $statement->execute(['run_id' => $runId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }

        $first = $rows[0];
        $history = new AgentRunHistory(
            $runId,
            OrganizationId::fromString((string) $first['organization_id']),
            (string) $first['agent_name'],
            (string) $first['correlation_id'],
        );

        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
            $history->append(new TraceEvent(
                sequence: (int) $row['sequence'],
                type: TraceEventType::from((string) $row['event_type']),
                payload: is_array($payload) ? $payload : [],
                status: ActivityStatus::from((string) $row['status']),
                timestamp: new DateTimeImmutable((string) $row['occurred_at']),
                durationMs: $row['duration_ms'] === null ? null : (int) $row['duration_ms'],
                cost: $row['cost_amount'] === null ? null : (float) $row['cost_amount'],
                costUnit: $row['cost_unit'] === null ? null : (string) $row['cost_unit'],
                error: $row['error'] === null ? null : (string) $row['error'],
            ));
        }

        return $history;
    }

    public function findByCorrelationId(OrganizationId $organizationId, string $correlationId): ?AgentRunHistory
    {
        $statement = $this->connection->prepare(
            'SELECT id FROM cos_agent_runs '
            . 'WHERE organization_id = :organization_id AND correlation_id = :correlation_id '
            . 'ORDER BY started_at DESC, created_at DESC LIMIT 1'
        );
        $statement->execute([
            'organization_id' => $organizationId->value(),
            'correlation_id' => $correlationId,
        ]);
        $runId = $statement->fetchColumn();

        return $runId === false ? null : $this->find((string) $runId);
    }

    public function save(AgentRunHistory $history): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO cos_agent_trace_events '
            . '(run_id, sequence, organization_id, event_type, payload, status, duration_ms, cost_amount, cost_unit, error, occurred_at) '
            . 'VALUES (:run_id, :sequence, :organization_id, :event_type, :payload, :status, :duration_ms, :cost_amount, :cost_unit, :error, :occurred_at) '
            . 'ON DUPLICATE KEY UPDATE event_type = VALUES(event_type), payload = VALUES(payload), status = VALUES(status), '
            . 'duration_ms = VALUES(duration_ms), cost_amount = VALUES(cost_amount), cost_unit = VALUES(cost_unit), '
            . 'error = VALUES(error), occurred_at = VALUES(occurred_at)'
        );

        foreach ($history->events() as $event) {
            $statement->execute([
                'run_id' => $history->runId,
                'sequence' => $event->sequence,
                'organization_id' => $history->organizationId->value(),
                'event_type' => $event->type->value,
                'payload' => json_encode($event->payload, JSON_THROW_ON_ERROR),
                'status' => $event->status->value,
                'duration_ms' => $event->durationMs,
                'cost_amount' => $event->cost,
                'cost_unit' => $event->costUnit,
                'error' => $event->error,
                'occurred_at' => $event->timestamp->format('Y-m-d H:i:s.u'),
            ]);
        }
    }
}

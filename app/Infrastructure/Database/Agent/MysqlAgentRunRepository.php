<?php
declare(strict_types=1);

namespace Infrastructure\Database\Agent;

use Kernel\Agent\AgentDefinition;
use Kernel\Agent\AgentInvocation;
use Kernel\Agent\AgentResult;
use Kernel\Agent\Contract\AgentRunRepositoryInterface;
use Kernel\Agent\LlmResponse;
use PDO;
use Throwable;

final readonly class MysqlAgentRunRepository implements AgentRunRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function start(string $runId, AgentDefinition $agent, AgentInvocation $invocation, array $context): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO cos_agent_runs '
            . '(id, organization_id, agent_name, agent_version, provider, model, prompt_version, schema_version, '
            . 'subject_type, subject_id, status, context_reference, input_snapshot, correlation_id, started_at) '
            . "VALUES (:id, :organization_id, :agent_name, :agent_version, 'pending', 'pending', :prompt_version, "
            . ":schema_version, :subject_type, :subject_id, 'RUNNING', :context_reference, :input_snapshot, "
            . ':correlation_id, NOW(6))'
        );
        $statement->execute([
            'id' => $runId,
            'organization_id' => $invocation->organizationId,
            'agent_name' => $agent->name,
            'agent_version' => $agent->version,
            'prompt_version' => $agent->promptVersion,
            'schema_version' => $agent->schemaVersion,
            'subject_type' => $invocation->subjectType,
            'subject_id' => $invocation->subjectId,
            'context_reference' => json_encode($invocation->contextReferences, JSON_THROW_ON_ERROR),
            'input_snapshot' => json_encode($context, JSON_THROW_ON_ERROR),
            'correlation_id' => $invocation->correlationId,
        ]);
    }

    public function complete(string $runId, AgentResult $result, LlmResponse $response, int $durationMs): void
    {
        $statement = $this->connection->prepare(
            "UPDATE cos_agent_runs SET status = 'COMPLETED', provider = :provider, model = :model, output = :output, "
            . 'confidence = :confidence, input_tokens = :input_tokens, output_tokens = :output_tokens, '
            . 'cost_amount = :cost_amount, cost_currency = :cost_currency, duration_ms = :duration_ms, finished_at = NOW(6) '
            . 'WHERE id = :id'
        );
        $statement->execute([
            'id' => $runId,
            'provider' => $response->provider,
            'model' => $response->model,
            'output' => json_encode($response->output, JSON_THROW_ON_ERROR),
            'confidence' => $result->confidence,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cost_amount' => $response->costAmount,
            'cost_currency' => $response->costCurrency,
            'duration_ms' => $durationMs,
        ]);
    }

    public function fail(string $runId, Throwable $error, int $durationMs, bool $invalidOutput = false): void
    {
        $statement = $this->connection->prepare(
            'UPDATE cos_agent_runs SET status = :status, error = :error, duration_ms = :duration_ms, '
            . 'finished_at = NOW(6) WHERE id = :id'
        );
        $statement->execute([
            'id' => $runId,
            'status' => $invalidOutput ? 'INVALID_OUTPUT' : 'FAILED',
            'error' => mb_substr($error->getMessage(), 0, 4000),
            'duration_ms' => $durationMs,
        ]);
    }
}

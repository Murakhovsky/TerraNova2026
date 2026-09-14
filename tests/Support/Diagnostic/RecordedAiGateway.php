<?php
declare(strict_types=1);

namespace Tests\Support\Diagnostic;

use DateTimeImmutable;
use Domains\Diagnostic\AI\AiGatewayInterface;
use Domains\Diagnostic\AI\AiOperationDefinition;
use Domains\Diagnostic\AI\AiRequest;
use Domains\Diagnostic\AI\AiResponse;
use Throwable;

final class RecordedAiGateway implements AiGatewayInterface
{
    public array $calls = [];

    public function __construct(private AiGatewayInterface $inner) {}

    public function execute(AiOperationDefinition $operation, AiRequest $request): AiResponse
    {
        $started = microtime(true);
        try {
            $response = $this->inner->execute($operation, $request);
            $this->calls[] = $this->audit(
                $operation,
                $request,
                $response,
                'SUCCESS',
                (int) ((microtime(true) - $started) * 1000),
            );
            return $response;
        } catch (Throwable $error) {
            $this->calls[] = $this->audit(
                $operation,
                $request,
                null,
                'FAILED',
                (int) ((microtime(true) - $started) * 1000),
            );
            throw $error;
        }
    }

    private function audit(
        AiOperationDefinition $operation,
        AiRequest $request,
        ?AiResponse $response,
        string $status,
        int $durationMs,
    ): array {
        return [
            'organization_id' => $request->organizationId,
            'diagnostic_id' => $request->diagnosticId,
            'operation' => $operation->operation->value,
            'operation_id' => $operation->operationId,
            'model' => $response?->model,
            'prompt_version' => $operation->promptVersion,
            'schema_version' => $operation->schemaVersion,
            'input_hash' => hash('sha256', json_encode($request->context, JSON_THROW_ON_ERROR)),
            'output_hash' => $response !== null
                ? hash('sha256', json_encode($response->output, JSON_THROW_ON_ERROR))
                : null,
            'tokens_input' => $response?->tokensInput ?? 0,
            'tokens_output' => $response?->tokensOutput ?? 0,
            'estimated_cost' => $response?->estimatedCost ?? 0,
            'duration_ms' => $durationMs,
            'status' => $status,
            'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];
    }
}

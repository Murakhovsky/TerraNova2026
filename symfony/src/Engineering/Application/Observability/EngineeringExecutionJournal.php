<?php
declare(strict_types=1);

namespace App\Engineering\Application\Observability;

use Throwable;

final readonly class EngineeringExecutionJournal
{
    public function __construct(private EngineeringExecutionEventStoreInterface $events) {}

    /**
     * @template T
     * @param callable():T $operation
     * @param callable(T):array<string,mixed>|null $details
     * @return T
     */
    public function around(
        string $featureId,
        string $workflowId,
        string $category,
        string $action,
        string $summary,
        string $correlationId,
        callable $operation,
        ?string $agentRunId = null,
        ?callable $details = null,
    ): mixed {
        $this->events->append(
            $featureId,
            $workflowId,
            $category,
            $action,
            'STARTED',
            $summary,
            agentRunId: $agentRunId,
            correlationId: $correlationId,
        );
        $started = hrtime(true);

        try {
            $result = $operation();
            $duration = (int) round((hrtime(true) - $started) / 1_000_000);
            $payload = $details !== null ? $details($result) : [];
            $this->events->append(
                $featureId,
                $workflowId,
                $category,
                $action,
                'COMPLETED',
                $summary,
                is_array($payload) ? $payload : [],
                $agentRunId,
                $correlationId,
                $duration,
            );
            return $result;
        } catch (Throwable $error) {
            $duration = (int) round((hrtime(true) - $started) / 1_000_000);
            $this->events->append(
                $featureId,
                $workflowId,
                $category,
                $action,
                'FAILED',
                $summary,
                [],
                $agentRunId,
                $correlationId,
                $duration,
                $error->getMessage(),
            );
            throw $error;
        }
    }

    /** @param array<string,mixed> $details */
    public function event(
        string $featureId,
        string $workflowId,
        string $category,
        string $action,
        string $status,
        string $summary,
        string $correlationId,
        array $details = [],
        ?string $agentRunId = null,
        ?string $error = null,
    ): void {
        $this->events->append(
            $featureId,
            $workflowId,
            $category,
            $action,
            $status,
            $summary,
            $details,
            $agentRunId,
            $correlationId,
            null,
            $error,
        );
    }
}

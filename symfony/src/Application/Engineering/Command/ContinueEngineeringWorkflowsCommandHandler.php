<?php
declare(strict_types=1);

namespace App\Application\Engineering\Command;

use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Application\Service\EngineeringContinueService;
use App\Engineering\Domain\Workflow\EngineeringId;
use Kernel\Application\Command\CommandHandlerInterface;
use Throwable;

final readonly class ContinueEngineeringWorkflowsCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringContinueService $engineering,
        private string $organizationId,
        private int $limit = 20,
    ) {}

    public function __invoke(ContinueEngineeringWorkflowsCommand $command): array
    {
        $rows = $this->workflows->queueForOrganization($this->organizationId, $this->limit);
        $results = [];

        foreach ($rows as $row) {
            $featureId = (string) ($row['feature_id'] ?? '');
            if ($featureId === '') continue;

            try {
                $result = $this->engineering->continueFeature(
                    $featureId,
                    $this->organizationId,
                    'engineering:scheduler:'.$featureId.':'.EngineeringId::generate(),
                );
                $results[] = [
                    'feature_id' => $featureId,
                    'priority' => (string) ($row['priority'] ?? 'P2'),
                    'status' => 'continued',
                    'state' => $result->state,
                    'next' => $result->next->type->value,
                ];
            } catch (Throwable $error) {
                $results[] = [
                    'feature_id' => $featureId,
                    'priority' => (string) ($row['priority'] ?? 'P2'),
                    'status' => 'failed',
                    'error' => mb_substr($error->getMessage(), 0, 500),
                ];
            }
        }

        return [
            'trigger' => trim($command->trigger) !== '' ? trim($command->trigger) : 'scheduler',
            'queue_policy' => 'priority_fifo',
            'worker_concurrency' => 1,
            'candidates' => count($rows),
            'results' => $results,
        ];
    }
}

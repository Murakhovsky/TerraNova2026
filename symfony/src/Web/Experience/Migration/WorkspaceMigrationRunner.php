<?php
declare(strict_types=1);

namespace App\Web\Experience\Migration;

use App\Web\Experience\Delivery\PageDeliveryWorkflow;
use RuntimeException;

final readonly class WorkspaceMigrationRunner
{
    public function __construct(
        private WorkspaceMigrationPlanner $planner,
        private PageDeliveryWorkflow $delivery,
    ) {}

    /** @return list<array<string,mixed>> */
    public function start(
        string $organizationId,
        string $correlationPrefix,
        int $limit = 1,
        ?string $stage = null,
    ): array {
        $plan = $this->planner->plan();

        if ($plan->blocked) {
            throw new RuntimeException('EX-005 is blocked: '.$plan->blockedReason);
        }

        if ($limit < 1 || $limit > 20) {
            throw new RuntimeException('Migration batch limit must be between 1 and 20.');
        }

        $queue = [];
        foreach ($plan->stages as $candidateStage) {
            if ($stage !== null && $candidateStage['name'] !== $stage) {
                continue;
            }
            foreach ($candidateStage['pages'] as $page) {
                $queue[] = $page;
            }
        }

        $started = [];
        foreach (array_slice($queue, 0, $limit) as $index => $page) {
            $started[] = [
                'page' => $page,
                'delivery' => $this->delivery->start(
                    $page['page_id'],
                    $organizationId,
                    $correlationPrefix.'-'.($index + 1),
                ),
            ];
        }

        return $started;
    }
}

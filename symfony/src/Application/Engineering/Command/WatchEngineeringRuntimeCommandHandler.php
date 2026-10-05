<?php
declare(strict_types=1);

namespace App\Application\Engineering\Command;

use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class WatchEngineeringRuntimeCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private EngineeringWorkflowStoreInterface $workflows,
        private string $organizationId,
        private int $staleAfterSeconds = 600,
        private int $stalledAfterSeconds = 1800,
    ) {}

    /** @return array<string,mixed> */
    public function __invoke(WatchEngineeringRuntimeCommand $command): array
    {
        return [
            'trigger' => trim($command->trigger) !== '' ? trim($command->trigger) : 'scheduler',
            'organization_id' => $this->organizationId,
            'thresholds' => [
                'stale_after_seconds' => $this->staleAfterSeconds,
                'stalled_after_seconds' => $this->stalledAfterSeconds,
            ],
            'health' => $this->workflows->refreshRuntimeHealthForOrganization(
                $this->organizationId,
                $this->staleAfterSeconds,
                $this->stalledAfterSeconds,
            ),
        ];
    }
}

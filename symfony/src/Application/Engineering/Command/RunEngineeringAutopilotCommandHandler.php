<?php
declare(strict_types=1);

namespace App\Application\Engineering\Command;

use App\Engineering\Application\Service\EngineeringDevelopmentAutopilot;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class RunEngineeringAutopilotCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private EngineeringDevelopmentAutopilot $autopilot,
        private string $organizationId,
    ) {}

    /** @return array<string,mixed> */
    public function __invoke(RunEngineeringAutopilotCommand $command): array
    {
        return array_merge(
            ['trigger' => trim($command->trigger) ?: 'scheduler'],
            $this->autopilot->tick($this->organizationId, true, 50),
        );
    }
}

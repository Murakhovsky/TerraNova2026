<?php
declare(strict_types=1);

namespace App\Application\Engineering\Command;

use App\Engineering\Application\Service\EngineeringSecurityRecoveryService;
use App\Engineering\Domain\Workflow\EngineeringId;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class RunEngineeringSecurityRecoveryCommandHandler implements CommandHandlerInterface
{
    public function __construct(private EngineeringSecurityRecoveryService $recovery) {}

    /** @return array<string,mixed> */
    public function __invoke(RunEngineeringSecurityRecoveryCommand $command): array
    {
        return $this->recovery->executeOnce(
            EngineeringId::assert($command->featureId),
            $command->organizationId,
            EngineeringId::assert($command->recoveryId),
        );
    }
}

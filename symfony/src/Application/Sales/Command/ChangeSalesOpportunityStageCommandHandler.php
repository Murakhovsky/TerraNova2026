<?php
declare(strict_types=1);

namespace App\Application\Sales\Command;

use Domains\Sales\Application\Contract\SalesWriteServiceFactoryInterface;
use Domains\Sales\Application\DTO\ChangeDealStageResult;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class ChangeSalesOpportunityStageCommandHandler implements CommandHandlerInterface
{
    public function __construct(private SalesWriteServiceFactoryInterface $writes)
    {
    }

    public function __invoke(ChangeSalesOpportunityStageCommand $command): ChangeDealStageResult
    {
        return $this->writes->forOrganization($command->organizationId->value())->changeOpportunityStage(
            $command->opportunityId,
            $command->targetStageId,
            $command->actorId,
            $command->correlationId,
            $command->lostReasonId,
            $command->lostReasonNote,
        );
    }
}

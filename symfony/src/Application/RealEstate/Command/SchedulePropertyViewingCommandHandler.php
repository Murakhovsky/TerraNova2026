<?php
declare(strict_types=1);

namespace App\Application\RealEstate\Command;

use Domains\RealEstate\Application\Service\RealEstateWorkflowService;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class SchedulePropertyViewingCommandHandler implements CommandHandlerInterface
{
    public function __construct(private RealEstateWorkflowService $workflow) {}
    public function __invoke(SchedulePropertyViewingCommand $command):array
    {
        return $this->workflow->scheduleViewing(
            $command->organizationId->value(),$command->actorId,$command->caseId,
            $command->idempotencyKey,$command->input,$command->correlationId,
        );
    }
}

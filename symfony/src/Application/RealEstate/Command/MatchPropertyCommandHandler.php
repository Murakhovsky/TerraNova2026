<?php
declare(strict_types=1);

namespace App\Application\RealEstate\Command;

use Domains\RealEstate\Application\Service\RealEstateWorkflowService;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class MatchPropertyCommandHandler implements CommandHandlerInterface
{
    public function __construct(private RealEstateWorkflowService $workflow) {}
    public function __invoke(MatchPropertyCommand $command):array
    {
        return $this->workflow->match(
            $command->organizationId->value(),$command->actorId,$command->opportunityId,
            $command->idempotencyKey,$command->input,$command->correlationId,
        );
    }
}

<?php
declare(strict_types=1);

namespace App\Application\RealEstate\Command;

use Domains\RealEstate\Application\Service\RealEstateWorkflowService;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class CreatePropertyOfferCommandHandler implements CommandHandlerInterface
{
    public function __construct(private RealEstateWorkflowService $workflow) {}
    public function __invoke(CreatePropertyOfferCommand $command):array
    {
        return $this->workflow->createOffer(
            $command->organizationId->value(),$command->actorId,$command->caseId,
            $command->idempotencyKey,$command->input,$command->correlationId,
        );
    }
}

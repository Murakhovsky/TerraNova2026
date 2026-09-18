<?php
declare(strict_types=1);

namespace App\Application\RealEstate\Command;

use Domains\RealEstate\Application\Service\RealEstateWorkflowService;
use InvalidArgumentException;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class RealEstateMutationCommandHandler implements CommandHandlerInterface
{
    public function __construct(private RealEstateWorkflowService $workflow){}

    public function __invoke(RealEstateMutationCommand $command): array
    {
        $org=$command->organizationId->value();
        return match($command->operation){
            RealEstateMutationCommand::MATCH=>$this->workflow->match($org,$command->actorId,(int)$command->subjectId,$command->input,$command->correlationId),
            RealEstateMutationCommand::OFFER=>$this->workflow->createOffer($org,$command->actorId,(string)$command->subjectId,$command->input,$command->correlationId),
            RealEstateMutationCommand::VIEWING=>$this->workflow->scheduleViewing($org,$command->actorId,(string)$command->subjectId,$command->input,$command->correlationId),
            RealEstateMutationCommand::RESERVE=>$this->workflow->reserve($org,$command->actorId,(string)$command->subjectId,$command->input,$command->correlationId),
            default=>throw new InvalidArgumentException('Unsupported RealEstate mutation.'),
        };
    }
}

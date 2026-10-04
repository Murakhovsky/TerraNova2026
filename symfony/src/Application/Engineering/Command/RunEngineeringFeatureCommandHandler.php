<?php
declare(strict_types=1);

namespace App\Application\Engineering\Command;

use App\Engineering\Application\Service\EngineeringContinueService;
use App\Engineering\Domain\Workflow\EngineeringId;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class RunEngineeringFeatureCommandHandler implements CommandHandlerInterface
{
    public function __construct(private EngineeringContinueService $engineering) {}

    /** @return array<string,mixed> */
    public function __invoke(RunEngineeringFeatureCommand $command): array
    {
        $featureId = EngineeringId::assert($command->featureId);
        $organizationId = trim($command->organizationId);
        if ($organizationId === '') {
            throw new \InvalidArgumentException('Engineering immediate execution requires organization id.');
        }

        $result = $this->engineering->continueFeature(
            $featureId,
            $organizationId,
            'engineering:immediate:'.$featureId.':'.EngineeringId::generate(),
        );

        return [
            'trigger' => trim($command->trigger) !== '' ? trim($command->trigger) : 'immediate',
            'feature_id' => $featureId,
            'state' => $result->state,
            'next' => $result->next->type->value,
        ];
    }
}

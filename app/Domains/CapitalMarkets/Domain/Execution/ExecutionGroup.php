<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Execution;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class ExecutionGroup extends ValueObject
{
    /** @param list<ExecutionLeg> $legs */
    public function __construct(
        public string $id,
        public string $strategyVersion,
        public string $opportunityId,
        public array $legs,
        public ExecutionPolicy $executionPolicy,
        public HedgePolicy $hedgePolicy,
        public ExecutionGroupState $state,
        public int $maximumUnhedgedTimeMs,
        public DateTimeImmutable $createdAt,
        public ?string $hedgeGroupId=null,
    ){
        if($id===''||$strategyVersion===''||$opportunityId===''||count($legs)<2||$maximumUnhedgedTimeMs<0){
            throw new InvalidArgumentException('Invalid execution group.');
        }
        foreach($legs as $leg)if(!$leg instanceof ExecutionLeg)throw new InvalidArgumentException('Execution group legs must be typed.');
    }
}

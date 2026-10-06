<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Execution;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class ExecutionPlan extends ValueObject
{
    /** @param list<ExecutionLeg> $legs @param list<string> $capitalReservationIds */
    public function __construct(
        public string $id,
        public string $opportunityId,
        public string $strategyVersion,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public array $legs,
        public string $sequencePolicy,
        public PartialFillPolicy $partialFillPolicy,
        public CompensationPolicy $compensationPolicy,
        public int $maxTotalLatencyMs,
        public int $maxLegLatencyMs,
        public Decimal $expectedCost,
        public Decimal $expectedPnl,
        public string $riskAssessmentId,
        public array $capitalReservationIds=[],
    ){
        if($id===''||$opportunityId===''||$strategyVersion===''||count($legs)<2||$expiresAt<=$createdAt){
            throw new InvalidArgumentException('Invalid execution plan.');
        }
        foreach($legs as $leg)if(!$leg instanceof ExecutionLeg)throw new InvalidArgumentException('Execution plan legs must be typed.');
        if(!$compensationPolicy->supportedInVerticalSliceV1()){
            throw new InvalidArgumentException('Compensation policy is not supported in VS1.');
        }
    }
}

<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RejectedHypothesisRecord
{
    public function __construct(
        public string $id,
        public string $hypothesisId,
        public string $reason,
        public array $evidence,
        public array $experimentIds,
        public array $marketConditions,
        public array $dataLimitations,
        public DateTimeImmutable $rejectedAt,
        public ?DateTimeImmutable $reviewAfter,
        public array $reopenConditions,
    ){
        if($id===''||$hypothesisId==='')throw new InvalidArgumentException('Invalid rejection record.');
        if(!in_array($reason,['NO_EDGE','EDGE_TOO_SMALL','COSTS_DESTROY_EDGE','TOO_RISKY','INSUFFICIENT_CAPACITY','UNSTABLE','DATA_INSUFFICIENT','NOT_EXECUTABLE','REGIME_DEPENDENT','TECHNICALLY_INFEASIBLE'],true)){
            throw new InvalidArgumentException('Invalid rejection reason.');
        }
    }
}

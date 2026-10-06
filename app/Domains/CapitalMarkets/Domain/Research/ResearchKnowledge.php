<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ResearchKnowledge
{
    public function __construct(
        public string $id,
        public string $type,
        public string $statement,
        public array $experimentIds,
        public array $datasetIds,
        public array $strategyVersionIds,
        public array $resultIds,
        public array $conditions,
        public DateTimeImmutable $createdAt,
    ){
        if($id===''||trim($statement)==='')throw new InvalidArgumentException('Invalid research knowledge.');
        if(!in_array($type,['VALIDATED_FINDING','REJECTED_FINDING','MARKET_OBSERVATION','EXECUTION_FINDING','RISK_FINDING','DATA_FINDING','VENUE_FINDING'],true)){
            throw new InvalidArgumentException('Invalid knowledge type.');
        }
    }
}

<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class StrategyVersion
{
    /** @param array<string,mixed> $parameterSchema @param array<string,mixed> $defaultParameters
     *  @param array<string,mixed> $entryRules @param array<string,mixed> $exitRules
     *  @param array<string,mixed> $sizingPolicy @param array<string,mixed> $hedgePolicy
     *  @param array<string,mixed> $executionPolicy
     */
    public function __construct(
        public string $id,
        public string $strategyId,
        public int $version,
        public string $logicHash,
        public array $parameterSchema,
        public array $defaultParameters,
        public array $entryRules,
        public array $exitRules,
        public array $sizingPolicy,
        public array $hedgePolicy,
        public array $executionPolicy,
        public string $riskPolicyReference,
        public DateTimeImmutable $createdAt,
        public string $createdBy,
        public string $status,
    ){
        if(trim($id)===''||trim($strategyId)===''||$version<1||trim($logicHash)===''){
            throw new InvalidArgumentException('Invalid strategy version.');
        }
        if(!in_array($status,['DRAFT','RESEARCH','BACKTEST','OOS','PAPER','LIMITED_LIVE','VALIDATED','SUSPENDED','REJECTED','RETIRED'],true)){
            throw new InvalidArgumentException('Invalid strategy status.');
        }
    }
}

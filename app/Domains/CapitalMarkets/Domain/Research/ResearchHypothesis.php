<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ResearchHypothesis
{
    /** @param list<string> $instrumentFamilies @param list<string> $markets @param list<string> $venues
     *  @param list<string> $requiredData @param list<string> $requiredCapabilities
     *  @param array<string,mixed> $successCriteria @param array<string,mixed> $failureCriteria
     *  @param array<string,mixed> $riskAssumptions @param array<string,mixed> $capitalAssumptions
     */
    public function __construct(
        public string $id,
        public string $code,
        public int $revision,
        public string $title,
        public string $description,
        public string $economicReason,
        public string $edgeSource,
        public array $instrumentFamilies,
        public array $markets,
        public array $venues,
        public string $timeHorizon,
        public string $expectedBehavior,
        public array $requiredData,
        public array $requiredCapabilities,
        public array $successCriteria,
        public array $failureCriteria,
        public array $riskAssumptions,
        public array $capitalAssumptions,
        public HypothesisStatus $status,
        public string $priority,
        public string $createdBy,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ){
        if(trim($id)===''||trim($code)===''||trim($title)===''||$revision<1){
            throw new InvalidArgumentException('Research hypothesis identity is invalid.');
        }
        if($status===HypothesisStatus::ReadyForResearch && trim($economicReason)===''){
            throw new InvalidArgumentException('economic_reason is required before READY_FOR_RESEARCH.');
        }
        if(!in_array($priority,['P0','P1','P2','P3'],true)){
            throw new InvalidArgumentException('Invalid hypothesis priority.');
        }
        if(!in_array($edgeSource,['STRUCTURAL','LIQUIDITY','FUNDING','INFORMATION','SETTLEMENT','VOLATILITY','BEHAVIORAL','REGULATORY','UNKNOWN'],true)){
            throw new InvalidArgumentException('Invalid edge source.');
        }
        if(!in_array($status,[HypothesisStatus::Idea,HypothesisStatus::Draft],true) && $edgeSource==='UNKNOWN'){
            throw new InvalidArgumentException('UNKNOWN edge source is allowed only for IDEA/DRAFT.');
        }
    }
}

<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Opportunity;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class RelativeValueCandidate extends ValueObject implements OpportunityCandidateInterface
{
    /**
     * @param list<array<string,mixed>> $legs
     * @param array<string,mixed> $evidence
     */
    public function __construct(
        public string $id,
        public HypothesisCode $hypothesis,
        public OpportunityType $type,
        public string $marketPairId,
        public DateTimeImmutable $detectedAt,
        public DateTimeImmutable $expiresAt,
        public array $legs,
        public Decimal $requiredCapital,
        public Decimal $capitalCapacity,
        public int $dataQualityScore,
        public array $evidence=[],
    ){
        if($id===''||$marketPairId===''||$expiresAt<=$detectedAt||count($legs)<2){
            throw new InvalidArgumentException('Invalid relative-value candidate.');
        }
        if(!$requiredCapital->isPositive()||$capitalCapacity->isNegative())throw new InvalidArgumentException('Relative-value candidate capital is invalid.');
        if($dataQualityScore<0||$dataQualityScore>100)throw new InvalidArgumentException('Relative-value candidate quality must be between 0 and 100.');
        foreach($legs as $leg){
            if(!is_array($leg)||array_is_list($leg))throw new InvalidArgumentException('Relative-value legs must be objects.');
        }
    }

    public function candidateId():string{return $this->id;}
    public function hypothesisCode():HypothesisCode{return $this->hypothesis;}
    public function expiredAt(DateTimeImmutable $at):bool{return $at >= $this->expiresAt;}
}

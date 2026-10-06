<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Opportunity;
use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;
final readonly class Opportunity extends ValueObject
{
    public function __construct(
        public string $id,
        public OpportunityCandidateInterface $candidate,
        public ?OpportunityCostEstimate $economics,
        public Decimal $requiredCapital,
        public Decimal $capitalCapacity,
        public Decimal $executionProbability,
        public int $riskScore,
        public OpportunityStatus $status,
        public DateTimeImmutable $validatedAt,
        public array $rejectionReasons=[],
        public ?OpportunityType $type=null,
        public ?ExpectedEconomics $expectedEconomics=null,
        public ?string $strategyVersion=null,
        public array $instruments=[],
        public array $venues=[],
        public array $evidence=[],
    ){
        if($economics===null&&$expectedEconomics===null){
            throw new InvalidArgumentException('Opportunity requires economics.');
        }
    }

    public function expectedNetPnl():Decimal
    {
        return $this->expectedEconomics?->expectedNetPnl
            ?? $this->economics?->expectedNetPnl
            ?? Decimal::fromString('0');
    }

    public function executableAt(DateTimeImmutable $at):bool
    {
        return !$this->candidate->expiredAt($at)
            && in_array($this->status,[OpportunityStatus::Valid,OpportunityStatus::Approved,OpportunityStatus::Reserved],true)
            && $this->expectedNetPnl()->isPositive();
    }
}

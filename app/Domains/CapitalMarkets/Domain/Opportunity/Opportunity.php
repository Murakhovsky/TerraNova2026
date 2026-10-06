<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Opportunity;
use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Kernel\Shared\Domain\ValueObject;
final readonly class Opportunity extends ValueObject
{
    public function __construct(
        public string $id, public SpreadCandidate $candidate, public OpportunityCostEstimate $economics,
        public Decimal $requiredCapital, public Decimal $capitalCapacity, public Decimal $executionProbability,
        public int $riskScore, public OpportunityStatus $status, public DateTimeImmutable $validatedAt,
        public array $rejectionReasons=[],
    ){}
    public function executableAt(DateTimeImmutable $at):bool{
        return !$this->candidate->expiredAt($at)
            && in_array($this->status,[OpportunityStatus::Valid,OpportunityStatus::Approved,OpportunityStatus::Reserved],true)
            && $this->economics->expectedNetPnl->isPositive();
    }
}

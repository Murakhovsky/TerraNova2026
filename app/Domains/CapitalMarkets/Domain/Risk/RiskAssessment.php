<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Risk;
use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Kernel\Shared\Domain\ValueObject;
final readonly class RiskAssessment extends ValueObject
{
    public function __construct(
        public string $id, public string $opportunityId, public RiskDecision $decision,
        public int $riskScore, public Decimal $approvedQuantity, public Decimal $approvedNotional,
        public DateTimeImmutable $assessedAt, public array $blockingReasons=[], public array $warnings=[],
    ){}
    public function approved():bool{return in_array($this->decision,[RiskDecision::Approve,RiskDecision::ApproveWithLimit],true);}
}

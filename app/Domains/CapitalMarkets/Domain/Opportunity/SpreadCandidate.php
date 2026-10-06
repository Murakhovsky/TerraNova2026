<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Opportunity;
use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Kernel\Shared\Domain\ValueObject;
final readonly class SpreadCandidate extends ValueObject
{
    public function __construct(
        public string $id,
        public HypothesisCode $hypothesis,
        public string $marketPairId,
        public SpreadDirection $direction,
        public DateTimeImmutable $detectedAt,
        public DateTimeImmutable $expiresAt,
        public string $buyVenueId,
        public string $sellVenueId,
        public string $buyInstrumentId,
        public string $sellInstrumentId,
        public Decimal $buyPrice,
        public Decimal $sellPrice,
        public Decimal $quantity,
        public Decimal $grossSpread,
        public Decimal $grossEdgeBps,
        public Decimal $capitalCapacity,
        public int $dataQualityScore,
        public array $evidence=[],
    ){}
    public function expiredAt(DateTimeImmutable $at):bool{return $at >= $this->expiresAt;}
}

<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Execution;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use Kernel\Shared\Domain\ValueObject;
final readonly class ExecutionPerformance extends ValueObject
{
    public Decimal $edgeCaptureRatio;
    public function __construct(
        public string $executionGroupId, public Decimal $detectedEdge, public Decimal $executableEdge,
        public Decimal $realizedEdge, public Decimal $expectedPnl, public Decimal $realizedPnl,
        public Decimal $capitalUsed, public int $latencyMs,
    ){
        $this->edgeCaptureRatio=$detectedEdge->isZero()?Decimal::fromString('0'):DecimalMath::divide($realizedEdge,$detectedEdge,8);
    }
}

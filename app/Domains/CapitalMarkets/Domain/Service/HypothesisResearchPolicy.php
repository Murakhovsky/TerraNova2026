<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use Domains\CapitalMarkets\Domain\Research\HypothesisVerdict;
use Domains\CapitalMarkets\Domain\Value\Decimal;

final readonly class HypothesisResearchPolicy
{
    /**
     * @param array{observation_count:int,detected_count:int,executable_count:int,realized_count:int,total_realized_pnl:string,average_edge_capture_ratio:string} $metrics
     */
    public function verdict(array $metrics,int $minimumObservations=30,int $minimumRealized=10):HypothesisVerdict
    {
        if($metrics['observation_count']<$minimumObservations)return HypothesisVerdict::InsufficientSample;
        if($metrics['detected_count']===0)return HypothesisVerdict::EdgeNotObserved;
        if($metrics['executable_count']===0)return HypothesisVerdict::EdgeObservedNotExecutable;
        if($metrics['realized_count']<$minimumRealized)return HypothesisVerdict::EdgeExecutableUnvalidated;

        $pnl=Decimal::fromString($metrics['total_realized_pnl']);
        $capture=Decimal::fromString($metrics['average_edge_capture_ratio']);
        return $pnl->isPositive()&&$capture->isPositive()
            ? HypothesisVerdict::EdgeValidated
            : HypothesisVerdict::EdgeNotValidated;
    }
}

<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Service;

use Domains\CapitalMarkets\Domain\Research\HypothesisVerdict;
use Domains\CapitalMarkets\Domain\Value\Decimal;

final readonly class HypothesisResearchPolicy
{
    /**
     * @param array{
     *   observation_count:int,detected_count:int,executable_count:int,
     *   execution_attempt_count:int,completed_execution_count:int,invalidated_execution_count:int,
     *   total_realized_pnl:string,average_edge_capture_ratio:string,completion_rate:string
     * } $metrics
     */
    public function verdict(
        array $metrics,
        int $minimumObservations=30,
        int $minimumExecutionAttempts=10,
        string $minimumCompletionRate='0.5',
    ):HypothesisVerdict{
        if($metrics['observation_count']<$minimumObservations)return HypothesisVerdict::InsufficientSample;
        if($metrics['detected_count']===0)return HypothesisVerdict::EdgeNotObserved;
        if($metrics['executable_count']===0)return HypothesisVerdict::EdgeObservedNotExecutable;
        if($metrics['execution_attempt_count']<$minimumExecutionAttempts)return HypothesisVerdict::EdgeExecutableUnvalidated;

        $pnl=Decimal::fromString($metrics['total_realized_pnl']);
        $capture=Decimal::fromString($metrics['average_edge_capture_ratio']);
        $completionRate=Decimal::fromString($metrics['completion_rate']);
        $requiredCompletionRate=Decimal::fromString($minimumCompletionRate);

        return $pnl->isPositive()
            &&$capture->isPositive()
            &&$completionRate->compareTo($requiredCompletionRate)>=0
            ? HypothesisVerdict::EdgeValidated
            : HypothesisVerdict::EdgeNotValidated;
    }
}

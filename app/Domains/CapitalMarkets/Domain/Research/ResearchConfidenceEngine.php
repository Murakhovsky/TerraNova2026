<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

final class ResearchConfidenceEngine
{
    public function calculate(
        int $sampleSize,
        int $minimumSample,
        int $dataQuality,
        int $oosPerformance,
        int $regimeDiversity,
        int $executionFidelity,
        int $resultStability,
    ):int{
        $sampleScore=$minimumSample<=0?0:min(100,(int)round(100*$sampleSize/$minimumSample));
        $components=[
            $sampleScore,$dataQuality,$oosPerformance,$regimeDiversity,$executionFidelity,$resultStability
        ];
        foreach($components as &$value)$value=max(0,min(100,$value));
        unset($value);
        return (int)round(array_sum($components)/count($components));
    }
}

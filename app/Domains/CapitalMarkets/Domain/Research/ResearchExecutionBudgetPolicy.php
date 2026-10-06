<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use InvalidArgumentException;

final class ResearchExecutionBudgetPolicy
{
    public function assertBacktest(array $configuration):void
    {
        $snapshotLimit=(int)($configuration['snapshot_limit']??5000);
        if($snapshotLimit<1||$snapshotLimit>10000){
            throw new InvalidArgumentException('snapshot_limit must be between 1 and 10000.');
        }
        $estimatedUnits=$snapshotLimit*(int)($configuration['parameter_combinations']??1);
        $maximumUnits=(int)($configuration['maximum_compute_units']??50000);
        if($maximumUnits<1||$estimatedUnits>$maximumUnits){
            throw new InvalidArgumentException('Research compute budget exceeded.');
        }
    }

    public function assertWalkForward(array $specification,int $windowCount):void
    {
        $maximumWindows=(int)($specification['maximum_windows']??100);
        if($maximumWindows<1||$windowCount>$maximumWindows){
            throw new InvalidArgumentException('Walk-forward window budget exceeded.');
        }
        $configuration=(array)($specification['configuration']??[]);
        $snapshotLimit=(int)($configuration['snapshot_limit']??5000);
        $estimatedUnits=$snapshotLimit*$windowCount;
        $maximumUnits=(int)($specification['maximum_compute_units']??250000);
        if($maximumUnits<1||$estimatedUnits>$maximumUnits){
            throw new InvalidArgumentException('Walk-forward compute budget exceeded.');
        }
    }

    public function estimate(array $configuration,int $windows=1):array
    {
        $snapshotLimit=max(1,(int)($configuration['snapshot_limit']??5000));
        $parameterCombinations=max(1,(int)($configuration['parameter_combinations']??1));
        return [
            'snapshot_budget'=>$snapshotLimit,
            'parameter_combinations'=>$parameterCombinations,
            'windows'=>max(1,$windows),
            'estimated_compute_units'=>$snapshotLimit*$parameterCombinations*max(1,$windows),
        ];
    }
}

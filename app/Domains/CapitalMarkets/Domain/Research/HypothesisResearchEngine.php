<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;

final class HypothesisResearchEngine
{
    /**
     * @param list<array<string,mixed>> $observations
     * @return array<string,mixed>
     */
    public function summarize(
        array $observations,
        int $minimumDetectedSample=30,
        int $minimumPaperSample=10,
        string $minimumCompletionRate='0.5',
    ):array
    {
        $minimumDetectedSample=max(1,$minimumDetectedSample);
        $minimumPaperSample=max(1,$minimumPaperSample);
        $requiredCompletionRate=Decimal::fromString($minimumCompletionRate);

        $scans=0;$unobservable=0;$detected=0;$executable=0;
        $executionAttempts=0;$realized=0;$invalidated=0;$positiveExpected=0;$positiveRealized=0;
        $expectedTotal=Decimal::fromString('0');
        $realizedTotal=Decimal::fromString('0');

        foreach($observations as $observation){
            $stage=(string)($observation['stage']??'');
            if($stage==='SCAN'){
                if(($observation['observable']??true)===false){
                    $unobservable++;
                    continue;
                }
                $scans++;
            }
            if($stage==='EVALUATION'){
                if((bool)($observation['detected']??false))$detected++;
                if((bool)($observation['executable']??false))$executable++;
                $expected=Decimal::fromString((string)($observation['expected_pnl']??'0'));
                $expectedTotal=DecimalMath::add($expectedTotal,$expected);
                if($expected->isPositive())$positiveExpected++;
            }
            if($stage==='EXECUTION'){
                $executionAttempts++;
                if((bool)($observation['realized']??false)){
                    $realized++;
                    $pnl=Decimal::fromString((string)($observation['realized_pnl']??'0'));
                    $realizedTotal=DecimalMath::add($realizedTotal,$pnl);
                    if($pnl->isPositive())$positiveRealized++;
                }else{
                    $invalidated++;
                }
            }
        }

        $averageExpected=$detected>0
            ? DecimalMath::divide($expectedTotal,Decimal::fromString((string)$detected),12)
            : Decimal::fromString('0');
        $averageRealizedPerAttempt=$executionAttempts>0
            ? DecimalMath::divide($realizedTotal,Decimal::fromString((string)$executionAttempts),12)
            : Decimal::fromString('0');

        $executableRatio=$detected>0
            ? DecimalMath::divide(Decimal::fromString((string)$executable),Decimal::fromString((string)$detected),6)
            : Decimal::fromString('0');
        $completionRate=$executionAttempts>0
            ? DecimalMath::divide(Decimal::fromString((string)$realized),Decimal::fromString((string)$executionAttempts),6)
            : Decimal::fromString('0');
        $positiveAttemptRatio=$executionAttempts>0
            ? DecimalMath::divide(Decimal::fromString((string)$positiveRealized),Decimal::fromString((string)$executionAttempts),6)
            : Decimal::fromString('0');

        $verdict=HypothesisVerdict::InsufficientData;
        if($scans >= $minimumDetectedSample){
            if($detected===0||!$averageExpected->isPositive()){
                $verdict=HypothesisVerdict::NoEdge;
            }elseif($executable===0||$executableRatio->compareTo(Decimal::fromString('0.1'))<0){
                $verdict=HypothesisVerdict::EdgeExistsNotExecutable;
            }elseif($executionAttempts < $minimumPaperSample){
                $verdict=HypothesisVerdict::PaperValidationRequired;
            }elseif(
                $completionRate->compareTo($requiredCompletionRate)>=0
                &&$averageRealizedPerAttempt->isPositive()
                &&$positiveAttemptRatio->compareTo(Decimal::fromString('0.5'))>=0
            ){
                $verdict=HypothesisVerdict::EdgeExists;
            }else{
                $verdict=HypothesisVerdict::NoEdge;
            }
        }

        return [
            'verdict'=>$verdict->value,
            'sample'=>[
                'scan_count'=>$scans,
                'unobservable_scan_count'=>$unobservable,
                'detected_count'=>$detected,
                'executable_count'=>$executable,
                'execution_attempt_count'=>$executionAttempts,
                'realized_count'=>$realized,
                'invalidated_execution_count'=>$invalidated,
                'minimum_detected_sample'=>$minimumDetectedSample,
                'minimum_paper_sample'=>$minimumPaperSample,
                'minimum_completion_rate'=>$requiredCompletionRate->value(),
            ],
            'edge_funnel'=>[
                'theoretical'=>$scans,
                'unobservable'=>$unobservable,
                'detected'=>$detected,
                'executable'=>$executable,
                'attempted'=>$executionAttempts,
                'realized'=>$realized,
                'invalidated'=>$invalidated,
            ],
            'economics'=>[
                'positive_expected_count'=>$positiveExpected,
                'expected_pnl_total'=>$expectedTotal->value(),
                'average_expected_pnl'=>$averageExpected->value(),
                'realized_pnl_total'=>$realizedTotal->value(),
                'average_realized_pnl_per_attempt'=>$averageRealizedPerAttempt->value(),
                'executable_ratio'=>$executableRatio->value(),
                'completion_rate'=>$completionRate->value(),
                'positive_realized_ratio_per_attempt'=>$positiveAttemptRatio->value(),
            ],
        ];
    }
}

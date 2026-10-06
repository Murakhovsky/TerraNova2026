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
    public function summarize(array $observations,int $minimumDetectedSample=30,int $minimumPaperSample=10):array
    {
        $minimumDetectedSample=max(1,$minimumDetectedSample);
        $minimumPaperSample=max(1,$minimumPaperSample);

        $scans=0;$detected=0;$executable=0;$realized=0;$positiveExpected=0;$positiveRealized=0;
        $expectedTotal=Decimal::fromString('0');
        $realizedTotal=Decimal::fromString('0');

        foreach($observations as $observation){
            $stage=(string)($observation['stage']??'');
            if($stage==='SCAN')$scans++;
            if($stage==='EVALUATION'){
                if((bool)($observation['detected']??false))$detected++;
                if((bool)($observation['executable']??false))$executable++;
                $expected=Decimal::fromString((string)($observation['expected_pnl']??'0'));
                $expectedTotal=DecimalMath::add($expectedTotal,$expected);
                if($expected->isPositive())$positiveExpected++;
            }
            if($stage==='EXECUTION'&&(bool)($observation['realized']??false)){
                $realized++;
                $pnl=Decimal::fromString((string)($observation['realized_pnl']??'0'));
                $realizedTotal=DecimalMath::add($realizedTotal,$pnl);
                if($pnl->isPositive())$positiveRealized++;
            }
        }

        $averageExpected=$detected>0
            ? DecimalMath::divide($expectedTotal,Decimal::fromString((string)$detected),12)
            : Decimal::fromString('0');
        $averageRealized=$realized>0
            ? DecimalMath::divide($realizedTotal,Decimal::fromString((string)$realized),12)
            : Decimal::fromString('0');

        $executableRatio=$detected>0
            ? DecimalMath::divide(Decimal::fromString((string)$executable),Decimal::fromString((string)$detected),6)
            : Decimal::fromString('0');
        $positiveRealizedRatio=$realized>0
            ? DecimalMath::divide(Decimal::fromString((string)$positiveRealized),Decimal::fromString((string)$realized),6)
            : Decimal::fromString('0');

        $verdict=HypothesisVerdict::InsufficientData;
        if($detected >= $minimumDetectedSample){
            if(!$averageExpected->isPositive()){
                $verdict=HypothesisVerdict::NoEdge;
            }elseif($executable===0||$executableRatio->compareTo(Decimal::fromString('0.1'))<0){
                $verdict=HypothesisVerdict::EdgeExistsNotExecutable;
            }elseif($realized < $minimumPaperSample){
                $verdict=HypothesisVerdict::PaperValidationRequired;
            }elseif($averageRealized->isPositive()&&$positiveRealizedRatio->compareTo(Decimal::fromString('0.6'))>=0){
                $verdict=HypothesisVerdict::EdgeExists;
            }else{
                $verdict=HypothesisVerdict::NoEdge;
            }
        }

        return [
            'verdict'=>$verdict->value,
            'sample'=>[
                'scan_count'=>$scans,
                'detected_count'=>$detected,
                'executable_count'=>$executable,
                'realized_count'=>$realized,
                'minimum_detected_sample'=>$minimumDetectedSample,
                'minimum_paper_sample'=>$minimumPaperSample,
            ],
            'edge_funnel'=>[
                'theoretical'=>$scans,
                'detected'=>$detected,
                'executable'=>$executable,
                'realized'=>$realized,
            ],
            'economics'=>[
                'positive_expected_count'=>$positiveExpected,
                'expected_pnl_total'=>$expectedTotal->value(),
                'average_expected_pnl'=>$averageExpected->value(),
                'realized_pnl_total'=>$realizedTotal->value(),
                'average_realized_pnl'=>$averageRealized->value(),
                'executable_ratio'=>$executableRatio->value(),
                'positive_realized_ratio'=>$positiveRealizedRatio->value(),
            ],
        ];
    }
}

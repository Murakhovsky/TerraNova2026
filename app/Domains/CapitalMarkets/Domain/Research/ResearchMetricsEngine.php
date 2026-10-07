<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;

final class ResearchMetricsEngine
{
    public function calculate(array $rows):array
    {
        if($rows===[]){
            return [
                'financial'=>[
                    'sample_count'=>0,'positive_count'=>0,'negative_count'=>0,'win_rate'=>'0',
                    'pnl_total'=>'0','pnl_average'=>'0','profit_factor'=>'0',
                ],
                'risk'=>[
                    'max_drawdown'=>'0','max_drawdown_ratio'=>'0','worst_observation'=>'0',
                ],
                'statistical'=>[
                    'mean'=>'0','mean_absolute_deviation'=>'0','median'=>'0','positive_rate'=>'0',
                ],
            ];
        }

        $values=[];$positive=0;$negative=0;$total=Decimal::fromString('0');
        $grossProfit=Decimal::fromString('0');$grossLoss=Decimal::fromString('0');
        foreach($rows as $row){
            $value=Decimal::fromString((string)($row['expected_net_pnl']??0));
            $values[]=$value;
            $total=DecimalMath::add($total,$value);
            if($value->isPositive()){$positive++;$grossProfit=DecimalMath::add($grossProfit,$value);}
            elseif($value->isNegative()){$negative++;$grossLoss=DecimalMath::add($grossLoss,DecimalMath::abs($value));}
        }

        $count=count($values);
        $mean=DecimalMath::divide($total,Decimal::fromString((string)$count),12);
        $ordered=$values;
        $sorted=$values;
        usort($sorted,static fn(Decimal $a,Decimal $b):int=>$a->compareTo($b));
        $middle=intdiv($count,2);
        $median=$count%2===0
            ? DecimalMath::midpoint($sorted[$middle-1],$sorted[$middle],12)
            : $sorted[$middle];

        $deviation=Decimal::fromString('0');
        foreach($ordered as $value)$deviation=DecimalMath::add($deviation,DecimalMath::abs(DecimalMath::subtract($value,$mean)));
        $meanDeviation=DecimalMath::divide($deviation,Decimal::fromString((string)$count),12);

        $profitFactor=$grossLoss->isZero()
            ? ($grossProfit->isPositive()?'INF':'0')
            : DecimalMath::divide($grossProfit,$grossLoss,8)->value();

        $equity=Decimal::fromString('0');
        $peak=Decimal::fromString('0');
        $maxDrawdown=Decimal::fromString('0');
        foreach($ordered as $value){
            $equity=DecimalMath::add($equity,$value);
            if($equity->compareTo($peak)>0)$peak=$equity;
            $drawdown=DecimalMath::subtract($peak,$equity);
            if($drawdown->compareTo($maxDrawdown)>0)$maxDrawdown=$drawdown;
        }
        $drawdownRatio=$peak->isPositive()?DecimalMath::divide($maxDrawdown,$peak,8)->value():'0';

        return [
            'financial'=>[
                'sample_count'=>$count,
                'positive_count'=>$positive,
                'negative_count'=>$negative,
                'win_rate'=>DecimalMath::divide(Decimal::fromString((string)$positive),Decimal::fromString((string)$count),8)->value(),
                'pnl_total'=>$total->value(),
                'pnl_average'=>$mean->value(),
                'profit_factor'=>$profitFactor,
            ],
            'risk'=>[
                'max_drawdown'=>$maxDrawdown->value(),
                'max_drawdown_ratio'=>$drawdownRatio,
                'worst_observation'=>$sorted[0]->value(),
            ],
            'statistical'=>[
                'mean'=>$mean->value(),
                'mean_absolute_deviation'=>$meanDeviation->value(),
                'median'=>$median->value(),
                'positive_rate'=>DecimalMath::divide(Decimal::fromString((string)$positive),Decimal::fromString((string)$count),8)->value(),
            ],
        ];
    }
}

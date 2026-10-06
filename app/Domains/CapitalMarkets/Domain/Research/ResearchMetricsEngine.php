<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

final class ResearchMetricsEngine
{
    /** @param list<array<string,mixed>> $rows */
    public function calculate(array $rows):array
    {
        if($rows===[]){
            return [
                'financial'=>[
                    'sample_count'=>0,'positive_count'=>0,'negative_count'=>0,'win_rate'=>0.0,
                    'pnl_total'=>0.0,'pnl_average'=>0.0,'profit_factor'=>0.0,
                ],
                'risk'=>[
                    'max_drawdown'=>0.0,'max_drawdown_ratio'=>0.0,'worst_observation'=>0.0,
                ],
                'statistical'=>[
                    'mean'=>0.0,'standard_deviation'=>0.0,'median'=>0.0,'positive_rate'=>0.0,
                ],
            ];
        }

        $values=[];
        foreach($rows as $row)$values[]=(float)($row['expected_net_pnl']??0);
        $count=count($values);
        $positive=count(array_filter($values,static fn(float $v):bool=>$v>0));
        $negative=count(array_filter($values,static fn(float $v):bool=>$v<0));
        $total=array_sum($values);
        $mean=$total/$count;

        $variance=array_sum(array_map(static fn(float $v):float=>($v-$mean)**2,$values))/$count;
        $sorted=$values;sort($sorted,SORT_NUMERIC);
        $middle=intdiv($count,2);
        $median=$count%2===0?($sorted[$middle-1]+$sorted[$middle])/2:$sorted[$middle];

        $grossProfit=array_sum(array_filter($values,static fn(float $v):bool=>$v>0));
        $grossLoss=abs(array_sum(array_filter($values,static fn(float $v):bool=>$v<0)));
        $profitFactor=$grossLoss<=0?($grossProfit>0?INF:0.0):$grossProfit/$grossLoss;

        $equity=0.0;$peak=0.0;$maxDrawdown=0.0;$maxDrawdownRatio=0.0;
        foreach($values as $value){
            $equity+=$value;
            if($equity>$peak)$peak=$equity;
            $drawdown=$peak-$equity;
            if($drawdown>$maxDrawdown)$maxDrawdown=$drawdown;
            if($peak>0){
                $ratio=$drawdown/$peak;
                if($ratio>$maxDrawdownRatio)$maxDrawdownRatio=$ratio;
            }
        }

        return [
            'financial'=>[
                'sample_count'=>$count,
                'positive_count'=>$positive,
                'negative_count'=>$negative,
                'win_rate'=>$positive/$count,
                'pnl_total'=>$total,
                'pnl_average'=>$mean,
                'profit_factor'=>$profitFactor,
            ],
            'risk'=>[
                'max_drawdown'=>$maxDrawdown,
                'max_drawdown_ratio'=>$maxDrawdownRatio,
                'worst_observation'=>min($values),
            ],
            'statistical'=>[
                'mean'=>$mean,
                'standard_deviation'=>sqrt($variance),
                'median'=>$median,
                'positive_rate'=>$positive/$count,
            ],
        ];
    }
}

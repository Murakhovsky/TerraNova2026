<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

final class WalkForwardEngine
{
    /** @return list<array{train:array{from:string,to:string},test:array{from:string,to:string}}> */
    public function windows(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $trainDays,
        int $testDays,
        int $stepDays,
    ):array{
        if($to<=$from||$trainDays<1||$testDays<1||$stepDays<1){
            throw new InvalidArgumentException('Invalid walk-forward configuration.');
        }

        $windows=[];
        $cursor=$from;
        $trainInterval=new DateInterval('P'.$trainDays.'D');
        $testInterval=new DateInterval('P'.$testDays.'D');
        $stepInterval=new DateInterval('P'.$stepDays.'D');

        while(true){
            $trainTo=$cursor->add($trainInterval);
            $testTo=$trainTo->add($testInterval);
            if($testTo>$to)break;

            $windows[]=[
                'train'=>['from'=>$cursor->format(DATE_ATOM),'to'=>$trainTo->format(DATE_ATOM)],
                'test'=>['from'=>$trainTo->format(DATE_ATOM),'to'=>$testTo->format(DATE_ATOM)],
            ];
            $cursor=$cursor->add($stepInterval);
        }

        if($windows===[])throw new InvalidArgumentException('Walk-forward period is too short for configured windows.');
        return $windows;
    }

    public function summarize(array $results):array
    {
        if($results===[])return ['windows'=>0,'positive_windows'=>0,'stability'=>0,'average_score'=>0];
        $scores=array_map(static fn(array $r):float=>(float)($r['score']??0),$results);
        $positive=count(array_filter($scores,static fn(float $v):bool=>$v>0));
        $mean=array_sum($scores)/count($scores);
        $variance=array_sum(array_map(static fn(float $v):float=>($v-$mean)**2,$scores))/count($scores);
        $stdev=sqrt($variance);
        $stability=$mean==0.0?0:max(0,min(100,(int)round(100-(100*$stdev/max(abs($mean),0.000001)))));
        return [
            'windows'=>count($results),
            'positive_windows'=>$positive,
            'positive_ratio'=>$positive/count($results),
            'average_score'=>$mean,
            'stability'=>$stability,
        ];
    }
}

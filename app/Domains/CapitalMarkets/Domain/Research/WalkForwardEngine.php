<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

use DateInterval;
use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use InvalidArgumentException;

final class WalkForwardEngine
{
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
        if($results===[])return ['windows'=>0,'positive_windows'=>0,'positive_ratio'=>'0','stability'=>0,'average_score'=>'0'];

        $scores=[];$sum=Decimal::fromString('0');$positive=0;
        foreach($results as $result){
            $score=Decimal::fromString((string)($result['score']??0));
            $scores[]=$score;
            $sum=DecimalMath::add($sum,$score);
            if($score->isPositive())$positive++;
        }
        $mean=DecimalMath::divide($sum,Decimal::fromString((string)count($scores)),12);
        $deviation=Decimal::fromString('0');
        foreach($scores as $score)$deviation=DecimalMath::add($deviation,DecimalMath::abs(DecimalMath::subtract($score,$mean)));
        $meanDeviation=DecimalMath::divide($deviation,Decimal::fromString((string)count($scores)),12);

        $stability=100;
        $meanAbs=DecimalMath::abs($mean);
        if($meanAbs->isPositive()){
            $ratioBps=DecimalMath::divide(
                DecimalMath::multiplyInteger($meanDeviation,10000),
                $meanAbs,
                0
            );
            $penalty=min(100,(int)DecimalMath::divide($ratioBps,Decimal::fromString('100'),0)->value());
            $stability=max(0,100-$penalty);
        }elseif(!$meanDeviation->isZero()){
            $stability=0;
        }

        return [
            'windows'=>count($results),
            'positive_windows'=>$positive,
            'positive_ratio'=>DecimalMath::divide(Decimal::fromString((string)$positive),Decimal::fromString((string)count($results)),8)->value(),
            'average_score'=>$mean->value(),
            'mean_absolute_deviation'=>$meanDeviation->value(),
            'stability'=>$stability,
        ];
    }
}

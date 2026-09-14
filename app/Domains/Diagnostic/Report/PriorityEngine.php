<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Report;
final class PriorityEngine
{
    public function score(float $impact,float $confidence,float $urgency,float $effort,float $cost,float $time,float $risk):float
    { $n=fn(float $v):float=>max(0,min(1,$v));$ease=(1-$n($effort)+1-$n($cost)+1-$n($time)+1-$n($risk))/4;return round(100*$n($impact)*$n($confidence)*$n($urgency)*$ease,2); }
    public function isQuickWin(float $score,float $impact,float $confidence,float $effort,float $time):bool{return $score>=35&&$impact>=.7&&$confidence>=.7&&$effort<=.35&&$time<=.35;}
}

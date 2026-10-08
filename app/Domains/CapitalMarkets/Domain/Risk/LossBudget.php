<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Risk;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
final readonly class LossBudget {
 public function __construct(public string $scope,public Decimal $daily,public Decimal $weekly,public Decimal $consumedDaily,public Decimal $consumedWeekly){}
 public function dailyHeadroom():Decimal{$v=DecimalMath::subtract($this->daily,$this->consumedDaily);return $v->isNegative()?Decimal::fromString('0'):$v;}
 public function weeklyHeadroom():Decimal{$v=DecimalMath::subtract($this->weekly,$this->consumedWeekly);return $v->isNegative()?Decimal::fromString('0'):$v;}
 public function exhausted():bool{return $this->consumedDaily->compareTo($this->daily)>=0||$this->consumedWeekly->compareTo($this->weekly)>=0;}
}

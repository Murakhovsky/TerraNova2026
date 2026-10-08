<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Risk;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use InvalidArgumentException;
final readonly class RiskLimit {
 public function __construct(public string $metric,public RiskEnvelopeLevel $level,public RiskLimitType $type,public Decimal $limit,public ?string $scopeId=null,public bool $hard=false){if($metric===''||$limit->isNegative())throw new InvalidArgumentException('Invalid risk limit.');}
 public function utilization(Decimal $current):Decimal{if($this->limit->isZero())return $current->isZero()?Decimal::fromString('0'):Decimal::fromString('999');return DecimalMath::divide($current,$this->limit);}
 public function headroom(Decimal $current):Decimal{$h=DecimalMath::subtract($this->limit,$current);return $h->isNegative()?Decimal::fromString('0'):$h;}
 public function breached(Decimal $current):bool{return $current->compareTo($this->limit)>0;}
}

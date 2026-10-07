<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Risk;
use Domains\CapitalMarkets\Domain\Value\Decimal;
final readonly class RiskHeadroom {
 public function __construct(public string $metric,public Decimal $current,public Decimal $limit,public Decimal $headroom,public Decimal $utilization,public bool $hard,public bool $breached){}
 public static function fromLimit(RiskLimit $limit,Decimal $current):self{return new self($limit->metric,$current,$limit->limit,$limit->headroom($current),$limit->utilization($current),$limit->hard,$limit->breached($current));}
}

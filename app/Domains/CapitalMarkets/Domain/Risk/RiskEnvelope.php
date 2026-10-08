<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Risk;
use InvalidArgumentException;
final readonly class RiskEnvelope {
 /** @param list<RiskLimit> $limits */
 public function __construct(public string $id,public string $portfolioId,public string $version,public array $limits){if($id===''||$portfolioId===''||$version==='')throw new InvalidArgumentException('Risk envelope identity required.');foreach($limits as $limit)if(!$limit instanceof RiskLimit)throw new InvalidArgumentException('Risk envelope limits must be RiskLimit.');}
 public function forMetric(string $metric):array{return array_values(array_filter($this->limits,fn(RiskLimit $l)=>$l->metric===$metric));}
}

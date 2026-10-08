<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Risk;
final readonly class ValuationPolicy {
 /** @param array<string,ValuationSource|string> $sources */
 public function __construct(public string $version,public array $sources,public int $maximumAgeSeconds,public bool $blockNewRiskOnDegraded=true,public bool $conservativeRiskPrice=true){}
 public function allowsNewRisk(ValuationQuality $quality):bool{return !$this->blockNewRiskOnDegraded||$quality===ValuationQuality::Trusted;}
}

<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

final readonly class MarketDataQualityAssessment
{
    /** @param list<MarketQualityFlag> $flags */
    public function __construct(
        public MarketQualityStatus $qualityStatus,
        public MarketTrustStatus $trustStatus,
        public array $flags,
        public int $score,
        public int $eventAgeMs,
        public int $ingestionLatencyMs,
        public int $processingLatencyMs,
    ){}

    public function has(MarketQualityFlag $flag):bool
    {
        foreach($this->flags as $candidate)if($candidate===$flag)return true;
        return false;
    }

    public function toArray():array{return [
        'quality_status'=>$this->qualityStatus->value,'trust_status'=>$this->trustStatus->value,
        'flags'=>array_map(static fn(MarketQualityFlag $flag):string=>$flag->value,$this->flags),
        'score'=>$this->score,'event_age_ms'=>$this->eventAgeMs,
        'ingestion_latency_ms'=>$this->ingestionLatencyMs,'processing_latency_ms'=>$this->processingLatencyMs,
    ];}
}

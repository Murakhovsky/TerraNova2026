<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class MarketDataQualityAssessment extends ValueObject
{
    /**
     * @param list<MarketQualityFlag> $flags
     */
    public function __construct(
        public MarketTrustStatus $status,
        public int $score,
        public array $flags,
        public int $ingestionLatencyMilliseconds,
        public int $processingLatencyMilliseconds,
        public int $eventAgeMilliseconds,
        public ?Decimal $referenceDeviationBps=null,
    ){
        if($this->score<0||$this->score>100)throw new InvalidArgumentException('Market quality score must be between 0 and 100.');
        foreach($this->flags as $flag){
            if(!$flag instanceof MarketQualityFlag)throw new InvalidArgumentException('Market quality flags must be typed.');
        }
        if(count($this->flags)!==count(array_unique(array_map(static fn(MarketQualityFlag $flag):string=>$flag->value,$this->flags)))){
            throw new InvalidArgumentException('Market quality flags cannot contain duplicates.');
        }
    }

    public function trusted():bool{return $this->status===MarketTrustStatus::Trusted;}

    /** @return array<string,mixed> */
    public function toArray():array
    {
        return [
            'status'=>$this->status->value,
            'score'=>$this->score,
            'flags'=>array_map(static fn(MarketQualityFlag $flag):string=>$flag->value,$this->flags),
            'ingestion_latency_ms'=>$this->ingestionLatencyMilliseconds,
            'processing_latency_ms'=>$this->processingLatencyMilliseconds,
            'event_age_ms'=>$this->eventAgeMilliseconds,
            'reference_deviation_bps'=>$this->referenceDeviationBps?->value(),
        ];
    }
}

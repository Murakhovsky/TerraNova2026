<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class MarketDataQualityPolicy extends ValueObject
{
    /**
     * @param array<string,int> $maximumAgeMillisecondsByType
     */
    public function __construct(
        public array $maximumAgeMillisecondsByType,
        public int $maximumProcessingLatencyMilliseconds,
        public int $maximumClockDriftMilliseconds,
        public int $maximumSpreadBps,
        public int $maximumJumpBps,
        public int $maximumReferenceDeviationBps,
        public bool $consecutiveSequenceRequired=false,
        public array $sequencePolicyByType=[],
    ){
        if($this->maximumProcessingLatencyMilliseconds<0||$this->maximumClockDriftMilliseconds<0){
            throw new InvalidArgumentException('Latency/clock thresholds cannot be negative.');
        }
        if($this->maximumSpreadBps<1||$this->maximumJumpBps<1||$this->maximumReferenceDeviationBps<1){
            throw new InvalidArgumentException('Market plausibility thresholds must be positive.');
        }
        foreach($this->maximumAgeMillisecondsByType as $type=>$value){
            MarketEventType::from($type);
            if($value<1)throw new InvalidArgumentException('Maximum market-data age must be positive.');
        }
        foreach($this->sequencePolicyByType as $type=>$policy){
            MarketEventType::from($type);
            if(!$policy instanceof MarketSequencePolicy)throw new InvalidArgumentException('Sequence policy must be typed.');
        }
    }

    public function maximumAgeFor(MarketEventType $type):int
    {
        $value=$this->maximumAgeMillisecondsByType[$type->value]??null;
        if(!is_int($value)||$value<1)throw new InvalidArgumentException('No freshness threshold configured for '.$type->value.'.');
        return $value;
    }

    public function sequencePolicyFor(MarketEventType $type):MarketSequencePolicy
    {
        $policy=$this->sequencePolicyByType[$type->value]??null;
        if($policy instanceof MarketSequencePolicy)return $policy;
        if($this->consecutiveSequenceRequired&&in_array($type,[MarketEventType::OrderBookSnapshot,MarketEventType::OrderBookDelta],true)){
            return MarketSequencePolicy::Contiguous;
        }
        return MarketSequencePolicy::Monotonic;
    }
}

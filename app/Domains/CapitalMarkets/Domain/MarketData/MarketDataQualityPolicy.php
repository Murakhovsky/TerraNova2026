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
    }

    public function maximumAgeFor(MarketEventType $type):int
    {
        $value=$this->maximumAgeMillisecondsByType[$type->value]??null;
        if(!is_int($value)||$value<1)throw new InvalidArgumentException('No freshness threshold configured for '.$type->value.'.');
        return $value;
    }
}

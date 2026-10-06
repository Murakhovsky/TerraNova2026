<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class MarketTimestamps extends ValueObject
{
    public function __construct(
        public DateTimeImmutable $sourceTimestamp,
        public DateTimeImmutable $receivedTimestamp,
        public DateTimeImmutable $processedTimestamp,
    ){
        if($this->processedTimestamp<$this->receivedTimestamp){
            throw new InvalidArgumentException('Processed timestamp cannot precede received timestamp.');
        }
    }

    public function ingestionLatencyMilliseconds():int
    {
        return self::millisecondsBetween($this->sourceTimestamp,$this->receivedTimestamp);
    }

    public function processingLatencyMilliseconds():int
    {
        return self::millisecondsBetween($this->receivedTimestamp,$this->processedTimestamp);
    }

    public function ageMilliseconds(DateTimeImmutable $now):int
    {
        return self::millisecondsBetween($this->sourceTimestamp,$now);
    }

    private static function millisecondsBetween(DateTimeImmutable $from,DateTimeImmutable $to):int
    {
        $fromMicros=((int)$from->format('U'))*1_000_000+(int)$from->format('u');
        $toMicros=((int)$to->format('U'))*1_000_000+(int)$to->format('u');
        return intdiv($toMicros-$fromMicros,1000);
    }
}

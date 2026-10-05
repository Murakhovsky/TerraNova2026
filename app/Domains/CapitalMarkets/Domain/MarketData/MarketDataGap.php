<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use InvalidArgumentException;

final readonly class MarketDataGap
{
    public function __construct(
        public string $id,
        public MarketSourceId $sourceId,
        public InstrumentId $instrumentId,
        public MarketEventType $dataType,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public string $reason,
        public MarketGapStatus $status,
    ){
        if(trim($this->id)===''||trim($this->reason)==='')throw new InvalidArgumentException('Market data gap identity and reason are required.');
        if($this->to<$this->from)throw new InvalidArgumentException('Market data gap end cannot precede start.');
    }
}

<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class MarketDataGap extends ValueObject
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
        if($this->id===''||trim($this->id)!==$this->id||mb_strlen($this->id)>190)throw new InvalidArgumentException('Market data gap id is invalid.');
        if($this->to<$this->from)throw new InvalidArgumentException('Market data gap end cannot precede start.');
        if($this->reason===''||trim($this->reason)!==$this->reason||mb_strlen($this->reason)>1000)throw new InvalidArgumentException('Market data gap reason is invalid.');
    }
}

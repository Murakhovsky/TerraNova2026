<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\DTO;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataMode;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketSession;
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceType;
use InvalidArgumentException;

final readonly class DecodedMarketEvent
{
    /**
     * @param array<string,mixed> $values Provider-neutral intermediate values.
     */
    public function __construct(
        public MarketEventType $eventType,
        public string $externalInstrument,
        public DateTimeImmutable $sourceTimestamp,
        public ?string $sequence,
        public array $values,
        public MarketDataMode $mode=MarketDataMode::Live,
        public MarketStatus $marketStatus=MarketStatus::Unknown,
        public MarketSession $session=MarketSession::Unknown,
        public ReferenceType $referenceType=ReferenceType::ProviderReference,
    ){
        if($this->externalInstrument===''||trim($this->externalInstrument)!==$this->externalInstrument||mb_strlen($this->externalInstrument)>190){
            throw new InvalidArgumentException('Decoded market instrument is invalid.');
        }
        if($this->sequence!==null&&($this->sequence===''||trim($this->sequence)!==$this->sequence||mb_strlen($this->sequence)>190)){
            throw new InvalidArgumentException('Decoded market sequence is invalid.');
        }
        if($this->values!==[]&&array_is_list($this->values))throw new InvalidArgumentException('Decoded market values must be an object.');
    }
}

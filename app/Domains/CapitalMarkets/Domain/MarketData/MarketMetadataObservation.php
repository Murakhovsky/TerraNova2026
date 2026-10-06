<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class MarketMetadataObservation extends ValueObject implements MarketObservation
{
    /** @param array<string,mixed> $metadata */
    public function __construct(public array $metadata)
    {
        if($metadata===[]||array_is_list($metadata))throw new InvalidArgumentException('Market metadata must be a non-empty object.');
        if(strlen(json_encode($metadata,JSON_THROW_ON_ERROR))>65536)throw new InvalidArgumentException('Market metadata is too large.');
    }

    public function eventType():MarketEventType{return MarketEventType::InstrumentMetadata;}
    public function toArray():array{return $this->metadata;}
}

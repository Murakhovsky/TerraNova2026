<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\MarketDataDecoderInterface;
use RuntimeException;

final class MarketDataDecoderRegistry
{
    /** @var array<string,MarketDataDecoderInterface> */
    private array $decoders=[];

    /** @param iterable<MarketDataDecoderInterface> $decoders */
    public function __construct(iterable $decoders=[])
    {
        foreach($decoders as $decoder){
            $type=$decoder->adapterType();
            if(isset($this->decoders[$type]))throw new RuntimeException('Duplicate market-data decoder: '.$type);
            $this->decoders[$type]=$decoder;
        }
    }

    public function get(string $adapterType):MarketDataDecoderInterface
    {
        return $this->decoders[$adapterType]??throw new RuntimeException('Market-data decoder is not registered: '.$adapterType);
    }
}

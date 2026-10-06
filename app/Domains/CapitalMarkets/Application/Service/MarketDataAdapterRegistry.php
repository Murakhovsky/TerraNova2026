<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Domain\Contract\MarketDataAdapterInterface;
use RuntimeException;

final class MarketDataAdapterRegistry
{
    /** @var array<string,MarketDataAdapterInterface> */
    private array $adapters=[];

    /** @param iterable<MarketDataAdapterInterface> $adapters */
    public function __construct(iterable $adapters=[])
    {
        foreach($adapters as $adapter){
            $type=$adapter->adapterType();
            if(isset($this->adapters[$type]))throw new RuntimeException('Duplicate market-data adapter: '.$type);
            $this->adapters[$type]=$adapter;
        }
    }

    public function get(string $adapterType):MarketDataAdapterInterface
    {
        return $this->adapters[$adapterType]??throw new RuntimeException('Market-data adapter is not registered: '.$adapterType);
    }

    /** @return list<string> */
    public function types():array
    {
        $types=array_keys($this->adapters);
        sort($types,SORT_STRING);
        return $types;
    }
}

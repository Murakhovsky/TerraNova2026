<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use Domains\Growth\Application\Contract\GrowthMarketSourceInterface;
use InvalidArgumentException;

final class GrowthMarketSourceRegistry
{
    /** @var array<string,GrowthMarketSourceInterface> */
    private array $sources=[];

    /** @param iterable<GrowthMarketSourceInterface> $sources */
    public function __construct(iterable $sources)
    {
        foreach($sources as $source){
            $type=strtolower(trim($source->type()));
            if($type===''||strlen($type)>80||!preg_match('/^[a-z][a-z0-9_-]*$/',$type)){
                throw new InvalidArgumentException('Growth market source type is invalid.');
            }
            if(isset($this->sources[$type]))throw new InvalidArgumentException('Duplicate Growth market source: '.$type);
            $this->sources[$type]=$source;
        }
    }

    public function get(string $type):GrowthMarketSourceInterface
    {
        $type=strtolower(trim($type));
        return $this->sources[$type]??throw new InvalidArgumentException('No Growth market source registered for type: '.$type);
    }

    /** @return list<string> */
    public function types():array
    {
        $types=array_keys($this->sources);sort($types,SORT_STRING);return $types;
    }
}

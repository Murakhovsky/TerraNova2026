<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use Domains\Growth\Application\Contract\SignalCollectorInterface;
use InvalidArgumentException;

final class SignalCollectorRegistry
{
    /** @var array<string,SignalCollectorInterface> */
    private array $collectors=[];

    /** @param iterable<SignalCollectorInterface> $collectors */
    public function __construct(iterable $collectors)
    {
        foreach($collectors as $collector){
            $name=trim($collector->name());
            if($name===''||strlen($name)>120)throw new InvalidArgumentException('Growth signal collector name is invalid.');
            if(isset($this->collectors[$name]))throw new InvalidArgumentException('Duplicate Growth signal collector: '.$name);
            $this->collectors[$name]=$collector;
        }
    }

    public function get(string $name): SignalCollectorInterface
    {
        return $this->collectors[$name]??throw new InvalidArgumentException('Unknown Growth signal collector: '.$name);
    }

    /** @return list<string> */
    public function names(): array
    {
        $names=array_keys($this->collectors);
        sort($names,SORT_STRING);
        return $names;
    }
}

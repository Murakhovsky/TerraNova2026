<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use Domains\Growth\Application\Contract\GrowthConversationRoutingTargetInterface;
use InvalidArgumentException;

final class GrowthConversationRoutingTargetRegistry
{
    /** @var array<string,GrowthConversationRoutingTargetInterface> */
    private array $targets=[];

    /** @param iterable<GrowthConversationRoutingTargetInterface> $targets */
    public function __construct(iterable $targets)
    {
        foreach($targets as $target){
            $route=strtolower(trim($target->route()));
            if($route===''||strlen($route)>80||!preg_match('/^[a-z][a-z0-9_-]*$/',$route)){
                throw new InvalidArgumentException('Growth conversation routing target id is invalid.');
            }
            if(isset($this->targets[$route]))throw new InvalidArgumentException('Duplicate Growth conversation routing target: '.$route);
            $this->targets[$route]=$target;
        }
    }

    public function get(string $route):GrowthConversationRoutingTargetInterface
    {
        $route=strtolower(trim($route));
        return $this->targets[$route]??throw new InvalidArgumentException('No Growth conversation routing target registered for: '.$route);
    }

    public function has(string $route):bool{return isset($this->targets[strtolower(trim($route))]);}

    /** @return list<string> */
    public function routes():array
    {
        $routes=array_keys($this->targets);sort($routes,SORT_STRING);return $routes;
    }
}

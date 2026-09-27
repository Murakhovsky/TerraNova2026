<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use Domains\Growth\Application\Contract\GrowthHandoffTargetInterface;
use InvalidArgumentException;

final class GrowthHandoffTargetRegistry
{
    /** @var array<string,GrowthHandoffTargetInterface> */
    private array $targets=[];

    /** @param iterable<GrowthHandoffTargetInterface> $targets */
    public function __construct(iterable $targets)
    {
        foreach($targets as $target){
            $domain=strtolower(trim($target->domain()));
            if($domain===''||strlen($domain)>80||!preg_match('/^[a-z][a-z0-9_-]*$/',$domain)){
                throw new InvalidArgumentException('Growth handoff target Domain id is invalid.');
            }
            if(isset($this->targets[$domain]))throw new InvalidArgumentException('Duplicate Growth handoff target: '.$domain);
            $this->targets[$domain]=$target;
        }
    }

    public function get(string $domain): GrowthHandoffTargetInterface
    {
        $domain=strtolower(trim($domain));
        return $this->targets[$domain]??throw new InvalidArgumentException('No Growth handoff target registered for Domain: '.$domain);
    }

    /** @return list<string> */
    public function domains(): array
    {
        $domains=array_keys($this->targets);
        sort($domains,SORT_STRING);
        return $domains;
    }
}

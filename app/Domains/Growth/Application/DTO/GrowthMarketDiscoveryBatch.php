<?php
declare(strict_types=1);

namespace Domains\Growth\Application\DTO;

use InvalidArgumentException;

final readonly class GrowthMarketDiscoveryBatch
{
    /** @param list<GrowthMarketDiscoveredAccount> $items */
    public function __construct(public array $items,public ?string $nextCursor=null)
    {
        foreach($items as $item){
            if(!$item instanceof GrowthMarketDiscoveredAccount)throw new InvalidArgumentException('Market discovery batch contains an invalid item.');
        }
        if($nextCursor!==null&&(trim($nextCursor)===''||mb_strlen($nextCursor)>1000)){
            throw new InvalidArgumentException('Market discovery next cursor is invalid.');
        }
    }
}

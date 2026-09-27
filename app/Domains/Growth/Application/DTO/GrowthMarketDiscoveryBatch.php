<?php
declare(strict_types=1);

namespace Domains\Growth\Application\DTO;

use InvalidArgumentException;

final readonly class GrowthMarketDiscoveryBatch
{
    /**
     * @param list<GrowthMarketDiscoveredAccount> $items
     * @param list<string> $errorSummaries
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor=null,
        public int $rejectedCount=0,
        public array $errorSummaries=[],
    ) {
        foreach($items as $item){
            if(!$item instanceof GrowthMarketDiscoveredAccount)throw new InvalidArgumentException('Market discovery batch contains an invalid item.');
        }
        if($nextCursor!==null&&(trim($nextCursor)===''||mb_strlen($nextCursor)>1000)){
            throw new InvalidArgumentException('Market discovery next cursor is invalid.');
        }
        if($rejectedCount<0)throw new InvalidArgumentException('Market discovery rejected count is invalid.');
        if(count($errorSummaries)>20)throw new InvalidArgumentException('Market discovery error summary list is too large.');
        foreach($errorSummaries as $summary){
            if(!is_string($summary)||trim($summary)===''||mb_strlen($summary)>1000){
                throw new InvalidArgumentException('Market discovery error summary is invalid.');
            }
        }
        if($rejectedCount===0&&$errorSummaries!==[]){
            throw new InvalidArgumentException('Market discovery errors require rejected items.');
        }
    }

    public function observedCount():int{return count($this->items)+$this->rejectedCount;}
}

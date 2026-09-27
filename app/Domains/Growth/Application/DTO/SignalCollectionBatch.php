<?php
declare(strict_types=1);

namespace Domains\Growth\Application\DTO;

use InvalidArgumentException;

final readonly class SignalCollectionBatch
{
    /** @param list<CollectedSignal> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor = null,
    ) {
        foreach($items as $item){
            if(!$item instanceof CollectedSignal)throw new InvalidArgumentException('Growth signal collection batch contains an invalid item.');
        }
        if($nextCursor!==null&&trim($nextCursor)==='')throw new InvalidArgumentException('Growth signal collection next cursor must be null or non-empty.');
    }
}

<?php
declare(strict_types=1);

namespace Domains\Growth\Application\DTO;

use InvalidArgumentException;

final readonly class SignalCollectionRequest
{
    public function __construct(
        public string $organizationId,
        public ?string $cursor = null,
        public int $limit = 100,
    ) {
        if(trim($organizationId)==='')throw new InvalidArgumentException('Growth signal collection organization is required.');
        if($cursor!==null&&trim($cursor)==='')throw new InvalidArgumentException('Growth signal collection cursor must be null or non-empty.');
        if($limit<1||$limit>500)throw new InvalidArgumentException('Growth signal collection limit must be between 1 and 500.');
    }
}

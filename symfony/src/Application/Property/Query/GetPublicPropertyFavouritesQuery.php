<?php

declare(strict_types=1);

namespace App\Application\Property\Query;

use Kernel\Application\Query\QueryInterface;

final readonly class GetPublicPropertyFavouritesQuery implements QueryInterface
{
    /** @param list<string> $publicIds */
    public function __construct(public array $publicIds)
    {
    }
}

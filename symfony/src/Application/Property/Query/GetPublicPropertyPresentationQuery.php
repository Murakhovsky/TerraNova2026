<?php

declare(strict_types=1);

namespace App\Application\Property\Query;

use Kernel\Application\Query\QueryInterface;

final readonly class GetPublicPropertyPresentationQuery implements QueryInterface
{
    public function __construct(public string $slug)
    {
    }
}

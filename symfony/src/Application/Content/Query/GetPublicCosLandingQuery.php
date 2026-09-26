<?php
declare(strict_types=1);

namespace App\Application\Content\Query;

use Kernel\Application\Query\QueryInterface;

final readonly class GetPublicCosLandingQuery implements QueryInterface
{
    public function __construct(public string $lang = 'en') {}
}

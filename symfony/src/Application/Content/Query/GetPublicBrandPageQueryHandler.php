<?php

declare(strict_types=1);

namespace App\Application\Content\Query;

use Domains\Content\Application\Service\PublicPageCatalog;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetPublicBrandPageQueryHandler implements QueryHandlerInterface
{
    public function __construct(private PublicPageCatalog $pages)
    {
    }

    /** @return array<string,mixed>|null */
    public function __invoke(GetPublicBrandPageQuery $query): ?array
    {
        return $this->pages->page($query->slug);
    }
}

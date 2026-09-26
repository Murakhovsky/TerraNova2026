<?php

declare(strict_types=1);

namespace App\Application\Content\Query;

use Domains\Content\Application\Contract\ContentServiceInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetPublicGuideQueryHandler implements QueryHandlerInterface
{
    public function __construct(private ContentServiceInterface $content)
    {
    }

    /** @return array<string,mixed>|null */
    public function __invoke(GetPublicGuideQuery $query): ?array
    {
        return $this->content->publicLanding($query->slug);
    }
}

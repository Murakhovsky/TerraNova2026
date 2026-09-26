<?php

declare(strict_types=1);

namespace App\Application\Content\Query;

use Domains\Content\Application\Contract\ContentServiceInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetPublicBlogQueryHandler implements QueryHandlerInterface
{
    public function __construct(private ContentServiceInterface $content)
    {
    }

    /** @return array<string,mixed> */
    public function __invoke(GetPublicBlogQuery $query): array
    {
        return $this->content->publicPosts(max(1, $query->page));
    }
}

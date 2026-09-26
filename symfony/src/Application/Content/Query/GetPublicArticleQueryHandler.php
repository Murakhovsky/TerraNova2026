<?php

declare(strict_types=1);

namespace App\Application\Content\Query;

use Domains\Content\Application\Contract\ContentServiceInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetPublicArticleQueryHandler implements QueryHandlerInterface
{
    public function __construct(private ContentServiceInterface $content)
    {
    }

    /** @return array{article:array<string,mixed>,related:list<array<string,mixed>>}|null */
    public function __invoke(GetPublicArticleQuery $query): ?array
    {
        $article = $this->content->publicPost($query->slug);
        if ($article === null) {
            return null;
        }

        return [
            'article' => $article,
            'related' => $this->content->relatedPosts((int) ($article['id'] ?? 0)),
        ];
    }
}

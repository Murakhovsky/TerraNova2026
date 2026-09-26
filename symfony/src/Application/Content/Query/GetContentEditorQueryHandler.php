<?php

declare(strict_types=1);

namespace App\Application\Content\Query;

use Domains\Content\Application\Contract\ContentServiceInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetContentEditorQueryHandler implements QueryHandlerInterface
{
    public function __construct(private ContentServiceInterface $content)
    {
    }

    /** @return array{item:?array,revisions:list<array<string,mixed>>,not_found:bool} */
    public function __invoke(GetContentEditorQuery $query): array
    {
        $type = in_array($query->requestedType, ['blog_post', 'seo_landing'], true)
            ? $query->requestedType
            : 'blog_post';

        if ($query->contentId <= 0) {
            return [
                'item' => [
                    'id' => 0,
                    'content_type' => $type,
                    'status' => 'draft',
                    'robots' => 'index,follow',
                    'body_html' => '',
                    'seo_score' => 0,
                ],
                'revisions' => [],
                'not_found' => false,
            ];
        }

        $item = $this->content->item($query->contentId);
        if ($item === null) {
            return ['item' => null, 'revisions' => [], 'not_found' => true];
        }

        return [
            'item' => $item,
            'revisions' => $this->content->revisions($query->contentId),
            'not_found' => false,
        ];
    }
}

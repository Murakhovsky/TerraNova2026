<?php

declare(strict_types=1);

namespace App\Application\Content\Query;

use Kernel\Application\Query\QueryInterface;

final readonly class GetContentEditorQuery implements QueryInterface
{
    public function __construct(
        public int $contentId = 0,
        public string $requestedType = 'blog_post',
    ) {
    }
}

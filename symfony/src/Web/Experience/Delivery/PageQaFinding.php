<?php
declare(strict_types=1);

namespace App\Web\Experience\Delivery;

use InvalidArgumentException;

final readonly class PageQaFinding
{
    public function __construct(
        public string $id,
        public string $pageId,
        public string $severity,
        public string $category,
        public string $problem,
        public string $evidence,
        public string $expectedFix,
        public bool $blocking,
    ) {
        if (!in_array($severity, ['BLOCKER','MAJOR','MINOR','SUGGESTION'], true)) {
            throw new InvalidArgumentException('Unsupported Experience QA severity: '.$severity);
        }
        if (!in_array($category, ['FUNCTIONAL','VISUAL','RESPONSIVE','ACCESSIBILITY','CONTENT','ARCHITECTURE','PERFORMANCE'], true)) {
            throw new InvalidArgumentException('Unsupported Experience QA category: '.$category);
        }
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}

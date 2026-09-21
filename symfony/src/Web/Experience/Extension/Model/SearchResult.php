<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Model;

use App\Web\Experience\Model\EntityRef;
use InvalidArgumentException;

final readonly class SearchResult
{
    public function __construct(
        public string $id,
        public string $label,
        public string $path,
        public string $kind = 'entity',
        public ?string $subtitle = null,
        public ?EntityRef $entity = null,
        public float $score = 0.0,
    ) {
        if (!preg_match('/^[a-z][a-z0-9._:-]*$/', $this->id)) {
            throw new InvalidArgumentException('SearchResult id must be a stable lowercase identifier.');
        }

        if (trim($this->label) === '') {
            throw new InvalidArgumentException('SearchResult label must not be empty.');
        }

        if (!str_starts_with($this->path, '/') || str_starts_with($this->path, '//')) {
            throw new InvalidArgumentException('SearchResult path must be an application-local absolute path.');
        }

        if (!preg_match('/^[a-z][a-z0-9._-]*$/', $this->kind)) {
            throw new InvalidArgumentException('SearchResult kind must be a stable lowercase identifier.');
        }

        if (!is_finite($this->score) || $this->score < 0.0) {
            throw new InvalidArgumentException('SearchResult score must be a finite non-negative number.');
        }
    }
}

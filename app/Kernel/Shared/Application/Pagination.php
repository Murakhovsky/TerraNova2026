<?php
declare(strict_types=1);

namespace Kernel\Shared\Application;

use InvalidArgumentException;

final readonly class Pagination
{
    public function __construct(
        public int $page = 1,
        public int $perPage = 30,
    ) {
        if ($page < 1) {
            throw new InvalidArgumentException('Page must be at least 1.');
        }
        if ($perPage < 1 || $perPage > 200) {
            throw new InvalidArgumentException('Per-page must be between 1 and 200.');
        }
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function limit(): int
    {
        return $this->perPage;
    }
}

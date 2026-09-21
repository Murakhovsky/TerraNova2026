<?php

declare(strict_types=1);

namespace App\Web\Experience\Data;

use InvalidArgumentException;

final readonly class DataGridPage
{
    /**
     * @param list<array<string,mixed>> $rows
     */
    public function __construct(
        public array $rows,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
        if ($this->total < 0 || $this->page < 1 || $this->perPage < 1) {
            throw new InvalidArgumentException('Invalid DataGrid pagination values.');
        }
    }

    public function pageCount(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function from(): int
    {
        return $this->total === 0 ? 0 : (($this->page - 1) * $this->perPage) + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->page * $this->perPage);
    }
}

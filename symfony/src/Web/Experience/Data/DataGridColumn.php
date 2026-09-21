<?php

declare(strict_types=1);

namespace App\Web\Experience\Data;

use InvalidArgumentException;

final readonly class DataGridColumn
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $sortable = false,
        public bool $filterable = false,
        public bool $defaultVisible = true,
        public int $mobilePriority = 100,
        public string $align = 'start',
    ) {
        if (!preg_match('/^[a-z][a-z0-9_.-]*$/', $this->key)) {
            throw new InvalidArgumentException('DataGrid column key must be a stable machine identifier.');
        }

        if (trim($this->label) === '') {
            throw new InvalidArgumentException('DataGrid column label must not be empty.');
        }

        if (!in_array($this->align, ['start', 'center', 'end'], true)) {
            throw new InvalidArgumentException('DataGrid column align must be start, center or end.');
        }
    }
}

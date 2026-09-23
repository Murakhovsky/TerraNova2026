<?php

declare(strict_types=1);

namespace App\Web\Experience\Data;

use InvalidArgumentException;

final readonly class DataGridFilter
{
    /**
     * @param array<string,string> $options
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $options,
        public ?string $value = null,
        public string $type = 'select',
        public ?string $placeholder = null,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_.-]*$/', $this->key)) {
            throw new InvalidArgumentException('DataGrid filter key must be a stable machine identifier.');
        }

        if (trim($this->label) === '') {
            throw new InvalidArgumentException('DataGrid filter label must not be empty.');
        }

        if (!in_array($this->type, ['select', 'text'], true)) {
            throw new InvalidArgumentException('DataGrid filter type must be select or text.');
        }

        if ($this->type === 'select' && $this->options === []) {
            throw new InvalidArgumentException('Select DataGrid filter requires options.');
        }
    }
}

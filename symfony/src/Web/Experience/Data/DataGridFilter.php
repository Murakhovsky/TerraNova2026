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
    ) {
        if (!preg_match('/^[a-z][a-z0-9_.-]*$/', $this->key)) {
            throw new InvalidArgumentException('DataGrid filter key must be a stable machine identifier.');
        }

        if (trim($this->label) === '') {
            throw new InvalidArgumentException('DataGrid filter label must not be empty.');
        }
    }
}

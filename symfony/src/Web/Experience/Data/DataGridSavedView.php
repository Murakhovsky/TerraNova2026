<?php

declare(strict_types=1);

namespace App\Web\Experience\Data;

use InvalidArgumentException;

final readonly class DataGridSavedView
{
    public function __construct(
        public string $id,
        public string $label,
        public DataGridQuery $query,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_.-]*$/', $this->id)) {
            throw new InvalidArgumentException('Saved view id must be a stable machine identifier.');
        }

        if (trim($this->label) === '') {
            throw new InvalidArgumentException('Saved view label must not be empty.');
        }
    }
}

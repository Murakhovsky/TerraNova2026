<?php

declare(strict_types=1);

namespace App\Web\Property\ViewModel;

final readonly class PublicPropertyFavouritesViewModel
{
    /** @param list<array<string,mixed>> $properties */
    public function __construct(
        public array $properties,
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        if ($this->error !== null) {
            return 'error';
        }

        return $this->properties === [] ? 'empty' : 'normal';
    }

    public function total(): int
    {
        return count($this->properties);
    }
}

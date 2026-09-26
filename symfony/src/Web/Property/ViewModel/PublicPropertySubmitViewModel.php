<?php

declare(strict_types=1);

namespace App\Web\Property\ViewModel;

final readonly class PublicPropertySubmitViewModel
{
    /**
     * @param list<array<string,mixed>> $types
     * @param array<string,mixed> $formData
     */
    public function __construct(
        public array $types,
        public array $formData,
        public int $yearMax,
        public ?string $submissionStatus = null,
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        return ($this->error !== null || $this->submissionStatus !== null)
            ? 'error'
            : 'normal';
    }
}

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
        public bool $submissionOk = false,
    ) {
    }

    public function state(): string
    {
        if ($this->error !== null || ($this->submissionStatus !== null && !$this->submissionOk)) {
            return 'error';
        }

        // A successful submission is feedback within the normal FormEditor state.
        // PageArchetype::FormEditor does not define a separate "success" page state.
        return 'normal';
    }
}

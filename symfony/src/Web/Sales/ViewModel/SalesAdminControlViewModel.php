<?php

declare(strict_types=1);

namespace App\Web\Sales\ViewModel;

final readonly class SalesAdminControlViewModel
{
    /** @param array<string,mixed> $data */
    public function __construct(
        public string $kind,
        public array $data,
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        return $this->error === null ? 'normal' : 'error';
    }
}

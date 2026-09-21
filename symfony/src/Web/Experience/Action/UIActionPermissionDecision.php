<?php

declare(strict_types=1);

namespace App\Web\Experience\Action;

final readonly class UIActionPermissionDecision
{
    private function __construct(
        public bool $allowed,
        public ?string $reason = null,
    ) {
    }

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(string $reason = 'Permission denied.'): self
    {
        return new self(false, trim($reason) !== '' ? trim($reason) : 'Permission denied.');
    }
}

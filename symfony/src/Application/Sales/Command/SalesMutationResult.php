<?php
declare(strict_types=1);

namespace App\Application\Sales\Command;

final readonly class SalesMutationResult
{
    /** @param array<string,mixed> $data */
    private function __construct(
        public bool $ok,
        public string $code,
        public array $data = [],
        public ?string $message = null,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function success(string $code, array $data = []): self
    {
        return new self(true, $code, $data);
    }

    /** @param array<string,mixed> $data */
    public static function failure(string $code, string $message, array $data = []): self
    {
        return new self(false, $code, $data, $message);
    }
}

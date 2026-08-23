<?php
declare(strict_types=1);

namespace Domains\Sales\Crm;

final readonly class ExternalResult
{
    private function __construct(
        public bool $successful,
        public ?string $externalId,
        public array $data,
        public ?string $error,
    ) {
    }

    public static function success(string $externalId, array $data = []): self
    {
        return new self(true, $externalId, $data, null);
    }

    public static function failure(string $error, array $data = []): self
    {
        return new self(false, null, $data, $error);
    }
}

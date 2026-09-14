<?php
declare(strict_types=1);

namespace Domains\Sales\Application\DTO;

final readonly class PublicLeadResult
{
    private function __construct(
        public bool $ok,
        public string $code,
        public ?int $leadId = null,
    ) {
    }

    public static function accepted(?int $leadId = null): self
    {
        return new self(true, 'accepted', $leadId);
    }

    public static function rejected(string $code): self
    {
        return new self(false, $code);
    }
}

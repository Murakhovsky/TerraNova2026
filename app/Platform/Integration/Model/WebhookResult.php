<?php
declare(strict_types=1);

namespace Platform\Integration\Model;

final readonly class WebhookResult
{
    /** @param array<string,mixed> $output */
    public function __construct(
        public bool $handled,
        public array $output = [],
        public ?string $error = null,
    ) {
    }
}

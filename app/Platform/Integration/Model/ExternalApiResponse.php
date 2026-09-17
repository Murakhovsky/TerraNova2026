<?php
declare(strict_types=1);

namespace Platform\Integration\Model;

final readonly class ExternalApiResponse
{
    /** @param array<string,mixed> $data @param array<string,string> $headers */
    public function __construct(
        public int $statusCode,
        public array $data = [],
        public array $headers = [],
        public ?string $externalRequestId = null,
    ) {
    }

    public function successful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}

<?php

declare(strict_types=1);

namespace App\Web\Experience\Registry;

final readonly class RouteExemption
{
    public function __construct(
        public string $routeName,
        public string $path,
        public string $reason,
        public string $description,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            routeName: (string) ($data['route'] ?? ''),
            path: (string) ($data['path'] ?? ''),
            reason: (string) ($data['reason'] ?? ''),
            description: (string) ($data['description'] ?? ''),
        );
    }
}

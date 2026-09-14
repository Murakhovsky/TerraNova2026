<?php
declare(strict_types=1);

namespace Domains\Property\Network;

final readonly class PropertyNetworkDeliveryResult
{
    /**
     * @param list<string> $succeededKeys
     * @param array<string,string> $failed
     */
    public function __construct(
        public array $succeededKeys,
        public array $failed = [],
        public ?string $nextCursor = null,
    ) {}

    public function succeeded(string $recordKey): bool
    {
        return in_array($recordKey, $this->succeededKeys, true);
    }

    public function error(string $recordKey): ?string
    {
        return $this->failed[$recordKey] ?? null;
    }

    public function isComplete(): bool
    {
        return $this->failed === [];
    }
}

<?php
declare(strict_types=1);

namespace Kernel\Event;

final readonly class EventMetadata
{
    public function __construct(
        public string $correlationId,
        public ?string $causationId,
        public string $actorType,
        public string $actorId,
        public int $schemaVersion = 1,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['correlationId'] ?? $data['correlation_id'] ?? ''),
            isset($data['causationId']) ? (string) $data['causationId'] : (
                isset($data['causation_id']) ? (string) $data['causation_id'] : null
            ),
            (string) ($data['actorType'] ?? $data['actor_type'] ?? 'SYSTEM'),
            (string) ($data['actorId'] ?? $data['actor_id'] ?? 'system'),
            (int) ($data['schemaVersion'] ?? $data['schema_version'] ?? 1),
        );
    }
}

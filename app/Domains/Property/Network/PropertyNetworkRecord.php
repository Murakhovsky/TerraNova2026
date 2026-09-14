<?php
declare(strict_types=1);

namespace Domains\Property\Network;

use InvalidArgumentException;

final readonly class PropertyNetworkRecord
{
    public const UPSERT = 'UPSERT';
    public const DELETE = 'DELETE';

    public function __construct(
        public string $externalEntityType,
        public string $externalId,
        public array $payload,
        public string $operation = self::UPSERT,
        public ?string $externalVersion = null,
        public ?string $observedAt = null,
    ) {
        if (trim($this->externalEntityType) === '' || trim($this->externalId) === '') {
            throw new InvalidArgumentException('Network record requires external entity type and id.');
        }
        if (!in_array($this->operation, [self::UPSERT, self::DELETE], true)) {
            throw new InvalidArgumentException('Unsupported network operation: ' . $this->operation);
        }
    }

    public function key(): string
    {
        return $this->externalEntityType . ':' . $this->externalId;
    }

    public function payloadHash(): string
    {
        return hash('sha256', json_encode(self::canonicalize($this->payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map(self::canonicalize(...), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::canonicalize($item);
        return $value;
    }
}

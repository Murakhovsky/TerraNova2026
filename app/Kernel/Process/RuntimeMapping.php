<?php
declare(strict_types=1);

namespace Kernel\Process;

use InvalidArgumentException;

final readonly class RuntimeMapping
{
    public const TYPES = ['use_case', 'command', 'event', 'contract', 'source'];

    public function __construct(
        public string $type,
        public ?string $ref = null,
        public ?string $path = null,
        public ?string $symbol = null,
    ) {
        if (!in_array($this->type, self::TYPES, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported process runtime mapping type: %s.', $this->type));
        }

        if ($this->type === 'source') {
            if ($this->path === null || trim($this->path) === '') {
                throw new InvalidArgumentException('Source process mapping requires a path.');
            }
            return;
        }

        if ($this->ref === null || trim($this->ref) === '') {
            throw new InvalidArgumentException(sprintf('%s process mapping requires a ref.', $this->type));
        }
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            type: (string)($data['type'] ?? ''),
            ref: isset($data['ref']) ? (string)$data['ref'] : null,
            path: isset($data['path']) ? (string)$data['path'] : null,
            symbol: isset($data['symbol']) ? (string)$data['symbol'] : null,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $data = ['type' => $this->type];
        if ($this->ref !== null) $data['ref'] = $this->ref;
        if ($this->path !== null) $data['path'] = $this->path;
        if ($this->symbol !== null) $data['symbol'] = $this->symbol;
        return $data;
    }
}

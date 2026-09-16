<?php
declare(strict_types=1);

namespace Kernel\Process;

use InvalidArgumentException;

final readonly class ProcessEdge
{
    public function __construct(
        public string $from,
        public string $to,
        public ?string $label = null,
    ) {
        if (trim($this->from) === '' || trim($this->to) === '') {
            throw new InvalidArgumentException('Process edge requires source and target step ids.');
        }
        if ($this->label !== null && trim($this->label) === '') {
            throw new InvalidArgumentException('Process edge label cannot be empty.');
        }
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            from: (string)($data['from'] ?? ''),
            to: (string)($data['to'] ?? ''),
            label: isset($data['label']) ? (string)$data['label'] : null,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $data = ['from' => $this->from, 'to' => $this->to];
        if ($this->label !== null) $data['label'] = $this->label;
        return $data;
    }
}

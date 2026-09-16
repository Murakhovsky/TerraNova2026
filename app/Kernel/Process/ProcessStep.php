<?php
declare(strict_types=1);

namespace Kernel\Process;

use InvalidArgumentException;

final readonly class ProcessStep
{
    public const KINDS = ['operation', 'state', 'decision', 'outcome', 'manual'];
    public const CAPABILITY_GAPS = ['missing-domain-capability'];

    /** @param list<RuntimeMapping> $runtime */
    public function __construct(
        public string $id,
        public string $label,
        public string $kind,
        public string $owner,
        public string $domain,
        public ?string $capability,
        public ?string $capabilityGap = null,
        public bool $critical = false,
        public array $runtime = [],
    ) {
        if (!preg_match('/^[a-z][a-z0-9_-]*$/', $this->id)) {
            throw new InvalidArgumentException(sprintf('Invalid process step id: %s.', $this->id));
        }
        if (trim($this->label) === '') {
            throw new InvalidArgumentException(sprintf('Process step %s requires a label.', $this->id));
        }
        if (!in_array($this->kind, self::KINDS, true)) {
            throw new InvalidArgumentException(sprintf('Process step %s has unsupported kind %s.', $this->id, $this->kind));
        }
        if (trim($this->owner) === '') {
            throw new InvalidArgumentException(sprintf('Process step %s requires an owner.', $this->id));
        }
        if (!preg_match('/^[a-z][a-z0-9_.-]*$/', $this->domain)) {
            throw new InvalidArgumentException(sprintf('Process step %s has invalid domain %s.', $this->id, $this->domain));
        }

        if ($this->capability !== null) {
            if (trim($this->capability) === '' || !str_starts_with($this->capability, $this->domain . '.')) {
                throw new InvalidArgumentException(sprintf('Process step %s capability must belong to %s.*.', $this->id, $this->domain));
            }
            if ($this->capabilityGap !== null) {
                throw new InvalidArgumentException(sprintf('Process step %s cannot declare capability and capability gap together.', $this->id));
            }
        } elseif (!in_array($this->capabilityGap, self::CAPABILITY_GAPS, true)) {
            throw new InvalidArgumentException(sprintf('Process step %s requires a supported explicit capability gap.', $this->id));
        }

        foreach ($this->runtime as $mapping) {
            if (!$mapping instanceof RuntimeMapping) {
                throw new InvalidArgumentException(sprintf('Process step %s contains an invalid runtime mapping.', $this->id));
            }
        }
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $runtime = [];
        foreach (is_array($data['runtime'] ?? null) ? $data['runtime'] : [] as $mapping) {
            if (!is_array($mapping)) {
                throw new InvalidArgumentException('Process runtime mappings must be objects.');
            }
            $runtime[] = RuntimeMapping::fromArray($mapping);
        }

        return new self(
            id: (string)($data['id'] ?? ''),
            label: (string)($data['label'] ?? ''),
            kind: (string)($data['kind'] ?? ''),
            owner: (string)($data['owner'] ?? ''),
            domain: (string)($data['domain'] ?? ''),
            capability: array_key_exists('capability', $data) && $data['capability'] !== null ? (string)$data['capability'] : null,
            capabilityGap: isset($data['capability_gap']) ? (string)$data['capability_gap'] : null,
            critical: ($data['critical'] ?? false) === true,
            runtime: $runtime,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'label' => $this->label,
            'kind' => $this->kind,
            'owner' => $this->owner,
            'domain' => $this->domain,
            'capability' => $this->capability,
        ];
        if ($this->capabilityGap !== null) $data['capability_gap'] = $this->capabilityGap;
        $data['critical'] = $this->critical;
        $data['runtime'] = array_map(static fn (RuntimeMapping $mapping): array => $mapping->toArray(), $this->runtime);
        return $data;
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Experience\Device;

use InvalidArgumentException;

final readonly class DeviceCapabilities
{
    /** @var array<string, DeviceCapability> */
    private array $capabilities;

    /** @param list<DeviceCapability> $capabilities */
    public function __construct(array $capabilities = [])
    {
        $normalized = [];

        foreach ($capabilities as $capability) {
            if (!$capability instanceof DeviceCapability) {
                throw new InvalidArgumentException('Device capabilities must use DeviceCapability values.');
            }

            $normalized[$capability->value] = $capability;
        }

        ksort($normalized);
        $this->capabilities = $normalized;
    }

    public function supports(DeviceCapability $capability): bool
    {
        return isset($this->capabilities[$capability->value]);
    }

    /** @return list<DeviceCapability> */
    public function all(): array
    {
        return array_values($this->capabilities);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->capabilities);
    }

    /** @param list<string> $names */
    public static function fromNames(array $names): self
    {
        $capabilities = [];

        foreach ($names as $name) {
            $capability = DeviceCapability::tryFrom(trim($name));
            if ($capability === null) {
                throw new InvalidArgumentException(sprintf('Unsupported device capability: %s.', $name));
            }

            $capabilities[] = $capability;
        }

        return new self($capabilities);
    }
}

<?php
declare(strict_types=1);

namespace Platform\Integration\Service;

use InvalidArgumentException;
use Platform\Integration\Contract\ConnectorInterface;
use Platform\Integration\Contract\ConnectorRegistryInterface;
use Platform\Integration\Model\ConnectorDefinition;
use RuntimeException;

final class ConnectorRegistry implements ConnectorRegistryInterface
{
    /** @var array<string,ConnectorInterface> */
    private array $connectors = [];

    /** @param iterable<ConnectorInterface> $connectors */
    public function __construct(iterable $connectors = [])
    {
        foreach ($connectors as $connector) {
            $this->register($connector);
        }
    }

    public function register(ConnectorInterface $connector): void
    {
        $key = $connector->definition()->key;
        if (isset($this->connectors[$key])) {
            throw new InvalidArgumentException(sprintf('Connector %s is already registered.', $key));
        }
        $this->connectors[$key] = $connector;
    }

    public function get(string $definitionKey): ConnectorInterface
    {
        return $this->connectors[$definitionKey] ?? throw new RuntimeException(sprintf('Connector %s is not registered.', $definitionKey));
    }

    public function has(string $definitionKey): bool
    {
        return isset($this->connectors[$definitionKey]);
    }

    public function definitions(): array
    {
        return array_values(array_map(static fn (ConnectorInterface $connector): ConnectorDefinition => $connector->definition(), $this->connectors));
    }
}

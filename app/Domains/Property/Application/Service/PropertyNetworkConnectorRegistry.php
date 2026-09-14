<?php
declare(strict_types=1);

namespace Domains\Property\Application\Service;

use Domains\Property\Application\Contract\PropertyNetworkConnectorInterface;
use RuntimeException;

final class PropertyNetworkConnectorRegistry
{
    /** @var array<string,PropertyNetworkConnectorInterface> */
    private array $connectors = [];

    /** @param iterable<PropertyNetworkConnectorInterface> $connectors */
    public function __construct(iterable $connectors = [])
    {
        foreach ($connectors as $connector) $this->register($connector);
    }

    public function register(PropertyNetworkConnectorInterface $connector): void
    {
        $code = trim($connector->code());
        if ($code === '') throw new RuntimeException('Property network connector code cannot be empty.');
        $this->connectors[$code] = $connector;
    }

    public function get(string $code): PropertyNetworkConnectorInterface
    {
        if (!isset($this->connectors[$code])) {
            throw new RuntimeException('Property network connector adapter is not registered: ' . $code);
        }
        return $this->connectors[$code];
    }

    /** @return list<string> */
    public function codes(): array
    {
        $codes = array_keys($this->connectors);
        sort($codes, SORT_STRING);
        return $codes;
    }
}

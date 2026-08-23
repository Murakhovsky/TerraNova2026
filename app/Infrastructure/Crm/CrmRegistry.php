<?php
declare(strict_types=1);

namespace Infrastructure\Crm;

use Domains\Sales\Crm\Contract\CrmPort;
use RuntimeException;

final class CrmRegistry
{
    /** @var array<string, CrmPort> */
    private array $adapters = [];

    /** @param iterable<CrmPort> $adapters */
    public function __construct(iterable $adapters = [])
    {
        foreach ($adapters as $adapter) {
            $this->register($adapter);
        }
    }

    public function register(CrmPort $adapter): void
    {
        $this->adapters[$adapter->provider()] = $adapter;
    }

    public function get(string $provider): CrmPort
    {
        return $this->adapters[$provider]
            ?? throw new RuntimeException(sprintf('CRM adapter "%s" is not registered.', $provider));
    }
}

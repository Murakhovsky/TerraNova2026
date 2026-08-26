<?php
declare(strict_types=1);

namespace Infrastructure\Integration\Crm;

use Domains\Sales\Application\Contract\CrmProviderInterface;
use RuntimeException;

final class CrmRegistry
{
    /** @var array<string, CrmProviderInterface> */
    private array $providers = [];

    /** @param iterable<CrmProviderInterface> $providers */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) $this->register($provider);
    }

    public function register(CrmProviderInterface $provider): void
    {
        $this->providers[$provider->provider()] = $provider;
    }

    public function get(string $provider): CrmProviderInterface
    {
        return $this->providers[$provider]
            ?? throw new RuntimeException(sprintf('CRM provider "%s" is not registered.', $provider));
    }
}

<?php
declare(strict_types=1);

namespace Kernel\Llm;

use InvalidArgumentException;

final class LlmProviderRegistry
{
    /** @var array<string, StructuredLlmClientInterface> */
    private array $providers;

    /** @param array<string, StructuredLlmClientInterface> $providers */
    public function __construct(array $providers)
    {
        if ($providers === []) {
            throw new InvalidArgumentException('LLM provider registry cannot be empty.');
        }
        foreach ($providers as $providerId => $client) {
            if (!is_string($providerId) || !preg_match('/^[A-Za-z0-9._:-]+$/', $providerId)) {
                throw new InvalidArgumentException(sprintf('Invalid LLM provider id: %s.', (string) $providerId));
            }
            if (!$client instanceof StructuredLlmClientInterface) {
                throw new InvalidArgumentException(sprintf('LLM provider %s does not implement StructuredLlmClientInterface.', $providerId));
            }
        }
        $this->providers = $providers;
    }

    public function client(string $providerId): StructuredLlmClientInterface
    {
        if (!isset($this->providers[$providerId])) {
            throw new InvalidArgumentException(sprintf('Unknown LLM provider: %s.', $providerId));
        }
        return $this->providers[$providerId];
    }

    public function has(string $providerId): bool
    {
        return isset($this->providers[$providerId]);
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->providers);
    }
}

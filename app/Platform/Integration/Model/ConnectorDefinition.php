<?php
declare(strict_types=1);

namespace Platform\Integration\Model;

use InvalidArgumentException;

final readonly class ConnectorDefinition
{
    /**
     * @param list<string> $capabilities
     * @param array<string,mixed> $configurationSchema
     * @param array<string,mixed> $credentialSchema
     * @param list<string> $webhookEvents
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $provider,
        public array $capabilities,
        public array $configurationSchema = [],
        public array $credentialSchema = [],
        public array $webhookEvents = [],
    ) {
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,99}$/', $this->key)) {
            throw new InvalidArgumentException('Connector definition key must be a stable machine identifier.');
        }
        if (trim($this->name) === '' || trim($this->provider) === '') {
            throw new InvalidArgumentException('Connector definition requires name and provider.');
        }
        if ($this->capabilities === [] || count(array_unique($this->capabilities)) !== count($this->capabilities)) {
            throw new InvalidArgumentException('Connector capabilities must be non-empty and unique.');
        }
        foreach ($this->capabilities as $capability) {
            if (!is_string($capability) || !preg_match('/^[a-z][a-z0-9_.-]*$/', $capability)) {
                throw new InvalidArgumentException('Connector capabilities must be stable machine identifiers.');
            }
        }
    }
}

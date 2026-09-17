<?php
declare(strict_types=1);

namespace Platform\Integration\Contract;

use Platform\Integration\Model\ConnectorDefinition;

interface ConnectorRegistryInterface
{
    public function get(string $definitionKey): ConnectorInterface;

    public function has(string $definitionKey): bool;

    /** @return list<ConnectorDefinition> */
    public function definitions(): array;
}

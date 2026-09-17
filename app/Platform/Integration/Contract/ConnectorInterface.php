<?php
declare(strict_types=1);

namespace Platform\Integration\Contract;

use Platform\Integration\Model\Connection;
use Platform\Integration\Model\ConnectorDefinition;
use Platform\Integration\Model\Credential;

interface ConnectorInterface
{
    public function definition(): ConnectorDefinition;

    public function supports(string $capability): bool;

    public function client(Connection $connection, Credential $credential): ExternalApiClientInterface;
}

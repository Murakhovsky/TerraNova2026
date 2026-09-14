<?php
declare(strict_types=1);

namespace Kernel\Agent\Contract;

use Kernel\Agent\AgentDefinition;
use Kernel\Agent\LlmResponse;

interface OrganizationAwareLlmClientInterface extends LlmClientInterface
{
    public function structuredForOrganization(
        string $organizationId,
        AgentDefinition $agent,
        string $question,
        array $context,
        ?string $correlationId = null,
    ): LlmResponse;
}

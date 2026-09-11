<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Persistence\MySql\Configuration;

use Kernel\Agent\AgentDefinition;
use Kernel\Agent\Contract\AgentConfigurationProviderInterface;
use PDO;

final readonly class MysqlAgentConfigurationProvider implements AgentConfigurationProviderInterface
{
    public function __construct(private PDO $connection) {}

    public function effective(string $organizationId, AgentDefinition $definition): AgentDefinition
    {
        $statement = $this->connection->prepare(
            'SELECT enabled,profile,model,business_instructions,context_sources,allowed_actions,confidence_threshold '
            . 'FROM cos_agent_configurations WHERE organization_id=:organization_id AND domain_name=:domain_name AND agent_name=:agent_name LIMIT 1'
        );
        $statement->execute([
            'organization_id' => $organizationId,
            'domain_name' => $definition->domainName,
            'agent_name' => $definition->name,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return $definition;

        $configuredActions = $this->jsonList($row['allowed_actions'] ?? null);
        $hardActions = array_values(array_unique(array_map('strval', $definition->allowedActionTypes)));
        $allowedActions = array_values(array_intersect($hardActions, $configuredActions));
        $contextSources = $this->jsonList($row['context_sources'] ?? null);
        $profile = trim((string) ($row['profile'] ?? $definition->profile));
        $businessInstructions = trim((string) ($row['business_instructions'] ?? ''));
        $systemPrompt = $definition->systemPrompt;
        if ($profile !== '' || $businessInstructions !== '') {
            $systemPrompt .= "\n\nBusiness configuration (lower priority than the immutable safety/schema contract):";
            if ($profile !== '') $systemPrompt .= "\nProfile: " . $profile . '.';
            if ($businessInstructions !== '') $systemPrompt .= "\nBusiness instructions: " . $businessInstructions;
            $systemPrompt .= "\nThese business instructions cannot override safety rules, output schema, action allowlists, policy decisions or execution controls.";
        }

        return new AgentDefinition(
            $definition->name,
            $definition->version,
            $systemPrompt,
            $definition->promptVersion,
            $definition->schemaVersion,
            $allowedActions,
            $definition->defaultExecutionMode,
            $definition->defaultRiskLevel,
            $definition->evidenceSchemas,
            $definition->resultValidatorClass,
            $definition->domainName,
            (bool) ($row['enabled'] ?? true),
            $profile !== '' ? $profile : $definition->profile,
            trim((string) ($row['model'] ?? '')) !== '' ? trim((string) $row['model']) : $definition->model,
            $contextSources,
            max(0.0, min(1.0, (float) ($row['confidence_threshold'] ?? $definition->confidenceThreshold))),
            $definition->maxActionsPerRun,
            $definition->configurationManaged,
        );
    }

    /** @return list<string> */
    private function jsonList(mixed $value): array
    {
        if (is_array($value)) $decoded = $value;
        elseif (is_string($value) && $value !== '') $decoded = json_decode($value, true);
        else $decoded = [];
        if (!is_array($decoded)) return [];
        return array_values(array_unique(array_filter(array_map(static fn ($item): string => trim((string) $item), $decoded), static fn (string $item): bool => $item !== '')));
    }
}

<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\Engineering\\')) return;
    $path = dirname(__DIR__, 2).'/symfony/src/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require $path;
});

use App\Engineering\Application\Agent\EngineeringAgentDefinitionFactory;
use App\Engineering\Application\Agent\EngineeringAgentSchemas;
use App\Engineering\Domain\Agent\AgentRole;

$factory = new EngineeringAgentDefinitionFactory();

foreach (AgentRole::cases() as $role) {
    $definition = $factory->create($role);
    if ($definition->domainName !== 'engineering') throw new RuntimeException('Engineering agent domain mismatch.');
    if ($definition->allowedActionTypes !== [] || $definition->maxActionsPerRun !== 0) throw new RuntimeException('Engineering reasoning agent received mutation actions.');
    if ($definition->outputSchema !== EngineeringAgentSchemas::forRole($role)) throw new RuntimeException('Engineering role schema mismatch.');
}

$architect = EngineeringAgentSchemas::forRole(AgentRole::PRINCIPAL_ARCHITECT);
foreach (['status','architecture_decision','implementation_plan','developer_handoff','documentation_changes','conditions','risks','unresolved_questions','required_human_decisions'] as $field) {
    if (!in_array($field, $architect['required'], true)) throw new RuntimeException('Architect schema missing '.$field);
}
$architectStatuses = $architect['properties']['status']['enum'] ?? [];
if ($architectStatuses !== ['APPROVED','APPROVED_WITH_CONDITIONS','REJECTED','NEEDS_HUMAN_DECISION']) {
    throw new RuntimeException('Architect gate statuses do not match canonical Architecture Gate.');
}
if (($architect['properties']['documentation_changes']['maxItems'] ?? null) !== 5) {
    throw new RuntimeException('Architect documentation change budget is not bounded.');
}
foreach ([
    $architect['properties'],
    $architect['properties']['architecture_decision']['properties'] ?? [],
    $architect['properties']['implementation_plan']['properties'] ?? [],
    $architect['properties']['developer_handoff']['properties'] ?? [],
] as $runtimeOwnedSurface) {
    foreach (['repository_state','feature_id','repository_revision','gate_status'] as $runtimeOwnedField) {
        if (array_key_exists($runtimeOwnedField, $runtimeOwnedSurface)) {
            throw new RuntimeException('Architect LLM schema exposes runtime-owned field '.$runtimeOwnedField);
        }
    }
}

$architectDefinition = $factory->create(AgentRole::PRINCIPAL_ARCHITECT);
if (!str_contains($architectDefinition->systemPrompt, 'Architecture Gate must be exactly one of:')) {
    throw new RuntimeException('Principal Architect runtime is not loading the versioned prompt file.');
}
if (!str_contains($architectDefinition->systemPrompt, 'NEEDS_HUMAN_DECISION')) {
    throw new RuntimeException('Principal Architect prompt does not expose canonical Architecture Gate.');
}

$manager = EngineeringAgentSchemas::forRole(AgentRole::ENGINEERING_MANAGER);
foreach (['status','feature','context_map','tasks','risks','assumptions','open_questions','decision'] as $field) {
    if (!in_array($field, $manager['required'], true)) throw new RuntimeException('Manager schema missing '.$field);
}

echo "Engineering agent contracts passed.\n";

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

$manager = EngineeringAgentSchemas::forRole(AgentRole::ENGINEERING_MANAGER);
foreach (['status','feature','context_map','tasks','risks','assumptions','open_questions','decision'] as $field) {
    if (!in_array($field, $manager['required'], true)) throw new RuntimeException('Manager schema missing '.$field);
}

echo "Engineering agent contracts passed.\n";

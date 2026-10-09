<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\Engineering\\')) return;
    $path = dirname(__DIR__, 2).'/symfony/src/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require $path;
});

use App\Engineering\Application\Agent\EngineeringSpecialistRequirementResolver;
use App\Engineering\Domain\Agent\AgentRole;

$resolver = new EngineeringSpecialistRequirementResolver();

$neutral = $resolver->resolve(
    [
        'affected_areas' => ['Engineering'],
        'non_functional_requirements' => ['Preserve idempotency and revision consistency.'],
        'constraints' => ['Human merge remains mandatory.'],
    ],
    [
        'security' => 'No security boundary change.',
        'permissions' => 'No permission surface change.',
        'tenant_isolation' => 'No tenant data access is introduced.',
        'database_changes' => [],
        'api_changes' => [],
        'observability' => 'Engineering execution journal remains authoritative.',
        'backward_compatibility' => 'No public contract change.',
    ],
    ['symfony/src/Engineering/Acceptance/V01FixtureTarget.php'],
);
if ($neutral !== []) {
    throw new RuntimeException('Structural schema keys, negated no-impact evidence, and words such as authoritative must not trigger specialists.');
}

$security = $resolver->resolve(
    ['functional_requirements' => ['Add authentication and tenant permissions for sensitive credentials.']],
);
if (!in_array(AgentRole::SECURITY_SPECIALIST, $security, true)) {
    throw new RuntimeException('Concrete security evidence must trigger SECURITY_SPECIALIST.');
}

$database = $resolver->resolve(
    ['scope' => ['Add a database migration for a new indexed column.']],
);
if (!in_array(AgentRole::DATABASE_MIGRATION_SPECIALIST, $database, true)) {
    throw new RuntimeException('Concrete database evidence must trigger DATABASE_MIGRATION_SPECIALIST.');
}

$devops = $resolver->resolve(
    ['scope' => ['Add a Docker worker and queue health check.']],
);
if (!in_array(AgentRole::DEVOPS_SPECIALIST, $devops, true)) {
    throw new RuntimeException('Concrete runtime evidence must trigger DEVOPS_SPECIALIST.');
}

$api = $resolver->resolve(
    ['scope' => ['Expose a versioned API endpoint.']],
);
if (!in_array(AgentRole::API_SPECIALIST, $api, true)) {
    throw new RuntimeException('Concrete API evidence must trigger API_SPECIALIST.');
}

$docs = $resolver->resolve(
    ['scope' => ['Update docs/integration.md and README.']],
);
if (!in_array(AgentRole::DOCUMENTATION_SPECIALIST, $docs, true)) {
    throw new RuntimeException('Concrete documentation evidence must trigger DOCUMENTATION_SPECIALIST.');
}

echo "Engineering specialist requirement resolver passed.\n";

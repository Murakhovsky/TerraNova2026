<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\Engineering\\')) return;
    $path = dirname(__DIR__, 2).'/symfony/src/'.str_replace('\\\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require $path;
});

use App\Engineering\Application\Agent\EngineeringAgentSchemas;
use App\Engineering\Domain\Agent\AgentRole;

$schema = EngineeringAgentSchemas::forRole(AgentRole::PRODUCT_REQUIREMENTS);
$criteria = $schema['properties']['feature']['properties']['acceptance_criteria'] ?? null;
if (!is_array($criteria) || ($criteria['minItems'] ?? 0) < 1) {
    throw new RuntimeException('Product acceptance criteria schema is missing/minimal.');
}
$item = $criteria['items'] ?? null;
if (!is_array($item) || ($item['type'] ?? null) !== 'object') {
    throw new RuntimeException('Product acceptance criterion item schema is not an object.');
}
$required = $item['required'] ?? [];
foreach (['id','description','verification_type'] as $field) {
    if (!in_array($field, is_array($required) ? $required : [], true)) {
        throw new RuntimeException('Product acceptance criterion schema does not require '.$field.'.');
    }
    if (!isset($item['properties'][$field])) {
        throw new RuntimeException('Product acceptance criterion schema does not define '.$field.'.');
    }
}
if (($item['additionalProperties'] ?? null) !== false) {
    throw new RuntimeException('Product acceptance criterion schema must reject undeclared fields.');
}

$runner = (string) file_get_contents(dirname(__DIR__, 2).'/symfony/src/Engineering/Application/Agent/EngineeringAgentRunner.php');
foreach (['validationFeedback', 'retry_correction', 'Previous structured output was rejected:'] as $needle) {
    if (!str_contains($runner, $needle)) {
        throw new RuntimeException('Engineering Agent retry correction feedback missing '.$needle);
    }
}

echo "Engineering Product structured-output schema contract passed.\n";

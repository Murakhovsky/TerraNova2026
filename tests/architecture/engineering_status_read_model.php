<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringStatusService.php');
$controller = (string) file_get_contents($root.'/symfony/src/Http/Api/V1/Controller/EngineeringReadController.php');

foreach (['agent_runs', 'artifacts', 'open_human_decisions', 'workflow'] as $needle) {
    if (!str_contains($service, $needle)) throw new RuntimeException('Engineering status read model missing '.$needle);
}
foreach (['TenantPermissions::MANAGE', 'manager_required', 'EngineeringStatusService'] as $needle) {
    if (!str_contains($controller, $needle)) throw new RuntimeException('Engineering status API boundary missing '.$needle);
}

echo "Engineering status read model passed.\n";

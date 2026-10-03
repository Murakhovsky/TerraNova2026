<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$routes = (string) file_get_contents($root.'/symfony/config/routes.yaml');
$controller = (string) file_get_contents($root.'/symfony/src/Http/Api/V1/Controller/EngineeringController.php');

foreach ([
    '/api/engineering/features',
    '/api/engineering/features/{id}/run',
    '/api/engineering/features/{id}/resume',
    '/api/engineering/features/{id}/cancel',
    '/api/engineering/features/{id}/runs',
    '/api/engineering/features/{id}/artifacts',
    '/api/engineering/features/{id}/human-decision',
] as $needle) {
    if (!str_contains($routes, $needle)) throw new RuntimeException('Engineering API route missing '.$needle);
}

foreach (['TenantPermissions::MANAGE','SessionCsrfValidator','EngineeringStatusService','EngineeringCancelService','answerAndResume'] as $needle) {
    if (!str_contains($controller, $needle)) throw new RuntimeException('Engineering API controller missing '.$needle);
}

echo "Engineering API contract passed.\n";

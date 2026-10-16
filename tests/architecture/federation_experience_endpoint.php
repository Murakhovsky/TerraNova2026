<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$route = (string) file_get_contents($root . '/symfony/config/routes.yaml');
$controller = (string) file_get_contents($root . '/symfony/src/Http/Api/V1/Controller/FederationExperiencePreferenceController.php');
$store = (string) file_get_contents($root . '/symfony/src/Persistence/Federation/FederationExperiencePreferenceStore.php');

foreach (['cos_federation_experience_preferences_show', 'cos_federation_experience_preferences_update',
    '/api/v1/experience/preferences'] as $fragment) {
    if (!str_contains($route, $fragment)) throw new RuntimeException('Experience preferences API route missing: ' . $fragment);
}
foreach (['$this->tenants->current()', 'TenantPermissions::ACCESS', '$this->csrf->isValid($request)',
    'ExperienceMode::tryFrom(', 'saveWorkspace(', 'saveDefaultMode('] as $fragment) {
    if (!str_contains($controller, $fragment)) throw new RuntimeException('Experience API missing guard: ' . $fragment);
}
foreach (['organization_id = :org', 'user_id = :user', 'self::identity($user)'] as $fragment) {
    if (!str_contains($store, $fragment)) throw new RuntimeException('Experience store missing tenancy isolation: ' . $fragment);
}
echo "Federation experience preferences API security contract passed.\n";

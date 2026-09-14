<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleManifest;

$states = new class implements ModuleStateRepositoryInterface {
    /** @var array<string, bool> */
    private array $values = [];

    public function enabledOverride(string $organizationId, string $moduleId): ?bool
    {
        return $this->values[$organizationId . ':' . $moduleId] ?? null;
    }

    public function setEnabled(string $organizationId, string $moduleId, bool $enabled): void
    {
        $this->values[$organizationId . ':' . $moduleId] = $enabled;
    }
};

$catalog = new ModuleCatalog([
    new ModuleManifest('core', 'Core', '1.0.0'),
    new ModuleManifest('sales', 'Sales', '0.4.0', dependencies: ['core']),
    new ModuleManifest('property', 'Property', '0.1.0', enabledByDefault: false),
]);
$resolver = new ActiveModuleResolver($catalog, $states);

if (!$resolver->isEnabled('org-a', 'sales')) throw new RuntimeException('Default-enabled module was not active.');
if ($resolver->isEnabled('org-a', 'property')) throw new RuntimeException('Default-disabled module became active.');

$states->setEnabled('org-a', 'sales', false);
if ($resolver->isEnabled('org-a', 'sales')) throw new RuntimeException('Explicit module disable was ignored.');

$states->setEnabled('org-a', 'sales', true);
$states->setEnabled('org-a', 'core', false);
if ($resolver->isEnabled('org-a', 'sales')) throw new RuntimeException('Disabled dependency did not disable dependent module.');
if (!$resolver->isConfiguredEnabled('org-a', 'sales')) throw new RuntimeException('Configured state was lost when dependency became inactive.');

$description = array_values(array_filter(
    $resolver->describe('org-a'),
    static fn (array $module): bool => $module['id'] === 'sales',
))[0] ?? null;
if (!is_array($description) || $description['active'] !== false || $description['configured_enabled'] !== true) {
    throw new RuntimeException('Module description does not separate configured and active state.');
}

$states->setEnabled('org-b', 'sales', false);
if (!$resolver->isEnabled('org-c', 'sales')) throw new RuntimeException('Tenant module state leaked between organizations.');

if ($resolver->isInstalled('org-a', 'unknown') || $resolver->isConfiguredEnabled('org-a', 'unknown')) {
    throw new RuntimeException('Unknown module reported installed or configured state.');
}

echo "Module activation invariants passed.\n";

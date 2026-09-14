<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ModuleLifecycleRepositoryInterface;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleInstallation;
use Kernel\Module\ModuleLifecycleManager;
use Kernel\Module\ModuleManifest;

$states = new class implements ModuleStateRepositoryInterface {
    /** @var array<string, bool> */
    public array $values = [];

    public function enabledOverride(string $organizationId, string $moduleId): ?bool
    {
        return $this->values[$organizationId . ':' . $moduleId] ?? null;
    }

    public function setEnabled(string $organizationId, string $moduleId, bool $enabled): void
    {
        $this->values[$organizationId . ':' . $moduleId] = $enabled;
    }
};

$installations = new class implements ModuleLifecycleRepositoryInterface {
    /** @var array<string, ModuleInstallation> */
    public array $values = [];

    public function find(string $organizationId, string $moduleId): ?ModuleInstallation
    {
        return $this->values[$organizationId . ':' . $moduleId] ?? null;
    }

    public function forOrganization(string $organizationId): array
    {
        return array_values(array_filter(
            $this->values,
            static fn (ModuleInstallation $installation): bool => $installation->organizationId === $organizationId,
        ));
    }

    public function record(string $organizationId, ModuleManifest $manifest, string $status): void
    {
        $this->values[$organizationId . ':' . $manifest->id] = new ModuleInstallation(
            $organizationId,
            $manifest->id,
            $status,
            $manifest->version,
            $manifest->schemaVersion,
        );
    }
};

$catalog = new ModuleCatalog([
    new ModuleManifest('core', 'Core', '1.0.0'),
    new ModuleManifest('crm', 'CRM', '1.0.0', dependencies: ['core']),
    new ModuleManifest('sales', 'Sales', '1.0.0', dependencies: ['crm']),
]);
$manager = new ModuleLifecycleManager($catalog, $states, $installations);
$resolver = new ActiveModuleResolver($catalog, $states, $installations);

$manager->install('org-a', 'sales');
foreach (['core', 'crm', 'sales'] as $moduleId) {
    if (!$resolver->isEnabled('org-a', $moduleId)) {
        throw new RuntimeException('Install did not activate dependency tree: ' . $moduleId);
    }
}

$blocked = false;
try {
    $manager->disable('org-a', 'core');
} catch (RuntimeException $error) {
    $blocked = str_contains($error->getMessage(), 'dependent module crm');
}
if (!$blocked) throw new RuntimeException('Dependency disable was not blocked while a dependent module is enabled.');

$manager->disable('org-a', 'sales');
$manager->disable('org-a', 'crm');
$manager->disable('org-a', 'core');
if ($resolver->isEnabled('org-a', 'sales')) throw new RuntimeException('Disabled module tree remained active.');

$manager->enable('org-a', 'sales');
foreach (['core', 'crm', 'sales'] as $moduleId) {
    if (!$resolver->isEnabled('org-a', $moduleId)) {
        throw new RuntimeException('Recursive enable did not restore dependency tree: ' . $moduleId);
    }
}

$blocked = false;
try {
    $manager->uninstall('org-a', 'core');
} catch (RuntimeException $error) {
    $blocked = str_contains($error->getMessage(), 'dependent module crm');
}
if (!$blocked) throw new RuntimeException('Dependency uninstall was not blocked while a dependent module is enabled.');

$manager->disable('org-a', 'sales');
$manager->disable('org-a', 'crm');
$manager->uninstall('org-a', 'core');
if ($resolver->isInstalled('org-a', 'core')) throw new RuntimeException('Uninstalled module still reports installed.');

$manager->enable('org-a', 'sales');
if (!$resolver->isEnabled('org-a', 'sales') || !$resolver->isInstalled('org-a', 'core')) {
    throw new RuntimeException('Enable did not reinstall an uninstalled dependency.');
}

echo "Module lifecycle invariants passed.\n";

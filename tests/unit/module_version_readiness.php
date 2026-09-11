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
    new ModuleManifest('core', 'Core', '2.0.0', schemaVersion: '2.0.0'),
    new ModuleManifest('sales', 'Sales', '2.0.0', dependencies: ['core'], schemaVersion: '2.0.0'),
]);
$resolver = new ActiveModuleResolver($catalog, $states, $installations);
$lifecycle = new ModuleLifecycleManager($catalog, $states, $installations);

// Legacy organizations without lifecycle records remain deployment-current for backwards compatibility.
if (!$resolver->isInstalled('legacy', 'sales') || !$resolver->isCurrent('legacy', 'sales') || !$resolver->isEnabled('legacy', 'sales')) {
    throw new RuntimeException('Legacy tenant compatibility was broken by version readiness gating.');
}

// An installed but stale module must not execute merely because it is configured enabled.
$installations->values['org-a:core'] = new ModuleInstallation('org-a', 'core', ModuleInstallation::INSTALLED, '2.0.0', '2.0.0');
$installations->values['org-a:sales'] = new ModuleInstallation('org-a', 'sales', ModuleInstallation::INSTALLED, '1.0.0', '1.0.0');
$states->setEnabled('org-a', 'core', true);
$states->setEnabled('org-a', 'sales', true);
if (!$resolver->isInstalled('org-a', 'sales')) {
    throw new RuntimeException('Stale module must still report installed.');
}
if ($resolver->isCurrent('org-a', 'sales') || $resolver->isEnabled('org-a', 'sales')) {
    throw new RuntimeException('Stale module version remained runtime-active.');
}
$description = array_values(array_filter(
    $resolver->describe('org-a'),
    static fn (array $module): bool => ($module['id'] ?? null) === 'sales',
))[0] ?? null;
if (!is_array($description)
    || ($description['installed_version'] ?? null) !== '1.0.0'
    || ($description['installed_schema_version'] ?? null) !== '1.0.0'
    || ($description['version_current'] ?? true) !== false
    || ($description['schema_version_current'] ?? true) !== false
    || ($description['current'] ?? true) !== false
) {
    throw new RuntimeException('Version readiness diagnostics are incomplete.');
}

$blocked = false;
try {
    $lifecycle->enable('org-a', 'sales');
} catch (RuntimeException $error) {
    $blocked = str_contains($error->getMessage(), 'requires upgrade before enable');
}
if (!$blocked) {
    throw new RuntimeException('Enable did not reject stale module installation.');
}

$lifecycle->upgrade('org-a', 'sales');
if (!$resolver->isCurrent('org-a', 'sales') || !$resolver->isEnabled('org-a', 'sales')) {
    throw new RuntimeException('Explicit upgrade did not restore module runtime readiness.');
}

// Schema drift alone is also enough to make a module unavailable.
$installations->values['org-b:core'] = new ModuleInstallation('org-b', 'core', ModuleInstallation::INSTALLED, '2.0.0', '2.0.0');
$installations->values['org-b:sales'] = new ModuleInstallation('org-b', 'sales', ModuleInstallation::INSTALLED, '2.0.0', '1.0.0');
$states->setEnabled('org-b', 'core', true);
$states->setEnabled('org-b', 'sales', true);
if ($resolver->isCurrent('org-b', 'sales') || $resolver->isEnabled('org-b', 'sales')) {
    throw new RuntimeException('Schema version drift remained runtime-active.');
}

// A stale dependency must deactivate its dependents and block target upgrade until repaired.
$installations->values['org-c:core'] = new ModuleInstallation('org-c', 'core', ModuleInstallation::INSTALLED, '1.0.0', '1.0.0');
$installations->values['org-c:sales'] = new ModuleInstallation('org-c', 'sales', ModuleInstallation::INSTALLED, '2.0.0', '2.0.0');
$states->setEnabled('org-c', 'core', true);
$states->setEnabled('org-c', 'sales', true);
if ($resolver->isEnabled('org-c', 'sales')) {
    throw new RuntimeException('Dependent module stayed active while a dependency was stale.');
}
$blocked = false;
try {
    $lifecycle->upgrade('org-c', 'sales');
} catch (RuntimeException $error) {
    $blocked = str_contains($error->getMessage(), 'dependency core requires upgrade');
}
if (!$blocked) {
    throw new RuntimeException('Target upgrade ignored stale dependency installation.');
}
$lifecycle->upgrade('org-c', 'core');
$lifecycle->upgrade('org-c', 'sales');
if (!$resolver->isEnabled('org-c', 'sales')) {
    throw new RuntimeException('Dependency-first upgrade did not restore dependent module readiness.');
}

echo "Module version readiness invariants passed.\n";

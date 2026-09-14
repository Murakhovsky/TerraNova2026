<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\EffectiveModuleContext;
use Kernel\Module\ModuleCapabilityRegistry;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleContributions;
use Kernel\Module\ModuleDefinition;
use Kernel\Module\ModuleManifest;

$states = new class implements ModuleStateRepositoryInterface {
    /** @var array<string, array<string, bool>> */
    public array $values = [];

    public function enabledOverride(string $organizationId, string $moduleId): ?bool
    {
        return $this->values[$organizationId][$moduleId] ?? null;
    }

    public function setEnabled(string $organizationId, string $moduleId, bool $enabled): void
    {
        $this->values[$organizationId][$moduleId] = $enabled;
    }
};

$catalog = new ModuleCatalog([
    new ModuleDefinition(
        new ModuleManifest('foundation', 'Foundation', '1.0.0'),
        new ModuleContributions(capabilities: ['foundation.read']),
    ),
    new ModuleDefinition(
        new ModuleManifest('sales', 'Sales', '1.0.0', dependencies: ['foundation']),
        new ModuleContributions(capabilities: ['sales.pipeline', 'sales.admin']),
    ),
]);

$resolver = new ActiveModuleResolver($catalog, $states);
$context = new EffectiveModuleContext($resolver, new ModuleCapabilityRegistry($catalog));

$snapshot = $context->describe('org-a');
if ($snapshot['active_module_ids'] !== ['foundation', 'sales']) {
    throw new RuntimeException('Default effective module ids are incorrect.');
}
if ($snapshot['active_capabilities'] !== ['foundation.read', 'sales.pipeline', 'sales.admin']) {
    throw new RuntimeException('Default effective capabilities are incorrect.');
}
if (($snapshot['modules'][1]['active_capabilities'] ?? null) !== ['sales.pipeline', 'sales.admin']) {
    throw new RuntimeException('Active Sales module must expose active capabilities.');
}

$states->setEnabled('org-a', 'foundation', false);
$snapshot = $context->describe('org-a');
if ($snapshot['active_module_ids'] !== [] || $snapshot['active_capabilities'] !== []) {
    throw new RuntimeException('Disabled dependency must deactivate dependent modules and capabilities.');
}
if (($snapshot['modules'][1]['capabilities'] ?? null) !== ['sales.pipeline', 'sales.admin']) {
    throw new RuntimeException('Declared capabilities must remain discoverable when a module is inactive.');
}
if (($snapshot['modules'][1]['active_capabilities'] ?? null) !== []) {
    throw new RuntimeException('Inactive Sales module must not expose active capabilities.');
}

$states->setEnabled('org-a', 'foundation', true);
$states->setEnabled('org-a', 'sales', false);
$snapshot = $context->describe('org-a');
if ($snapshot['active_module_ids'] !== ['foundation']) {
    throw new RuntimeException('Explicit Sales disable must preserve only the active dependency.');
}
if ($snapshot['active_capabilities'] !== ['foundation.read']) {
    throw new RuntimeException('Explicit Sales disable leaked Sales capabilities.');
}

$other = $context->describe('org-b');
if ($other['active_module_ids'] !== ['foundation', 'sales']) {
    throw new RuntimeException('Effective module context leaked tenant state between organizations.');
}

echo "Effective module context invariants passed.\n";

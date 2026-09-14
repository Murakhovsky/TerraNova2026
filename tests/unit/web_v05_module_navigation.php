<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Interfaces\Web\Navigation\DiagnosticNavigationContributor;
use Interfaces\Web\Navigation\ModuleAwareNavigationService;
use Interfaces\Web\Navigation\PropertyNavigationContributor;
use Interfaces\Web\Navigation\SalesNavigationContributor;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\BulkModuleStateRepositoryInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleManifest;
use Kernel\Tenant\OrganizationContextInterface;

$states = new class implements BulkModuleStateRepositoryInterface {
    /** @var array<string, array<string, bool>> */
    private array $values = [];
    public int $bulkReads = 0;

    public function enabledOverride(string $organizationId, string $moduleId): ?bool
    {
        return $this->values[$organizationId][$moduleId] ?? null;
    }

    public function enabledOverrides(string $organizationId): array
    {
        $this->bulkReads++;
        return $this->values[$organizationId] ?? [];
    }

    public function setEnabled(string $organizationId, string $moduleId, bool $enabled): void
    {
        $this->values[$organizationId][$moduleId] = $enabled;
    }
};

$organization = new class implements OrganizationContextInterface {
    public string $organizationId = 'org-a';

    public function id(): string
    {
        return $this->organizationId;
    }

    public function actorId(): string
    {
        return 'test-user';
    }

    public function isAuthenticated(): bool
    {
        return true;
    }
};

$catalog = new ModuleCatalog([
    new ModuleManifest('sales', 'Sales', '0.8.6'),
    new ModuleManifest('property', 'Property', '0.1.1'),
    new ModuleManifest('diagnostic', 'Diagnostics', '0.5.4'),
]);
$resolver = new ActiveModuleResolver($catalog, $states);
$navigation = new ModuleAwareNavigationService(
    $organization,
    $resolver,
    [
        ['module_id' => 'sales', 'service' => new SalesNavigationContributor()],
        ['module_id' => 'property', 'service' => new PropertyNavigationContributor()],
        ['module_id' => 'diagnostic', 'service' => new DiagnosticNavigationContributor()],
    ],
);

$keys = static fn (array $items): array => array_map(static fn (array $item): string => (string) ($item['key'] ?? ''), $items);
$section = static function (array $items, string $key): ?array {
    foreach ($items as $item) {
        if (($item['key'] ?? '') === $key) return $item;
    }
    return null;
};
$assertNoOrderMetadata = null;
$assertNoOrderMetadata = static function (array $items) use (&$assertNoOrderMetadata): void {
    foreach ($items as $item) {
        if (array_key_exists('order', $item)) {
            throw new RuntimeException('Internal navigation ordering metadata leaked into the view contract.');
        }
        if (isset($item['children'])) {
            $assertNoOrderMetadata((array) $item['children']);
        }
    }
};

$beforeManagerReads = $states->bulkReads;
$manager = $navigation->workspace('manager');
if (($states->bulkReads - $beforeManagerReads) !== 1) {
    throw new RuntimeException('Workspace navigation must resolve exactly one effective module snapshot per render.');
}
$expected = ['home', 'sales', 'clients', 'properties', 'cos', 'analytics', 'administration'];
if ($keys($manager['primary'] ?? []) !== $expected) {
    throw new RuntimeException('Enabled modules did not compose the canonical manager workspace.');
}
$sales = $section($manager['primary'] ?? [], 'sales');
if ($keys($sales['children'] ?? []) !== ['sales', 'today', 'pipeline', 'leads', 'deals', 'director']) {
    throw new RuntimeException('Manager Sales navigation contribution is invalid.');
}
$cos = $section($manager['primary'] ?? [], 'cos');
if ($keys($cos['children'] ?? []) !== ['cos', 'actions', 'approvals', 'agents', 'rules', 'events', 'audit', 'diagnostics']) {
    throw new RuntimeException('Diagnostics module did not extend the COS navigation section in canonical order.');
}
$assertNoOrderMetadata($manager['primary'] ?? []);

$admin = $navigation->workspace('admin');
$adminSales = $section($admin['primary'] ?? [], 'sales');
if (!in_array('sales-admin', $keys($adminSales['children'] ?? []), true)) {
    throw new RuntimeException('Admin Sales navigation did not expose Sales Admin.');
}
$adminAdministration = $section($admin['primary'] ?? [], 'administration');
if ($keys($adminAdministration['children'] ?? []) !== ['users', 'content']) {
    throw new RuntimeException('Admin core navigation lost Administration permissions.');
}

$beforePortalReads = $states->bulkReads;
$portal = $navigation->portal('realtor');
if (($states->bulkReads - $beforePortalReads) !== 1) {
    throw new RuntimeException('Portal navigation must resolve exactly one effective module snapshot per render.');
}
if ($keys($portal['primary'] ?? []) !== ['cabinet', 'catalog', 'favour', 'listing', 'submit']) {
    throw new RuntimeException('Property module did not contribute the canonical portal navigation.');
}
$assertNoOrderMetadata($portal['primary'] ?? []);

$states->setEnabled('org-a', 'sales', false);
$withoutSales = $navigation->workspace('manager');
$withoutSalesKeys = $keys($withoutSales['primary'] ?? []);
if (in_array('sales', $withoutSalesKeys, true) || in_array('clients', $withoutSalesKeys, true)) {
    throw new RuntimeException('Disabled Sales module still appears in workspace navigation.');
}

$states->setEnabled('org-a', 'property', false);
$withoutProperty = $navigation->workspace('manager');
if (in_array('properties', $keys($withoutProperty['primary'] ?? []), true)) {
    throw new RuntimeException('Disabled Property module still appears in workspace navigation.');
}
if ($keys($navigation->portal('realtor')['primary'] ?? []) !== ['cabinet']) {
    throw new RuntimeException('Disabled Property module still appears in portal navigation.');
}

$states->setEnabled('org-a', 'diagnostic', false);
$withoutDiagnostic = $navigation->workspace('manager');
$cosWithoutDiagnostic = $section($withoutDiagnostic['primary'] ?? [], 'cos');
if (in_array('diagnostics', $keys($cosWithoutDiagnostic['children'] ?? []), true)) {
    throw new RuntimeException('Disabled Diagnostics module still appears in COS navigation.');
}

$organization->organizationId = 'org-b';
$otherTenant = $navigation->workspace('manager');
if ($keys($otherTenant['primary'] ?? []) !== $expected) {
    throw new RuntimeException('Module-aware navigation cached another organization state.');
}
$otherTenantCos = $section($otherTenant['primary'] ?? [], 'cos');
if (!in_array('diagnostics', $keys($otherTenantCos['children'] ?? []), true)) {
    throw new RuntimeException('Organization switch did not restore the second tenant effective module state.');
}

$organization->organizationId = 'org-a';
$disabledTenantAgain = $navigation->workspace('manager');
if ($keys($disabledTenantAgain['primary'] ?? []) !== ['home', 'cos', 'analytics', 'administration']) {
    throw new RuntimeException('Navigation did not re-resolve the original tenant after switching organizations.');
}

$assertNoOrderMetadata($otherTenant['primary'] ?? []);
$assertNoOrderMetadata($disabledTenantAgain['primary'] ?? []);

echo "WEB V0.5 module-aware navigation runtime contract passed.\n";

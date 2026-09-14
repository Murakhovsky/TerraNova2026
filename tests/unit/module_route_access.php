<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Interfaces\Web\Routing\ModuleRouteAccessGuard;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleManifest;
use Kernel\Tenant\OrganizationContextInterface;

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

$organization = new class implements OrganizationContextInterface {
    public string $organizationId = 'org-a';

    public function id(): string { return $this->organizationId; }
    public function actorId(): string { return '42'; }
    public function isAuthenticated(): bool { return true; }
};

$resolver = new ActiveModuleResolver(
    new ModuleCatalog([new ModuleManifest('sales', 'Sales', '0.7.1')]),
    $states,
);
$guard = new ModuleRouteAccessGuard($organization, $resolver);

if (!$guard->allows('sales')) {
    throw new RuntimeException('Enabled-by-default Sales module must be routable.');
}

$states->setEnabled('org-a', 'sales', false);
if ($guard->allows('sales')) {
    throw new RuntimeException('Disabled Sales module remains routable for org-a.');
}

$organization->organizationId = 'org-b';
if (!$guard->allows('sales')) {
    throw new RuntimeException('Route guard cached org-a instead of resolving the current organization per request.');
}

$states->setEnabled('org-b', 'sales', false);
if ($guard->allows('sales')) {
    throw new RuntimeException('Disabled Sales module remains routable for org-b.');
}

$organization->organizationId = 'org-a';
$states->setEnabled('org-a', 'sales', true);
if (!$guard->allows('sales')) {
    throw new RuntimeException('Re-enabled Sales module did not become routable for org-a.');
}

if ($guard->allows('unknown')) {
    throw new RuntimeException('Unknown module must never be routable.');
}

echo "Per-request module route access invariants passed.\n";

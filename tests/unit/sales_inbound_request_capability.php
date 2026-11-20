<?php
declare(strict_types=1);

use App\Application\Capability\CanonicalCapabilityGateway;
use App\Infrastructure\Capability\SalesInboundRequestReadAdapter;
use App\Persistence\Federation\FederationCapabilityBindingResolver;
use Domains\Sales\Application\Contract\SalesProposalLeadReadModelInterface;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDefinition;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
spl_autoload_register(static function(string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) return;
    $candidate = $root . '/symfony/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($candidate)) require $candidate;
});
$manifest = require $root . '/app/Domains/Sales/module.php';
$catalog = new CanonicalCapabilityCatalog(new ModuleCatalog([ModuleDefinition::fromArray($manifest)]));
$state = new class implements ModuleStateRepositoryInterface {
    public bool $enabled = true;
    public function enabledOverride(string $organizationId, string $moduleId): ?bool { return $this->enabled; }
    public function setEnabled(string $organizationId, string $moduleId, bool $enabled): void { $this->enabled = $enabled; }
};
$modules = new ActiveModuleResolver(new ModuleCatalog([ModuleDefinition::fromArray($manifest)]), $state);
$bindings = new FederationCapabilityBindingResolver($catalog, new DomainModuleRegistry([]), $modules);
$read = new class implements SalesProposalLeadReadModelInterface {
    public array $calls = [];
    public function lead(string $organizationId, int $leadId): ?array {
        $this->calls[] = [$organizationId, $leadId];
        if ($organizationId === 'tenant-a' && $leadId === 7) {
            return ['id' => 7, 'organization_id' => 'tenant-a', 'full_name' => 'Test'];
        }
        return null;
    }
};
$adapter = new SalesInboundRequestReadAdapter($read);
$gateway = new CanonicalCapabilityGateway($catalog, $modules, $bindings, [$adapter]);
$manager = new TenantContext(
    UserId::fromString('15'), OrganizationId::fromString('tenant-a'),
    OrganizationRole::fromString('manager'),
    [Permission::fromString(TenantPermissions::ACCESS), Permission::fromString(TenantPermissions::MANAGE)]
);
$viewer = new TenantContext(
    UserId::fromString('16'), OrganizationId::fromString('tenant-a'),
    OrganizationRole::fromString('viewer'),
    [Permission::fromString(TenantPermissions::ACCESS), Permission::fromString(TenantPermissions::MANAGE)]
);
$check = static function(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$reject = static function(callable $fn) use ($check): void {
    try { $fn(); } catch (DomainException) { return; }
    $check(false, 'Expected Sales capability invocation to be denied.');
};
$check($gateway->describe($manager, 'sales.inbound.request.read')['status'] === 'read_only', 'Not a canonical read-only capability.');
$hit = $gateway->invoke($manager, 'sales.inbound.request.read', ['lead_id' => 7], 'trusted_read', '', 'sales-trace');
$check($hit['found'] === true && $hit['request']['id'] === 7, 'Sales native read model not used.');
$miss = $gateway->invoke($manager, 'sales.inbound.request.read', ['lead_id' => 9], 'human_api', '', 'sales-trace');
$check($miss === ['found' => false, 'request' => null], 'Missing request must not invent a record.');
$reject(static fn() => $gateway->invoke($manager, 'sales.inbound.request.read', ['lead_id' => 7, 'tenant_id' => 'tenant-b'], 'trusted_read', '', 'sales-trace'));
$reject(static fn() => $gateway->invoke($manager, 'sales.inbound.request.read', ['lead_id' => 0], 'trusted_read', '', 'sales-trace'));
$reject(static fn() => $gateway->invoke($manager, 'sales.inbound.request.read', ['lead_id' => '7'], 'trusted_read', '', 'sales-trace'));
$reject(static fn() => $gateway->invoke($viewer, 'sales.inbound.request.read', ['lead_id' => 7], 'trusted_read', '', 'sales-trace'));
$state->enabled = false;
$reject(static fn() => $gateway->invoke($manager, 'sales.inbound.request.read', ['lead_id' => 7], 'trusted_read', '', 'sales-trace'));
$check(count($read->calls) === 2, 'Denied operations reached Sales read model.');
echo "Sales inbound request canonical capability PASS.\n";

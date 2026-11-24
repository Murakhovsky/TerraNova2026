<?php
declare(strict_types=1);

use App\Application\Capability\CanonicalCapabilityGateway;
use App\Application\Diagnostic\Command\StartDiagnosticCommand;
use App\Infrastructure\Capability\DiagnosticSessionStartAdapter;
use App\Persistence\Federation\FederationCapabilityBindingResolver;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Application\Command\CommandInterface;
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
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) return;
    $filename = $root . '/symfony/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($filename)) require $filename;
});
$manifest = require $root . '/app/Domains/Diagnostic/module.php';
$definition = ModuleDefinition::fromArray($manifest);
$modulesCatalog = new ModuleCatalog([$definition]);
$catalog = new CanonicalCapabilityCatalog($modulesCatalog);
$state = new class implements ModuleStateRepositoryInterface {
    public bool $enabled = true;
    public function enabledOverride(string $organizationId, string $moduleId): ?bool { return $this->enabled; }
    public function setEnabled(string $organizationId, string $moduleId, bool $enabled): void { $this->enabled = $enabled; }
};
$modules = new ActiveModuleResolver($modulesCatalog, $state);
$bindings = new FederationCapabilityBindingResolver($catalog, new DomainModuleRegistry([]), $modules);
$bus = new class implements CommandBusInterface {
    /** @var list<StartDiagnosticCommand> */
    public array $calls = [];
    public bool $forgeReceipt = false;
    public function dispatch(CommandInterface $command): mixed {
        if (!$command instanceof StartDiagnosticCommand) throw new RuntimeException('Unexpected command type.');
        $this->calls[] = $command;
        return [
            'session_id' => $this->forgeReceipt ? 'forged-session' : substr(hash(
                'sha256',
                'diagnostic:start:' . $command->organizationId->value() . ':' . $command->idempotencyKey,
            ), 0, 32),
            'status' => 'active',
            'pack_id' => $command->input['pack_id'],
            'pack_version' => 1,
            'replayed' => false,
            'known_facts' => ['respondent.private_phone'],
            'next_question' => ['text' => 'Private interview question'],
        ];
    }
};
$gateway = new CanonicalCapabilityGateway($catalog, $modules, $bindings, [new DiagnosticSessionStartAdapter($bus)]);
$manager = new TenantContext(
    UserId::fromString('21'), OrganizationId::fromString('tenant-a'),
    OrganizationRole::fromString('manager'),
    [Permission::fromString(TenantPermissions::ACCESS), Permission::fromString(TenantPermissions::MANAGE)],
);
$viewer = new TenantContext(
    UserId::fromString('22'), OrganizationId::fromString('tenant-a'),
    OrganizationRole::fromString('viewer'),
    [Permission::fromString(TenantPermissions::ACCESS), Permission::fromString(TenantPermissions::MANAGE)],
);
$check = static function (bool $value, string $why): void {
    if (!$value) throw new RuntimeException($why);
};
$reject = static function (callable $fn): void {
    try { $fn(); } catch (DomainException) { return; }
    throw new RuntimeException('Expected Diagnostic capability to reject unsafe execution.');
};
$input = ['pack_id' => 'sales-pack', 'subject_id' => 'company-42', 'domain' => 'Sales', 'mode' => 'QUICK_SCAN'];
$check($gateway->describe($manager, 'diagnostic.session.start')['status'] === 'direct_human', 'Unbound Diagnostic command.');
$receipt = $gateway->invoke($manager, 'diagnostic.session.start', $input, 'human_api', 'start-key-01', 'trace-01');
$expected = substr(hash('sha256', 'diagnostic:start:tenant-a:start-key-01'), 0, 32);
$check($receipt === [
    'session_id' => $expected, 'status' => 'active', 'pack_id' => 'sales-pack',
    'pack_version' => 1, 'replayed' => false,
], 'Diagnostic command did not return a minimal verified receipt.');
$check(count($bus->calls) === 1 && $bus->calls[0]->organizationId->value() === 'tenant-a'
    && $bus->calls[0]->actorId === 21
    && $bus->calls[0]->idempotencyKey === 'start-key-01'
    && $bus->calls[0]->correlationId === 'trace-01', 'Diagnostic bus identity/tracing was not server-owned.');

$reject(static fn() => $gateway->invoke($manager, 'diagnostic.session.start', $input, 'ai', 'key-02', 'trace-01'));
$reject(static fn() => $gateway->invoke($manager, 'diagnostic.session.start', $input, 'cron', 'key-02', 'trace-01'));
$reject(static fn() => $gateway->invoke($manager, 'diagnostic.session.start', $input, 'trusted_read', 'key-02', 'trace-01'));
$reject(static fn() => $gateway->invoke($manager, 'diagnostic.session.start', $input, 'human_api', '', 'trace-01'));
$reject(static fn() => $gateway->invoke($manager, 'diagnostic.session.start', [...$input, 'tenant_id' => 'tenant-b'], 'human_api', 'key-02', 'trace-01'));
$reject(static fn() => $gateway->invoke($manager, 'diagnostic.session.start', [...$input, 'session_id' => 'forged'], 'human_api', 'key-02', 'trace-01'));
$reject(static fn() => $gateway->invoke($manager, 'diagnostic.session.start', [...$input, 'methodology_version' => 'fake-pinned-methodology'], 'human_api', 'key-02', 'trace-01'));
$reject(static fn() => $gateway->invoke($manager, 'diagnostic.session.start', [...$input, 'parent_session_id' => 'foreign-tenant-session'], 'human_api', 'key-02', 'trace-01'));
$reject(static fn() => $gateway->invoke($manager, 'diagnostic.session.start', [...$input, 'subject_id' => ''], 'human_api', 'key-02', 'trace-01'));
$reject(static fn() => $gateway->invoke($viewer, 'diagnostic.session.start', $input, 'human_api', 'key-02', 'trace-01'));
$check(count($bus->calls) === 1, 'Unauthorized operation reached Diagnostic command bus.');
$bus->forgeReceipt = true;
$reject(static fn() => $gateway->invoke($manager, 'diagnostic.session.start', $input, 'human_ui', 'key-03', 'trace-01'));
$check(count($bus->calls) === 2, 'Receipt verification fixture was not exercised.');
$state->enabled = false;
$reject(static fn() => $gateway->invoke($manager, 'diagnostic.session.start', $input, 'human_api', 'key-04', 'trace-01'));
$check(count($bus->calls) === 2, 'Disabled Diagnostic module reached the CommandBus.');
echo "Diagnostic session.start governed canonical capability PASS.\n";

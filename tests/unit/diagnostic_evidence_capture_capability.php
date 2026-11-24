<?php
declare(strict_types=1);

use App\Application\Capability\CanonicalCapabilityGateway;
use App\Application\Diagnostic\Command\CaptureDiagnosticEvidenceCommand;
use App\Infrastructure\Capability\DiagnosticEvidenceCaptureAdapter;
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
spl_autoload_register(static function (string $name) use ($root): void {
    if (!str_starts_with($name, 'App\\')) return;
    $file = $root . '/symfony/src/' . str_replace('\\', '/', substr($name, 4)) . '.php';
    if (is_file($file)) require $file;
});
$module = require $root . '/app/Domains/Diagnostic/module.php';
$moduleCatalog = new ModuleCatalog([ModuleDefinition::fromArray($module)]);
$catalog = new CanonicalCapabilityCatalog($moduleCatalog);
$state = new class implements ModuleStateRepositoryInterface {
    public bool $enabled = true;
    public function enabledOverride(string $org, string $module): ?bool { return $this->enabled; }
    public function setEnabled(string $org, string $module, bool $enabled): void { $this->enabled = $enabled; }
};
$active = new ActiveModuleResolver($moduleCatalog, $state);
$resolver = new FederationCapabilityBindingResolver($catalog, new DomainModuleRegistry([]), $active);
$bus = new class implements CommandBusInterface {
    public array $calls = [];
    public bool $replay = false;
    public bool $forgeId = false;
    public bool $invalidCount = false;
    public function dispatch(CommandInterface $command): mixed {
        if (!$command instanceof CaptureDiagnosticEvidenceCommand) throw new RuntimeException('Wrong command dispatched.');
        $this->calls[] = $command;
        return [
            'evidence_id' => $this->forgeId ? 'wrong' : hash('sha256',
                $command->organizationId->value().':'.$command->sessionId.':'.$command->idempotencyKey),
            'replayed' => $this->replay,
            'facts_ingested' => $this->replay ? 0 : ($this->invalidCount ? 99 : count($command->input['facts'] ?? [])),
            'metrics_ingested' => $this->replay ? 0 : count($command->input['metrics'] ?? []),
            'revision' => $this->replay ? null : 3,
            'interview_text' => 'not part of public receipt',
        ];
    }
};
$gateway = new CanonicalCapabilityGateway($catalog, $active, $resolver, [new DiagnosticEvidenceCaptureAdapter($bus)]);
$manager = new TenantContext(UserId::fromString('31'), OrganizationId::fromString('tenant-a'),
    OrganizationRole::fromString('manager'),
    [Permission::fromString(TenantPermissions::ACCESS), Permission::fromString(TenantPermissions::MANAGE)]);
$viewer = new TenantContext(UserId::fromString('32'), OrganizationId::fromString('tenant-a'),
    OrganizationRole::fromString('viewer'),
    [Permission::fromString(TenantPermissions::ACCESS), Permission::fromString(TenantPermissions::MANAGE)]);
$check = static function (bool $ok, string $reason): void { if (!$ok) throw new RuntimeException($reason); };
$reject = static function (callable $call): void {
    try { $call(); } catch (DomainException) { return; }
    throw new RuntimeException('Unsafe Diagnostic request was not rejected.');
};
$id = str_repeat('a', 32);
$input = ['session_id'=>$id,'type'=>'interview','title'=>'Operator evidence','source'=>'manual',
    'facts'=>[['key'=>'headcount','value'=>12]],'metrics'=>[['key'=>'ratio','value'=>0.7]]];
$check($gateway->describe($manager,'diagnostic.evidence.capture')['status']==='direct_human',
    'Capability is not bound to human-only CommandBus.');
$receipt = $gateway->invoke($manager,'diagnostic.evidence.capture',$input,'human_api','key-1','trace-1');
$expected = hash('sha256','tenant-a:'.$id.':key-1');
$check($receipt === ['evidence_id'=>$expected,'replayed'=>false,
    'facts_ingested'=>1,'metrics_ingested'=>1,'revision'=>3], 'Invalid or unredacted receipt.');
$check(count($bus->calls)===1 && $bus->calls[0]->organizationId->value()==='tenant-a'
    && $bus->calls[0]->actorId===31 && $bus->calls[0]->sessionId===$id
    && $bus->calls[0]->idempotencyKey==='key-1'
    && $bus->calls[0]->correlationId==='trace-1'
    && !array_key_exists('session_id',$bus->calls[0]->input), 'Actor/session were not server-fenced.');
$reject(static fn() => $gateway->invoke($manager,'diagnostic.evidence.capture',$input,'ai','key-2','trace'));
$reject(static fn() => $gateway->invoke($manager,'diagnostic.evidence.capture',$input,'cron','key-2','trace'));
$reject(static fn() => $gateway->invoke($manager,'diagnostic.evidence.capture',$input,'trusted_read','key-2','trace'));
$reject(static fn() => $gateway->invoke($manager,'diagnostic.evidence.capture',$input,'human_api','','trace'));
$reject(static fn() => $gateway->invoke($manager,'diagnostic.evidence.capture',[...$input,'tenant_id'=>'foreign'],'human_api','key-2','trace'));
$reject(static fn() => $gateway->invoke($manager,'diagnostic.evidence.capture',[...$input,'actor_id'=>10],'human_api','key-2','trace'));
$reject(static fn() => $gateway->invoke($manager,'diagnostic.evidence.capture',[...$input,'session_id'=>'bad'],'human_api','key-2','trace'));
$reject(static fn() => $gateway->invoke($manager,'diagnostic.evidence.capture',[...$input,'metadata'=>['idempotency_request_hash'=>'invalid']],'human_api','key-2','trace'));
$reject(static fn() => $gateway->invoke($manager,'diagnostic.evidence.capture',[...$input,'metrics'=>[['key'=>'x','value'=>'not-number']]],'human_api','key-2','trace'));
$reject(static fn() => $gateway->invoke($manager,'diagnostic.evidence.capture',[...$input,'facts'=>[['key'=>'x','value'=>['unexpected'=>'object']]]],'human_api','key-2','trace'));
$reject(static fn() => $gateway->invoke($viewer,'diagnostic.evidence.capture',$input,'human_api','key-2','trace'));
$check(count($bus->calls)===1,'Denied input reached CommandBus.');
$bus->replay=true;
$replay=$gateway->invoke($manager,'diagnostic.evidence.capture',$input,'human_ui','key-1','trace-2');
$check($replay === ['evidence_id'=>$expected,'replayed'=>true,'facts_ingested'=>0,'metrics_ingested'=>0],
    'Idempotent replay receipt is invalid.');
$bus->replay=false;$bus->forgeId=true;
$reject(static fn() => $gateway->invoke($manager,'diagnostic.evidence.capture',$input,'human_api','key-3','trace-3'));
$bus->forgeId=false;$bus->invalidCount=true;
$reject(static fn() => $gateway->invoke($manager,'diagnostic.evidence.capture',$input,'human_api','key-4','trace-4'));
$state->enabled=false;
$reject(static fn() => $gateway->invoke($manager,'diagnostic.evidence.capture',$input,'human_api','key-5','trace-5'));
$check(count($bus->calls)===4,'Disabled Domain reached bus.');
echo "Diagnostic evidence.capture canonical Gateway PASS.\n";

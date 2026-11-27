<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $root.'/symfony/src/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (is_file($file)) require $file;
    }
});

use App\Web\Experience\AI\AgentRuntimeAdapterInterface;
use App\Web\Experience\AI\AgentWorkspaceAccess;
use App\Web\Experience\AI\AgentWorkspaceRuntime;
use App\Web\Experience\AI\AgentWorkspaceScope;
use App\Web\Experience\AI\StructuredAgentResultFactory;
use App\Web\Experience\AI\UIContext;
use App\Web\Experience\Extension\Model\WorkspaceDefinition;
use App\Web\Experience\Model\EntityRef;
use Domains\Marketing\Infrastructure\Hermes\HermesAnalystProfileProbe;
use Kernel\Agent\AgentRunProjection;
use Kernel\Agent\Model\AgentRunStatus;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

$expect = static function (bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException($why);
};
$blocked = static function (callable $fn, string $why): void {
    try { $fn(); } catch (LogicException) { return; }
    throw new RuntimeException($why);
};
$actor = static fn(string $org, string $role, array $permissions): TenantContext => new TenantContext(
    UserId::fromString('1'), OrganizationId::fromString($org), OrganizationRole::fromString($role),
    array_map(Permission::fromString(...), $permissions),
);
$manager = $actor('org-a', 'manager', [TenantPermissions::ACCESS, TenantPermissions::MANAGE]);
$foreign = $actor('org-b', 'manager', [TenantPermissions::ACCESS, TenantPermissions::MANAGE]);
$member = $actor('org-a', 'member', [TenantPermissions::ACCESS]);
$missingPermission = $actor('org-a', 'manager', [TenantPermissions::ACCESS]);
$expect(AgentWorkspaceAccess::allows($manager), 'Manager access lost');
$expect(!AgentWorkspaceAccess::allows($member), 'Member gained tenant-wide run access');
$expect(!AgentWorkspaceAccess::allows($missingPermission), 'Role alone grants history access');

$campaign = new EntityRef('marketing_campaign', 'mkt-'.str_repeat('a',24));
$deal = new EntityRef('sales.deal', '42');
$expect(AgentWorkspaceScope::permitsEntity(new WorkspaceDefinition('marketing.campaigns','Campaigns','/marketing/campaigns'), $campaign),
    'Marketing binding rejected');
$expect(!AgentWorkspaceScope::permitsEntity(new WorkspaceDefinition('marketing.campaigns','Campaigns','/marketing/campaigns'), $deal),
    'Unrelated subject accepted in Marketing');
$expect(!AgentWorkspaceScope::permitsEntity(new WorkspaceDefinition('pm.catalog','Products','/product-management'), $deal),
    'Unbound collection allowed arbitrary subject');
$expect(AgentWorkspaceScope::permitsEntity(new WorkspaceDefinition('sales.deal','Deal','/sales/deals','sales.deal'), $deal),
    'Canonical Sales subject rejected');

$run = static fn(string $org,string $type,string $id): AgentRunProjection => new AgentRunProjection(
    id:str_repeat('a',32), organizationId:$org, agentName:'fixture', agentVersion:'1',
    subjectType:$type, subjectId:$id, status:AgentRunStatus::COMPLETED,
    output:['summary'=>'<script>never trusted HTML</script>', 'recommendations'=>['Review data'],
        'proposed_actions'=>[['type'=>'sales.update','target_type'=>'sales.deal','target_id'=>'42','parameters'=>['amount'=>10]]],
        'warnings'=>['Not verified'], 'evidence'=>[['source'=>'fixture']]],
    contextReference:[], confidence:0.8, provider:'fixture', model:'fixture', inputTokens:null,
    outputTokens:null, costAmount:null, costCurrency:null, durationMs:null, error:null,
    correlationId:'trace-fixture', startedAt:null, finishedAt:null, createdAt:new DateTimeImmutable(),
);
$native = $run('org-a','sales.deal','42');
$expect(AgentWorkspaceScope::permitsRun($manager,$deal,$native), 'Scoped run unavailable');
$expect(!AgentWorkspaceScope::permitsRun($foreign,$deal,$native), 'Foreign run disclosed');
$expect(!AgentWorkspaceScope::permitsRun($member,$deal,$native), 'Unprivileged run disclosed');
$expect(!AgentWorkspaceScope::permitsRun($manager,$deal,$run('org-a','sales.deal','43')), 'Wrong entity disclosed');
$expect(!AgentWorkspaceScope::permitsRun($manager,$deal,$run('org-a','property.asset','42')), 'Wrong subject type disclosed');
$expect(AgentWorkspaceScope::permitsRun($manager,$deal,$run('org-a','deal','42')), 'Legacy subject alias lost');
$result = (new StructuredAgentResultFactory())->fromRun($native);
$expect($result->summary['summary']==='<script>never trusted HTML</script>', 'Summary text lost');
$expect(count($result->recommendations)===2 && $result->recommendations[0]->kind==='recommendation'
    && $result->recommendations[1]->kind==='proposal', 'Advice and untrusted proposal collapsed');

$context = new UIContext('marketing.campaigns',$campaign,[],[],[],[]);
$unsupported = new UIContext('sales.deal',$deal,[],[],[],[]);
$adapter = new class implements AgentRuntimeAdapterInterface {
    public int $reads = 0;
    public function supports(UIContext $context): bool {return $context->workspace==='marketing.campaigns';}
    public function inspect(TenantContext $actor, UIContext $context): array {
        ++$this->reads;
        return ['runtime'=>['status'=>'SIMULATED','plan_available'=>false], 'campaign'=>null];
    }
};
$runtime = new AgentWorkspaceRuntime([$adapter]);
$expect($runtime->inspect($manager,$unsupported)===null && $adapter->reads===0,'Unsupported runtime was invoked');
$blocked(static fn()=>$runtime->inspect($member,$context),'Member admitted to adapter');
$blocked(static fn()=>(new AgentWorkspaceRuntime([$adapter,$adapter]))->inspect($manager,$context),'Ambiguous adapter was selected');
$expect($adapter->reads===0,'Denied or ambiguous request invoked runtime');
$expect($runtime->inspect($manager,$context)['runtime']['plan_available']===false && $adapter->reads===1,
    'Unavailable simulation became executable');
$expect((new AgentWorkspaceRuntime([]))->inspect($manager,$context)===null,'Absent adapter does not degrade safely');

// Exact zero-tool contract of the actual existing Hermes boundary.
HermesAnalystProfileProbe::assertNoTools([]);
HermesAnalystProfileProbe::assertNoTools([['enabled'=>true,'tools'=>[]],['enabled'=>false,'tools'=>['research']]]);
$blocked(static fn()=>HermesAnalystProfileProbe::assertNoTools([['enabled'=>true,'tools'=>['research']]]),
    'One enabled tool exceeded zero-tool allowance');
$blocked(static fn()=>HermesAnalystProfileProbe::assertNoTools([['enabled'=>'false','tools'=>[]]]),
    'Malformed tool metadata was accepted');

echo "UX06 scoped runtime dispatch, negative access, structured advice and zero-tool boundary PASS.\n";

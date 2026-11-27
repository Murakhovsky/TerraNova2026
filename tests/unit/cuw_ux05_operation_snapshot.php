<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
spl_autoload_register(static function(string $name) use ($root): void {
    if (str_starts_with($name, 'App\\')) {
        $p = $root.'/symfony/src/'.str_replace('\\', '/', substr($name, 4)).'.php';
        if (is_file($p)) require $p;
    }
});
use App\Web\Operations\Read\OperationListReadInterface;
use App\Web\Operations\OperationsSnapshot;
use App\Web\Operations\OperationsHistoryCursor;
use App\Web\Operations\OperationStatus as S;
use App\Web\Experience\Model\EntityRef;
use Kernel\Agent\Contract\AgentRunReadModelInterface;
use Kernel\Agent\AgentRunProjection;
use Kernel\Queue\Contract\AsyncOperationReadModelInterface;
use Kernel\Queue\AsyncOperationProjection;
use Kernel\Queue\AsyncOperationStatus;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Workflow\Contract\WorkflowExecutionStoreInterface;
use Platform\Workflow\Model\{WorkflowRunDraft,WorkflowRunIdentity,WorkflowRunSnapshot,WorkflowRunTransition,WorkflowStepClaim,WorkflowStepCheckpoint,WorkflowStepSnapshot};

$read = new class implements OperationListReadInterface {
    public function rows(string $org, string $source, int $limit, ?string $id = null, ?array $before = null, string $correlation = '', ?EntityRef $entity = null, ?S $status = null): array {
        if ($source === 'import') throw new RuntimeException('Provider unavailable');
        if ($source === 'async' && $org === 'tenant-a') {
            // The typed model still fences tenant ownership independently.
            return [['id'=>'job-1'],['id'=>'foreign']];
        }
        if ($source === 'action' && $org === 'tenant-a') {
            $rows = [];
            for ($i=52; $i>=1; --$i) {
                $key = sprintf('a%02d',$i);
                if ($before !== null && $key >= $before['id']) continue;
                if ($id !== null && $key !== $id) continue;
                $rows[] = ['id'=>$key,'title'=>'Test action','native_status'=>'FAILED',
                    'created_at'=>'2026-11-27 10:00:00','updated_at'=>'2026-11-27 10:01:00'];
            }
            return array_slice($rows,0,$limit);
        }
        if ($org !== 'tenant-a' || $source !== 'workflow' || ($id !== null && $id !== 'run-1')) return [];
        return [['id'=>'run-1','title'=>'federation.approved-plan','native_status'=>'completed',
            'total_steps'=>2,'completed_steps'=>2,'correlation_id'=>'corr-1','created_at'=>'2026-11-27 09:00:00','updated_at'=>'2026-11-27 10:00:00']];
    }
    public function workflowGraph(string $org, string $run): array { return ['one'=>[], 'two'=>['one']]; }
    public function actionAttempts(string $org, string $action): array {
        return [['id'=>123,'attempt'=>2,'status'=>'FAILED','started_at'=>'2026-11-27 09:00:00','finished_at'=>'2026-11-27 10:00:00','has_error'=>1]];
    }
    public function workflowProof(string $org, string $run): array {
        return ['status'=>'drift','verified_steps'=>1,'total_steps'=>2];
    }
    public function importItems(string $org, string $batch): array { return []; }
};
$async = new class implements AsyncOperationReadModelInterface {
    public function recentForOrganization(string $org, int $limit = 50): array {
        return [
            new AsyncOperationProjection('job-1','tenant-a','IMPORT',AsyncOperationStatus::Completed,1,3,
                'corr-1',new DateTimeImmutable(),new DateTimeImmutable(),entityType:'product',entityId:'p1'),
            new AsyncOperationProjection('foreign','tenant-b','SECRET',AsyncOperationStatus::Completed,1,3,
                'foreign',new DateTimeImmutable(),new DateTimeImmutable()),
        ];
    }
    public function find(string $org,string $operationId): ?AsyncOperationProjection {
        return array_values(array_filter($this->recentForOrganization($org),static fn($p)=>$p->id===$operationId))[0] ?? null;
    }
};
$agents = new class implements AgentRunReadModelInterface {
    public function recentForOrganization(string $org,int $limit=20): array {return [];}
    public function recentForSubject(string $org,string $subjectType,string $subjectId,int $limit=20):array{return [];}
    public function find(string $org,string $runId):?AgentRunProjection{return null;}
    public function actionsForRun(string $org,string $runId):array{return [];}
};
// A read-only test double fails if any mutation is attempted.
$store = new class implements WorkflowExecutionStoreInterface {
    public function create(WorkflowRunDraft $draft, TenantContext $actor): WorkflowRunSnapshot { throw new LogicException('No writes'); }
    public function get(WorkflowRunIdentity $identity): ?WorkflowRunSnapshot { return null; }
    public function steps(WorkflowRunIdentity $identity): array {
        if ($identity->organizationId !== 'tenant-a' || $identity->runId !== 'run-1') return [];
        return [
            new WorkflowStepSnapshot('one','native.first','1','none','failed',1,3),
            new WorkflowStepSnapshot('two','native.second','1','none','pending',0,1),
        ];
    }
    public function compareAndTransition(WorkflowRunTransition $transition): WorkflowRunSnapshot { throw new LogicException('No writes'); }
    public function claim(WorkflowStepClaim $claim): WorkflowStepSnapshot { throw new LogicException('No writes'); }
    public function checkpoint(WorkflowStepCheckpoint $checkpoint): WorkflowStepSnapshot { throw new LogicException('No writes'); }
};
$snap = new OperationsSnapshot($read,$agents,$async,$store);
$actor = static fn(string $org,string $role,array $permissions):TenantContext => new TenantContext(
    UserId::fromString('1'),OrganizationId::fromString($org),OrganizationRole::fromString($role),
    array_map(Permission::fromString(...), $permissions),
);
$a = $actor('tenant-a','manager',[TenantPermissions::ACCESS,TenantPermissions::MANAGE]);
$r = $snap->read($a,null,'','', '');
if (count($r['rows']) !== 52 || $r['unavailable'] !== ['import']) throw new RuntimeException('Partial snapshot or cross-tenant isolation failed.');
foreach ($r['rows'] as $row) if ($row['source'] !== 'action' && $row['status'] !== S::Unverified) throw new RuntimeException('False success.');
$entity = $snap->read($a,new EntityRef('product','p1'),'corr-1','async','async:job-1');
if (count($entity['rows'])!==1 || $entity['detail']['id']!=='job-1') throw new RuntimeException('Entity detail failed.');
$other = $actor('tenant-b','manager',[TenantPermissions::ACCESS,TenantPermissions::MANAGE]);
if ($snap->one($other,'async','job-1') !== null) throw new RuntimeException('Foreign direct lookup leaked.');
try {
    $snap->read($actor('tenant-a','member',[TenantPermissions::ACCESS]),null,'','','');
    throw new RuntimeException('Denied role accessed operations.');
} catch (DomainException) {}
$workflow = $snap->read($a,null,'','workflow','workflow:run-1');
if ($workflow['detail']['items'][1]['blockers'] !== ['one'] || $workflow['detail']['items'][0]['attempt']!==1
    || $workflow['detail']['status']!==S::Unverified) throw new RuntimeException('Workflow dependency or evidence projection failed.');
$action = $snap->read($a,null,'','action','action:a01');
if ($action['detail']['id']!=='a01' || $action['detail']['items'][0]['receipt']!=='123'
    || !$action['detail']['items'][0]['hasError']) throw new RuntimeException('Historical action detail unavailable.');
$first = $snap->read($a,null,'','action','');
$cursor = OperationsHistoryCursor::decode($first['nextCursor']);
$second = $snap->read($a,null,'','action','',$cursor);
if (count($first['rows']) !== 50 || count($second['rows']) !== 2 || $second['nextCursor'] !== ''
    || array_intersect(array_column($first['rows'],'key'),array_column($second['rows'],'key')) !== []) {
    throw new RuntimeException('Archive cursor skipped or duplicated records.');
}
try {
    OperationsHistoryCursor::decode('not-a-cursor');
    throw new RuntimeException('Invalid cursor accepted.');
} catch (InvalidArgumentException) {}
echo "UX-05 archive cursor and stable pagination passed.\n";
echo "UX-05 snapshot role, tenant, detail, provider failure and no-false-success contract passed.\n";

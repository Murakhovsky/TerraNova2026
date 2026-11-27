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
use App\Persistence\Workflow\DoctrineWorkflowExecutionStore;

$read = new class implements OperationListReadInterface {
    public function rows(string $org, string $source, int $limit, ?string $id = null): array {
        if ($source === 'import') throw new RuntimeException('Provider unavailable');
        if ($org !== 'tenant-a' || $source !== 'workflow' || ($id !== null && $id !== 'run-1')) return [];
        return [['id'=>'run-1','title'=>'federation.approved-plan','native_status'=>'completed',
            'total_steps'=>2,'completed_steps'=>2,'correlation_id'=>'corr-1','updated_at'=>'2026-11-27 10:00:00']];
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
// No workflow detail is selected in this test; fixture cannot use or mutate store.
$store = (new ReflectionClass(DoctrineWorkflowExecutionStore::class))->newInstanceWithoutConstructor();
$snap = new OperationsSnapshot($read,$agents,$async,$store);
$actor = static fn(string $org,string $role,array $permissions):TenantContext => new TenantContext(
    UserId::fromString('1'),OrganizationId::fromString($org),OrganizationRole::fromString($role),
    array_map(Permission::fromString(...), $permissions),
);
$a = $actor('tenant-a','manager',[TenantPermissions::ACCESS,TenantPermissions::MANAGE]);
$r = $snap->read($a,null,'','', '');
if (count($r['rows']) !== 2 || $r['unavailable'] !== ['import']) throw new RuntimeException('Partial snapshot or cross-tenant isolation failed.');
foreach ($r['rows'] as $row) if ($row['status'] !== S::Unverified) throw new RuntimeException('False success.');
$entity = $snap->read($a,new EntityRef('product','p1'),'corr-1','async','async:job-1');
if (count($entity['rows'])!==1 || $entity['detail']['id']!=='job-1') throw new RuntimeException('Entity detail failed.');
$other = $actor('tenant-b','manager',[TenantPermissions::ACCESS,TenantPermissions::MANAGE]);
if ($snap->one($other,'async','job-1') !== null) throw new RuntimeException('Foreign direct lookup leaked.');
try {
    $snap->read($actor('tenant-a','member',[TenantPermissions::ACCESS]),null,'','','');
    throw new RuntimeException('Denied role accessed operations.');
} catch (DomainException) {}
echo "UX-05 snapshot role, tenant, detail, provider failure and no-false-success contract passed.\n";

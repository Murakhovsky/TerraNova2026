<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';

use DomainException as Blocked;
use Domains\Marketing\Application\Contract\MarketingPaidReadRepository;
use Domains\Marketing\Application\Contract\PaidMediaProviderInterface;
use Domains\Marketing\Application\Service\MarketingPaidReadService;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

$assert=static fn(bool $ok,string $msg)=>$ok?:throw new RuntimeException($msg);
$access=[Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)];
$manager=new TenantContext(UserId::fromString('1'),OrganizationId::fromString('org-a'),OrganizationRole::fromString('manager'),$access);
$alien=new TenantContext(UserId::fromString('2'),OrganizationId::fromString('org-b'),OrganizationRole::fromString('manager'),$access);
$viewer=new TenantContext(UserId::fromString('3'),OrganizationId::fromString('org-a'),OrganizationRole::fromString('viewer'),$access);

$repository=new class implements MarketingPaidReadRepository {
    public array $received=[];
    private const ID='mads-1234567890abcdef12345678';
    public function registerAccount(string $organizationId,string $credentialRef,array $verified):array
    {
        $this->received[]=['connect',$organizationId];
        return ['id'=>self::ID,'organization_id'=>$organizationId,'connection_status'=>'READ_ONLY'];
    }
    public function accounts(string $organizationId,int $limit=100):array
    {
        return $organizationId==='org-a'?[['id'=>self::ID,'connection_status'=>'READ_ONLY']]:[];
    }
    public function account(string $organizationId,string $id):?array
    {
        return $organizationId==='org-a'&&$id===self::ID?[
            'id'=>self::ID,'connection_status'=>'READ_ONLY','ad_account_id'=>'act_12345','credential_ref'=>'env://META_ADS_READ_TEST'
        ]:null;
    }
    public function recordSyncFailure(string $organizationId,string $accountId,string $requestedBy,string $errorCode):void
    {
        $this->received[]=['failed',$organizationId,$accountId,$requestedBy,$errorCode];
    }
    public function syncHistory(string $organizationId,string $accountId,int $limit=20):array
    {
        $this->received[]=['history',$organizationId,$accountId,$limit];
        return [];
    }
    public function spendEvidence(string $organizationId,string $accountId,string $snapshotId):array
    {
        if($organizationId!=='org-a'||$accountId!==self::ID
            ||$snapshotId!=='mis-'.str_repeat('a',24)){
            throw new DomainException('Unknown tenant Marketing evidence.');
        }
        $this->received[]=['evidence',$organizationId,$accountId,$snapshotId];
        return ['source_snapshot_ref'=>$snapshotId,'reported_spend_decimal'=>'10.250000'];
    }
    public function importHierarchy(string $organizationId,string $accountId,array $hierarchy):array{throw new RuntimeException('Atomic-only import expected.');}
    public function importInsights(string $organizationId,string $accountId,string $grain,string $from,string $to,array $rows):int{throw new RuntimeException('Atomic-only import expected.');}
    public function importSnapshot(string $organizationId,string $accountId,string $from,string $to,array $hierarchy,array $insights):array
    {
        $this->received[]=['atomic',$organizationId,$accountId,$from,$to];
        return ['campaigns'=>1,'adsets'=>1,'ads'=>1,'insights'=>1];
    }
    public function hierarchy(string $organizationId,string $accountId):array
    {
        $this->received[]=['hierarchy',$organizationId,$accountId];
        return ['campaigns'=>[],'adsets'=>[],'ads'=>[]];
    }
    public function insights(string $organizationId,string $accountId,string $grain,string $from,string $to):array
    {
        $this->received[]=['insights',$organizationId,$accountId];
        return [];
    }
    public function campaigns(string $organizationId,string $accountId,int $limit=100):array{return [];}
};
$provider=new class implements PaidMediaProviderInterface {
    public array $received=[];
    public bool $fail=false;
    public function inspectAccount(string $organizationId,string $providerAccountId,string $credentialRef):array
    {
        $this->received[]=['inspect',$organizationId];
        return ['ad_account_id'=>$providerAccountId,'connection_status'=>'READ_ONLY'];
    }
    public function hierarchy(string $organizationId,string $providerAccountId,string $credentialRef):array
    {
        $this->received[]=['hierarchy',$organizationId];
        if($this->fail)throw new DomainException('Remote read failed; sensitive provider message must not persist.');
        return ['campaigns'=>[['id'=>'1']],'adsets'=>[['id'=>'2']],'ads'=>[['id'=>'3']]];
    }
    public function insights(string $organizationId,string $providerAccountId,string $credentialRef,string $from,string $to,string $grain):array
    {
        $this->received[]=['insights',$organizationId];
        return [['campaign_id'=>'1','spend'=>'1.00']];
    }
};
$paid=new MarketingPaidReadService($repository,$provider);
$assert(count($paid->accounts($alien))===0,'Tenant B must see no Tenant A account.');
$assert(count($paid->accounts($manager))===1,'Tenant A must see its account.');
try {$paid->connect($viewer,'act_12345','env://META_ADS_READ_TEST');}catch(Blocked){$blocked=true;}
$assert($blocked??false,'Viewer cannot connect an Ad Account.');
try {$paid->sync($alien,'mads-1234567890abcdef12345678','2026-11-01','2026-11-07');}
catch(Blocked){$blocked=true;}
$assert($blocked??false,'Cross-tenant sync blocked.');
$assert(count($provider->received)===0,'Denied cross-tenant sync must not reach Meta.');
$got=$paid->sync($manager,'mads-1234567890abcdef12345678','2026-11-01','2026-11-07');
$assert($got['insights']===1,'Atomic snapshot receipt required.');
$assert($repository->received[count($repository->received)-1][0]==='atomic','Only atomic import allowed.');
$paid->hierarchy($manager,'mads-1234567890abcdef12345678');
$assert($repository->received[count($repository->received)-1][1]==='org-a','Hierarchy must carry actor tenant.');
$assert($paid->syncHistory($manager,'mads-1234567890abcdef12345678')===[],'Sync history should be tenant bound.');
try {$paid->syncHistory($alien,'mads-1234567890abcdef12345678');}
catch(Blocked){$historyDenied=true;}
$assert($historyDenied??false,'Foreign tenant must not read sync history.');
$provider->fail=true;
try {$paid->sync($manager,'mads-1234567890abcdef12345678','2026-11-01','2026-11-07');}
catch(Blocked){$remoteFailed=true;}
$assert($remoteFailed??false,'Provider read failure must not be promoted to success.');
$receipt=$repository->received[count($repository->received)-1];
$assert($receipt===['failed','org-a','mads-1234567890abcdef12345678','1','REMOTE_READ_FAILED'],
    'Failed remote read must create a sanitized tenant-only operator receipt.');
$provider->fail=false;
$before=count($provider->received);
try {$paid->sync($manager,'mads-1234567890abcdef12345678','2026-02-31','2026-11-07');}
catch(Blocked){$dateDenied=true;}
$assert($dateDenied??false&&count($provider->received)===$before,'Invalid read reporting window must not reach Meta.');
$evidence=$paid->spendEvidence($manager,'mads-1234567890abcdef12345678','mis-'.str_repeat('a',24));
$assert($evidence['reported_spend_decimal']==='10.250000','Tenant Marketing spend evidence readback failed.');
$deniedOther=false;
try{$paid->spendEvidence($alien,'mads-1234567890abcdef12345678','mis-'.str_repeat('a',24));}
catch(DomainException){$deniedOther=true;}
$assert($deniedOther,'Other tenant reached Marketing spend evidence.');
$deniedViewer=false;
try{$paid->spendEvidence($viewer,'mads-1234567890abcdef12345678','mis-'.str_repeat('a',24));}
catch(DomainException){$deniedViewer=true;}
$assert($deniedViewer,'Viewer reached Marketing spend evidence.');
$deniedId=false;
try{$paid->spendEvidence($manager,'mads-1234567890abcdef12345678','mis-../../wrong');}
catch(DomainException){$deniedId=true;}
$assert($deniedId,'Invalid source snapshot reference was accepted.');
echo "Marketing Meta tenant and atomic sync service PASS\n";

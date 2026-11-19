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
    public function inspectAccount(string $organizationId,string $providerAccountId,string $credentialRef):array
    {
        $this->received[]=['inspect',$organizationId];
        return ['ad_account_id'=>$providerAccountId,'connection_status'=>'READ_ONLY'];
    }
    public function hierarchy(string $organizationId,string $providerAccountId,string $credentialRef):array
    {
        $this->received[]=['hierarchy',$organizationId];
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
echo "Marketing Meta tenant and atomic sync service PASS\n";

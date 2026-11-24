<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require (getenv('COS_TEST_AUTOLOAD')?:$root.'/vendor/autoload.php');

use App\Infrastructure\Finance\MarketingSpendEvidenceReadAdapter;
use Domains\Finance\Application\Contract\MarketingSpendEvidenceReadPort;
use Domains\Finance\Application\Service\FinanceMarketingSpendReadbackService;
use Domains\Finance\Application\Service\FinanceSpendObservationService;
use Domains\Finance\Domain\FinanceSpendObservationPolicy;
use Domains\Finance\Infrastructure\Persistence\MySql\MysqlFinanceSpendObservationRepository;
use Domains\Marketing\Application\Contract\PaidMediaProviderInterface;
use Domains\Marketing\Application\Service\MarketingPaidReadService;
use Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidReadRepository;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

$check=static function(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);};
$denied=static function(callable $act,string $why):void {
    try{$act();}catch(DomainException){return;}
    throw new RuntimeException($why);
};
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST')?:'127.0.0.1',getenv('DB_PORT')?:'3306',getenv('DB_DATABASE')?:'cos'),
    getenv('DB_USERNAME')?:'cos',getenv('DB_PASSWORD')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

$organization='finance-marketing-bridge-'.bin2hex(random_bytes(5));
$alienOrg='finance-marketing-foreign-'.bin2hex(random_bytes(5));
$permissions=[Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)];
$actor=static fn(string $org,string $role)=>new TenantContext(
    UserId::fromString('301'),OrganizationId::fromString($org),
    OrganizationRole::fromString($role),$permissions);
$manager=$actor($organization,'manager');
$foreign=$actor($alienOrg,'manager');
$viewer=$actor($organization,'viewer');
$marketing=new MysqlMarketingPaidReadRepository($db);
$financePolicy=new FinanceSpendObservationPolicy();
$finance=new MysqlFinanceSpendObservationRepository($db,$financePolicy);
$account=$marketing->registerAccount($organization,'env://FIN_MKT_BRIDGE_TEST',[
    'ad_account_id'=>'act_'.random_int(1000000000000,9999999999999),
    'currency'=>'USD','timezone'=>'Europe/Kyiv',
    'connection_status'=>'READ_ONLY','capability_scope'=>['ads_read']
]);
$id=$account['id'];
$from='2026-11-01';$to='2026-11-07';
$source=[
    'campaign_id'=>'81000031','date_start'=>$from,'date_stop'=>$to,'spend'=>'21.750000',
    'impressions'=>'100','clicks'=>'12'
];
$hierarchy=[
    'campaigns'=>[['id'=>'81000031','name'=>'Source-backed campaign','effective_status'=>'PAUSED']],
    'adsets'=>[], 'ads'=>[]
];
$marketing->importSnapshot($organization,$id,$from,$to,$hierarchy,[$source]);
$rows=$marketing->insights($organization,$id,'campaign',$from,$to);
$check(count($rows)===1,'Marketing test source row not persisted.');
$snapshotId=(string)$rows[0]['id'];

$provider=new class implements PaidMediaProviderInterface {
    public function inspectAccount(string $organizationId,string $providerAccountId,string $credentialRef):array
    {throw new RuntimeException('No Meta provider calls permitted during Finance DB readback.');}
    public function hierarchy(string $organizationId,string $providerAccountId,string $credentialRef):array
    {throw new RuntimeException('No Meta provider calls permitted during Finance DB readback.');}
    public function insights(string $organizationId,string $providerAccountId,string $credentialRef,string $from,string $to,string $grain):array
    {throw new RuntimeException('No Meta provider calls permitted during Finance DB readback.');}
};
$marketingReadService=new MarketingPaidReadService($marketing,$provider);
$sourceAdapter=new MarketingSpendEvidenceReadAdapter($marketingReadService);
$financeService=new FinanceSpendObservationService($finance,$financePolicy);
$bridge=new FinanceMarketingSpendReadbackService($sourceAdapter,$financeService);

$receipt=$bridge->import($manager,$id,$snapshotId,'finance-marketing-import-01');
$check($receipt['status']==='SOURCE_OBSERVATION_UNRECONCILED'
    &&$receipt['readback_status']==='MARKETING_DB_SNAPSHOT'
    &&!$receipt['finance_expense_confirmed']&&!$receipt['budget_mutated']
    &&!$receipt['source_provenance_verified_by_provider'],'Bridge overclaimed financial verification.');
$one=$receipt['observation'];
$check($one['source_snapshot_ref']===$snapshotId
    &&$one['source_revision']===1
    &&$one['reported_spend_decimal']==='21.75'
    &&$one['evidence_verification']==='UNVERIFIED_SOURCE'
    &&!$one['finance_expense_confirmed'],'Finance received fabricated Expense or wrong source snapshot.');

$replay=$bridge->import($manager,$id,$snapshotId,'finance-marketing-import-retry');
$check($replay['observation']['id']===$one['id'],'Repeated source snapshot imported duplicate Finance observation.');
$denied(static fn()=> $bridge->import($foreign,$id,$snapshotId,'finance-foreign-01'),
    'Cross-tenant bridge was allowed to copy another tenant source.');
$denied(static fn()=> $bridge->import($viewer,$id,$snapshotId,'finance-viewer-01'),
    'Viewer imported Finance Marketing evidence.');
$denied(static fn()=> $bridge->import($manager,$id,'mis-'.str_repeat('f',24),'finance-missing-snapshot'),
    'Nonexistent Marketing snapshot created Finance source evidence.');
$denied(static fn()=> $bridge->import($manager,'mads-'.str_repeat('a',24),$snapshotId,'finance-wrong-account'),
    'Marketing snapshot was allowed to change account.');

$fake=new class implements MarketingSpendEvidenceReadPort {
    public function get(TenantContext $actor,string $accountId,string $snapshotId):array
    {
        return ['ad_account_ref'=>'mads-'.str_repeat('c',24),'source_snapshot_ref'=>$snapshotId,'grain'=>'campaign'];
    }
};
$unsafe=new FinanceMarketingSpendReadbackService($fake,$financeService);
$denied(static fn()=> $unsafe->import($manager,$id,$snapshotId,'finance-forged-source'),
    'Caller-supplied foreign account source bypassed Finance readback shape validation.');

$revised=[...$source,'spend'=>'30.125000','clicks'=>'18'];
$marketing->importInsights($organization,$id,'campaign',$from,$to,[$revised]);
$latest=$bridge->import($manager,$id,$snapshotId,'finance-marketing-import-rev-02')['observation'];
$check($latest['id']===$one['id']&&$latest['source_revision']===2
    &&$latest['source_status']==='REVISED'
    &&$latest['reported_spend_decimal']==='30.125','Finance did not preserve source revision provenance.');
$oldSource=$marketing->spendEvidence($organization,$id,$snapshotId);
$check($oldSource['source_revision']===2,'Marketing source readback did not resolve current revision.');
$financeScope=$financePolicy->scope($id,$from,$to,'USD','Europe/Kyiv',$oldSource['attribution_hash']);
$status=$finance->reconciliationStatus($organization,$financeScope);
$check($status['status']==='UNRECONCILED'&&$status['source_count']===1
    &&$status['source_reported_spend_decimal']==='30.125'
    &&!$status['finance_expense_confirmed']&&!$status['budget_exposure_coverage_confirmed'],
    'Finance reconciliation incorrectly promoted a Marketing source observation.');
$foreignRead=$finance->reconciliationStatus($alienOrg,$financeScope);
$check($foreignRead['source_count']===0,'Finance bridge leaked one tenant source to another.');
$q=$db->prepare('SELECT COUNT(*) FROM cos_finance_spend_evidence_events WHERE organization_id=?');
$q->execute([$organization]);$check((int)$q->fetchColumn()===2,'Finance source correction created duplicated or missing events.');
$q=$db->prepare('SELECT COUNT(*) FROM cos_finance_budget_reservations WHERE organization_id=?');
$q->execute([$organization]);$check((int)$q->fetchColumn()===0,'Marketing source ingestion reserved budget.');
echo "Marketing→Finance exact readback, tenant isolation, idempotent source revisions and no financial mutation PASS\n";

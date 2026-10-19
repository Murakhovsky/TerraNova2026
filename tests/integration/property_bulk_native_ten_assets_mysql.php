<?php
declare(strict_types=1);

/**
 * Isolated, real MySQL: 10 staged -> identity claims -> native Property
 * writer -> 10 independently read Assets -> same-key reconciliation.
 *
 * The Action is a test fixture. Canonical approval/admission remains covered
 * by federation_canonical_goal_e2e_mysql.php and dedicated worker gates.
 * This is NOT an assertion that the full approved Property Goal E2E passed.
 */
if (getenv('COS_TEST_PROPERTY_BULK_NATIVE')!=='1') {
    throw new RuntimeException('Explicit isolated Property test rollout required.');
}
require (string)(getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__,2).'/symfony/vendor/autoload.php');
spl_autoload_register(static function(string $name):void {
    if(!str_starts_with($name,'App\\'))return;
    $file=dirname(__DIR__,2).'/symfony/src/'.str_replace('\\','/',substr($name,4)).'.php';
    if(is_file($file))require $file;
});

use App\Application\Property\Service\PropertyWriteService;
use App\Infrastructure\Persistence\MySql\MysqlPropertyLocationReference;
use App\Infrastructure\Persistence\Property\PdoPropertyMutationReceipt;
use App\Persistence\Property\PropertyBulkIntakeService;
use Doctrine\DBAL\DriverManager;
use Domains\Property\Application\Service\CanonicalPropertyInventoryCommands;
use Domains\Property\Application\Service\PropertyCanonicalRuntimeService;
use Domains\Property\Automation\Action\PropertyBulkCreateHandler;
use Domains\Property\Infrastructure\Persistence\MySql\MysqlPropertyCanonicalRuntimeRepository;
use Domains\Property\Infrastructure\Persistence\MySql\MysqlPropertyProjection;
use Domains\Property\Infrastructure\ReadModel\MySql\MysqlPropertyReferencePort;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Kernel\Transaction\Contract\TransactionManagerInterface;

$host=(string)(getenv('DB_HOST')?:'mysql');
$port=(int)(getenv('DB_PORT')?:3306);
$name=(string)(getenv('DB_DATABASE')?:'cos');
$user=(string)(getenv('DB_USERNAME')?:'cos');
$password=(string)(getenv('DB_PASSWORD')?:'');
$pdo=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",$user,$password,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$db=DriverManager::getConnection([
    'driver'=>'pdo_mysql','host'=>$host,'port'=>$port,'dbname'=>$name,
    'user'=>$user,'password'=>$password,'charset'=>'utf8mb4',
]);
$token=bin2hex(random_bytes(7));
$org='bulk-native-'.$token;
$batchOwner='42';
$actor=new TenantContext(UserId::fromString($batchOwner),
    OrganizationId::fromString($org),OrganizationRole::fromString('manager'),
    [Permission::fromString(TenantPermissions::MANAGE)]);
$check=static function(bool $valid,string $error):void {
    if(!$valid)throw new RuntimeException('Property 10 native Assets: '.$error);
};
$reject=static function(callable $call,string $error):void {
    try{$call();}catch(DomainException|\InvalidArgumentException){return;}
    throw new RuntimeException('Unsafe Property mutation accepted: '.$error);
};

// Reuse the real canonical Property repositories, writer and receipt store.
// Event/Audit sinks in this acceptance are test-local to prevent outbound events.
$transactions=new class($pdo) implements TransactionManagerInterface {
    public function __construct(private PDO $pdo){}
    public function transactional(callable $operation):mixed {
        if($this->pdo->inTransaction()) return $operation();
        $this->pdo->beginTransaction();
        try{$value=$operation();$this->pdo->commit();return $value;}
        catch(Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error;}
    }
    public function isActive():bool {return $this->pdo->inTransaction();}
    public function afterCommit(callable $callback):void {
        if($this->pdo->inTransaction())throw new RuntimeException('Acceptance afterCommit during transaction');
        $callback();
    }
};
$events=new class implements EventStoreInterface {
    public int $count=0;
    public function append(DomainEvent $event):void {$this->count++;}
    public function find(string $eventId):?DomainEvent {return null;}
    public function findByAggregate(string $organizationId,string $aggregateType,
        string $aggregateId,int $limit=100):array {return [];}
};
$audit=new class implements AuditRepositoryInterface {
    public int $count=0;
    public function append(AuditEntry $entry):void {$this->count++;}
};
$references=new MysqlPropertyReferencePort($pdo);
$repository=new MysqlPropertyCanonicalRuntimeRepository($pdo);
$projection=new MysqlPropertyProjection($pdo,new MysqlPropertyLocationReference($pdo));
$runtime=new PropertyCanonicalRuntimeService($repository,$projection,new EventBus($events,$transactions),$transactions);
$writer=new PropertyWriteService($runtime,new CanonicalPropertyInventoryCommands($runtime),
    $repository,$references,new PdoPropertyMutationReceipt($pdo),$transactions,$audit);
$intake=new PropertyBulkIntakeService($db,$references,$writer);
$handler=new PropertyBulkCreateHandler($intake);

$pdo->prepare('INSERT INTO cos_organizations (id,name,status) VALUES (:id,:name,"ACTIVE")')
    ->execute(['id'=>$org,'name'=>'Isolated Property native batch acceptance']);
$items=[];
for($i=0;$i<10;$i++) {
    $items[]=['type_code'=>$i%2===0?'house':'apartment','city'=>'Львів',
        'region'=>'Львівська','address'=>'Acceptance '.$token.' building '.($i+1),
        'area_total'=>100.0+$i,'rooms'=>2];
}
$batch=$intake->stage($actor,$items);
$check($batch['count']===10,'Staging cardinality differs from input.');
$check((int)$db->fetchOne('SELECT COUNT(*) FROM tn_property_assets WHERE organization_id=:org',
    ['org'=>$org])===0,'Staging mutated native Property.');

$batchId=$batch['batch_id'];
$digest=$batch['digest'];
$check(count($intake->reviewForPlan($actor,$batchId,$digest))===10,'Review has incorrect cardinality.');

// Deliberately corrupt one JSON field, verifying the real service refuses
// the changed payload EVEN IF its column fingerprint remained unchanged.
$original=(string)$db->fetchOne('SELECT payload_json FROM cos_property_bulk_items
    WHERE organization_id=:org AND batch_id=:batch AND item_index=0',
    ['org'=>$org,'batch'=>$batchId]);
$db->executeStatement('UPDATE cos_property_bulk_items SET payload_json=:payload
    WHERE organization_id=:org AND batch_id=:batch AND item_index=0',[
    'payload'=>json_encode($items[0]+['unknown'=>'tampered'],JSON_THROW_ON_ERROR),
    'org'=>$org,'batch'=>$batchId,
]);
$reject(fn()=>$intake->reviewForPlan($actor,$batchId,$digest),'mutated staged payload');
$db->executeStatement('UPDATE cos_property_bulk_items SET payload_json=:payload
    WHERE organization_id=:org AND batch_id=:batch AND item_index=0',[
    'payload'=>$original,'org'=>$org,'batch'=>$batchId,
]);
$check(count($intake->reviewForPlan($actor,$batchId,$digest))===10,'Restoring item did not restore the digest.');

foreach($items as $input) {
    $db->insert('cos_property_bulk_identity_claims',[
        'organization_id'=>$org,'batch_id'=>$batchId,
        'fingerprint'=>PropertyBulkIntakeService::fingerprint(PropertyBulkIntakeService::canonicalize($input)),
        'created_at'=>gmdate('Y-m-d H:i:s'),
    ]);
}
// Approved Plan transition is simulated ONLY at the Property boundary:
// the genuine independent Plan Approval is tested in Federation E2E.
$db->executeStatement('UPDATE cos_property_bulk_batches SET state=:state
    WHERE organization_id=:org AND batch_id=:batch',[
    'state'=>'planned','org'=>$org,'batch'=>$batchId,
]);
$action=new Action(bin2hex(random_bytes(16)),$org,PropertyBulkCreateHandler::TYPE,
    'property_bulk_batch',$batchId,['batch_digest'=>$digest],
    'USER',$batchOwner,'APPROVAL_REQUIRED','HIGH','wf:'.hash('sha256',$org.':'.$batchId),
    new DateTimeImmutable(),ActionStatus::Running,'federation:property-test-'.$token);

$result=$handler->execute($action);
$check($result->successful,'Real Property writer failed: '.($result->error??'unknown'));
$check(($result->data['verified_count']??null)===10,'Ten native records not attested.');
$assetIds=$result->data['asset_ids']??[];
$check(is_array($assetIds)&&count(array_unique($assetIds))===10,'Non-unique Property Assets.');
foreach($assetIds as $index=>$assetId) {
    $native=$references->getPropertyReference($org,$assetId);
    $check(is_array($native)&&$native['asset_id']===$assetId,'Missing independent native Property read.');
    $check($native['type_code']===$items[$index]['type_code'],'Native type did not match input.');
}
$check((int)$db->fetchOne('SELECT COUNT(*) FROM tn_property_assets WHERE organization_id=:org',
    ['org'=>$org])===10,'Native registry has unexpected cardinality.');
$check($audit->count===10&&$events->count===10,'Domain writer did not emit expected events and audits.');
$replayed=$handler->execute($action);
$check($replayed->successful && $replayed->data['asset_ids']===$assetIds,
    'Same Action must reconcile native records, never duplicate them.');
$check((int)$db->fetchOne('SELECT COUNT(*) FROM tn_property_assets WHERE organization_id=:org',
    ['org'=>$org])===10,'Same Action replay created extra Property Assets.');

// Neither an unrelated Action identity nor changed digest can mutate the batch.
$forged=new Action(bin2hex(random_bytes(16)),$org,PropertyBulkCreateHandler::TYPE,
    'property_bulk_batch',$batchId,['batch_digest'=>str_repeat('a',64)],
    'USER',$batchOwner,'APPROVAL_REQUIRED','HIGH','wf:'.hash('sha256','different'),
    new DateTimeImmutable(),ActionStatus::Running,'federation:property-test-'.$token);
$check(!$handler->execute($forged)->successful,'Conflicting approved digest was accepted.');
$check($intake->preview($actor,$batchId)['state']==='verified','Batch did not finish verified.');
echo "Property native bulk 10 Assets and idempotent reconciliation MySQL PASS.\n";

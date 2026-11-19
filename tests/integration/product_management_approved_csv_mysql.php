<?php
declare(strict_types=1);

/**
 * Native Product CSV approved Action boundary on isolated real MySQL.
 * Running Action in this fixture simulates only the already-approved worker
 * boundary. Full canonical Plan/Approval/Workflow admission is verified by
 * the existing independent Federation tests and is NOT asserted here.
 */
$root=dirname(__DIR__,2);
require (string)(getenv('COS_TEST_AUTOLOAD')?:$root.'/symfony/vendor/autoload.php');

use Domains\ProductManagement\Application\Service\CsvProductImportService;
use Domains\ProductManagement\Application\Service\ProductManagementService;
use Domains\ProductManagement\Automation\Action\ProductImportApplyHandler;
use Domains\ProductManagement\Infrastructure\Persistence\MySqlProductManagementStore;
use Infrastructure\Platform\Persistence\MySql\Event\MysqlEventStore;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Module\ModuleDiscovery;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;

$pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    (string)(getenv('DB_HOST')?:'mysql'),(int)(getenv('DB_PORT')?:3306),
    (string)(getenv('DB_DATABASE')?:'cos')),
    (string)(getenv('DB_USERNAME')?:'cos'),(string)(getenv('DB_PASSWORD')?:''),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$assert=static function(bool $ok,string $reason):void {
    if(!$ok)throw new RuntimeException('Approved Product CSV: '.$reason);
};
foreach(['pm_import_batches','pm_import_rows','cos_events','cos_event_outbox'] as $table) {
    $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $q->execute([$table]);
    $assert((int)$q->fetchColumn()===1,'migration missing '.$table);
}
$products=new ProductManagementService(new MySqlProductManagementStore($pdo,new MysqlEventStore($pdo)));
$csv=new CsvProductImportService($pdo,$products);
$moduleState=new class implements ModuleStateRepositoryInterface {
    public bool $enabled=false;
    public function enabledOverride(string $organizationId,string $moduleId):?bool {
        return $moduleId==='product_management'?$this->enabled:null;
    }
    public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void {
        if($moduleId==='product_management')$this->enabled=$enabled;
    }
};
$catalog=new ModuleCatalog((new ModuleDiscovery($root.'/app/Domains'))->discover());
$modules=new ActiveModuleResolver($catalog,$moduleState);
$handler=new ProductImportApplyHandler($csv,$modules);
$moduleState->setEnabled('pm-gate-test','product_management',true);
$token=bin2hex(random_bytes(7));
$org='pm-approved-'.$token;
$skuA='CSV'.strtoupper($token).'A';$skuB='CSV'.strtoupper($token).'B';
$source="sku,name,type,uom,track_inventory\n".$skuA.",Alpha,stock,pcs,1\n".$skuB.",Beta,stock,pcs,1\n";
$batch=$csv->preview($org,$source,'42','csv-preview-'.$token);
$assert($batch['state']==='staged'
    &&preg_match('/^[a-f0-9]{64}$/D',(string)($batch['rows_digest']??''))
    &&($batch['counts']['ready']??0)===2,'staged rowset evidence missing');
$assert($products->searchProducts($org,$skuA)===[],'preview wrote a Product');
$actionKey='wf:'.hash('sha256',$org.':'.$batch['batch_id']);
$makeAction=static function(string $actionOrg,array $params,ActionStatus $status=ActionStatus::Running)use($batch,$actionKey):Action {
    return new Action(bin2hex(random_bytes(16)),$actionOrg,ProductImportApplyHandler::TYPE,
        'product_import_batch',$batch['batch_id'],$params,'USER','42',
        'APPROVAL_REQUIRED','HIGH',$actionKey,new DateTimeImmutable(),
        $status,'pm-approved-csv-test');
};
$params=['batch_digest'=>$batch['digest'],'rows_digest'=>$batch['rows_digest']];
$unapproved=$makeAction($org,$params,ActionStatus::PendingApproval);
$assert(!$handler->execute($unapproved)->successful,'PendingApproval Action bypassed worker boundary');
$wrong=$makeAction($org,array_replace($params,['rows_digest'=>str_repeat('a',64)]));
$assert(!$handler->execute($wrong)->successful,'Forged approved CSV digest was accepted');
$cross=$makeAction('pm-other-'.$token,$params);
$assert(!$handler->execute($cross)->successful,'Other tenant could apply approved batch');
$assert($products->searchProducts($org,$skuA)===[],'Denied Action created a Product');
$action=$makeAction($org,$params);
$moduleState->setEnabled($org,'product_management',false);
$assert(!$handler->execute($action)->successful,
    'Disabled tenant must not apply even a valid approved CSV Action');
$assert($products->searchProducts($org,$skuA)===[],
    'Disabled tenant Action silently wrote Products');
$moduleState->setEnabled($org,'product_management',true);
$applied=$handler->execute($action);
$assert($applied->successful,'Approved native Product CSV writer failed: '.($applied->error??''));
$assert(($applied->data['verified_count']??null)===2
    &&count(array_unique($applied->data['product_ids']??[]))===2,'Two native Product receipts not verified');
$assert(($csv->report($org,$batch['batch_id'])['state']??null)==='applied','Batch was not marked applied');
$assert(count($products->searchProducts($org,$skuA))===1
    &&count($products->searchProducts($org,$skuB))===1,'Native Product records absent');
$replay=$handler->execute($action);
$assert($replay->successful && $replay->data['product_ids']===$applied->data['product_ids'],
    'Replaying same approved Action created different native identities');
$stmt=$pdo->prepare('SELECT COUNT(*) FROM pm_products WHERE organization_id=?');
$stmt->execute([$org]);
$assert((int)$stmt->fetchColumn()===2,'Approved Action replay must not duplicate Products');
$stmt=$pdo->prepare("SELECT COUNT(*) FROM cos_events WHERE organization_id=? AND type='product_management.product.created'");
$stmt->execute([$org]);
$assert((int)$stmt->fetchColumn()===2,'Native writer duplicated Domain Events');
$stmt=$pdo->prepare("SELECT COUNT(*) FROM cos_event_outbox o INNER JOIN cos_events e ON e.id=o.event_id
 WHERE e.organization_id=? AND e.type='product_management.product.created'");
$stmt->execute([$org]);
$assert((int)$stmt->fetchColumn()===2,'Native writer did not append transactional Outbox receipts');

// A changed staged payload must fail before the first Product write.
$tamperedOrg='pm-tampered-'.$token;
$tamperedSku='TAMPER'.strtoupper($token);
$tamperedBatch=$csv->preview($tamperedOrg,"sku,name,type,uom,track_inventory\n".$tamperedSku.",Test,stock,pcs,1\n",
    '42','csv-tamper-'.$token);
$pdo->prepare("UPDATE pm_import_rows SET payload_json=? WHERE organization_id=? AND batch_id=? AND row_no=1")
    ->execute(['{"sku":"FORGED","name":"Test","type":"stock","uom":"pcs","track_inventory":true}',
        $tamperedOrg,$tamperedBatch['batch_id']]);
$tamperAction=new Action(bin2hex(random_bytes(16)),$tamperedOrg,ProductImportApplyHandler::TYPE,
    'product_import_batch',$tamperedBatch['batch_id'],
    ['batch_digest'=>$tamperedBatch['digest'],'rows_digest'=>$tamperedBatch['rows_digest']],
    'USER','42','APPROVAL_REQUIRED','HIGH',
    'wf:'.hash('sha256',$tamperedOrg.':'.$tamperedBatch['batch_id']),
    new DateTimeImmutable(),ActionStatus::Running,'pm-tamper-test');
$assert(!$handler->execute($tamperAction)->successful,'Tampered approved CSV row was accepted');
$assert($products->searchProducts($tamperedOrg,'TAMPER')===[],'Tampered CSV created a Product');
$assert($csv->report($tamperedOrg,$tamperedBatch['batch_id'])['state']==='staged',
    'Preflight tampering should fail before Action claim');

// A SKU race after preview quarantines a batch; do not continue silently.
$collisionOrg='pm-collision-'.$token;
$collisionSku='COLLIDE'.strtoupper($token);
$collisionBatch=$csv->preview($collisionOrg,"sku,name,type,uom,track_inventory\n".$collisionSku.",Import,stock,pcs,1\n",
    '42','csv-collision-'.$token);
$products->createProduct($collisionOrg,['sku'=>$collisionSku,'name'=>'Already exists','type'=>'stock'],
    'outside-'.$token,'42','ci-collision');
$collisionAction=new Action(bin2hex(random_bytes(16)),$collisionOrg,ProductImportApplyHandler::TYPE,
    'product_import_batch',$collisionBatch['batch_id'],
    ['batch_digest'=>$collisionBatch['digest'],'rows_digest'=>$collisionBatch['rows_digest']],
    'USER','42','APPROVAL_REQUIRED','HIGH',
    'wf:'.hash('sha256',$collisionOrg.':'.$collisionBatch['batch_id']),
    new DateTimeImmutable(),ActionStatus::Running,'pm-collision-test');
$assert(!$handler->execute($collisionAction)->successful,'A changed SKU claim did not block approved import');
$report=$csv->report($collisionOrg,$collisionBatch['batch_id']);
$assert($report['state']==='blocked'&&($report['counts']['failed']??0)===1,
    'Ambiguous CSV import must quarantine failed row');
$assert(!$handler->execute($collisionAction)->successful,
    'Blocked CSV must not automatically replay after ambiguity');
echo "Product Management approved CSV Action native MySQL acceptance passed.\n";

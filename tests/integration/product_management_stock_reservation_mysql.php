<?php
declare(strict_types=1);

/**
 * E5: Real MySQL acceptance for tenant-scoped manual reservation/release.
 * No Order aggregate, Shopify network, production credentials or second executor.
 */
$root=dirname(__DIR__,2);
require (string)(getenv('COS_TEST_AUTOLOAD')?:$root.'/symfony/vendor/autoload.php');

use Domains\ProductManagement\Application\Service\ProductManagementService;
use Domains\ProductManagement\Infrastructure\Persistence\MySqlProductManagementStore;
use Infrastructure\Platform\Persistence\MySql\Event\MysqlEventStore;

$pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    (string)(getenv('DB_HOST')?:'mysql'),(int)(getenv('DB_PORT')?:3306),
    (string)(getenv('DB_DATABASE')?:'cos')),
    (string)(getenv('DB_USERNAME')?:'cos'),(string)(getenv('DB_PASSWORD')?:''),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$tag=bin2hex(random_bytes(6));
$org='pm-rsv-'.$tag;$other='pm-other-'.$tag;
$products=new ProductManagementService(new MySqlProductManagementStore($pdo,new MysqlEventStore($pdo)));
$assert=static function(bool $pass,string $why):void {
    if(!$pass)throw new RuntimeException('Product reservation MySQL: '.$why);
};
$reject=static function(callable $operation,string $why):void {
    try{$operation();}catch(Throwable $error){return;}
    throw new RuntimeException('Expected E5 reservation rejection: '.$why);
};
$p=$products->createProduct($org,['name'=>'Manual Reserved Item','sku'=>'RSV'.strtoupper($tag),'type'=>'stock'],
    'rsv-product-'.$tag,'tester','rsv-e5');
$v=$p['variant_id'];
$l=$products->createLocation($org,'R-'.$tag,'Reservation warehouse','rsv-location-'.$tag,'tester','rsv-e5');
$loc=$l['location_id'];
$to=$products->createLocation($org,'T-'.$tag,'Transfer target','rsv-dest-'.$tag,'tester','rsv-e5');
$dest=$to['location_id'];
$products->recordMovement($org,$v,$loc,'5.000000','receipt','PO-RESERVATION',
    'rsv-seed-'.$tag,'tester','rsv-e5');
$origin='manual:ORDER-'.$tag;
$r=$products->reserveStock($org,$v,$loc,'3.000000',$origin,
    'rsv-create-'.$tag,'tester','rsv-e5');
$again=$products->reserveStock($org,$v,$loc,'3.000000',$origin,
    'rsv-create-'.$tag,'tester','rsv-e5');
$assert($r['reservation_id']===$again['reservation_id']
    &&$r['status']==='active' &&$r['source_ref']===$origin,
    'same-key replay must return the same reservation receipt');
$rows=$products->reservations($org,$v,$loc,$r['reservation_id'],1,0);
$assert(count($rows)===1 && $rows[0]['status']==='active' && $rows[0]['quantity']==='3.000000','Reservation independently readable');
$assert($products->reservations($other,$v,$loc,$r['reservation_id'])===[],'Reservation tenant scope');
$balance=$products->balance($org,$v,$loc);
$assert((string)$balance['on_hand']==='5.000000'
    &&(string)$balance['reserved']==='3.000000'
    &&(string)$balance['available']==='2.000000',
    'reserve must reduce available, never on_hand');
$reject(static fn()=> $products->reserveStock($org,$v,$loc,'3.000000','manual:OVERDRAW-'.$tag,
    'rsv-overdraw-'.$tag,'tester','rsv-e5'),'cannot over-reserve');
$reject(static fn()=> $products->reserveStock($org,$v,$loc,'1.000000',$origin,
    'rsv-duplicate-source-'.$tag,'tester','rsv-e5'),'source identity cannot be reused with another key');
$reject(static fn()=> $products->reserveStock($org,$v,$loc,'1.000000','sales:FORGED',
    'rsv-forged-'.$tag,'tester','rsv-e5'),'no assumed Sales/OMS writer');
$reject(static fn()=> $products->reserveStock($org,$v,$loc,'0','manual:ZERO',
    'rsv-zero-'.$tag,'tester','rsv-e5'),'zero reservation');
$reject(static fn()=> $products->recordMovement($org,$v,$loc,'-3.000000','issue',
    'SALE-RESERVED','rsv-bad-issue-'.$tag,'tester','rsv-e5'),'issue cannot spend reserved units');
$reject(static fn()=> $products->transferStock($org,$v,$loc,$dest,'3.000000',
    'TRANSFER-RESERVED','rsv-bad-transfer-'.$tag,'tester','rsv-e5'),'transfer cannot spend reserved units');
$reject(static fn()=> $products->reserveStock($other,$v,$loc,'1.000000','manual:CROSS',
    'rsv-cross-'.$tag,'intruder','rsv-e5'),'tenant cannot reserve another tenant SKU');
$assert((string)$products->balance($org,$v,$loc)['available']==='2.000000',
    'all rejected writes must roll back');

$rel=$products->releaseStock($org,$r['reservation_id'],'Manual order cancelled',
    'rsv-release-'.$tag,'tester','rsv-e5');
$releasedRows=$products->reservations($org,$v,$loc,$r['reservation_id'],1,0);
$assert(count($releasedRows)===1 && $releasedRows[0]['status']==='released' && $releasedRows[0]['release_reason']==='Manual order cancelled','Released reservation audit projection');
$replay=$products->releaseStock($org,$r['reservation_id'],'Manual order cancelled',
    'rsv-release-'.$tag,'tester','rsv-e5');
$assert($rel['reservation_id']===$replay['reservation_id']
    &&$rel['status']==='released' && $rel['balance']['reserved']==='0.000000',
    'idempotent full release must preserve original receipt');
$reject(static fn()=> $products->releaseStock($org,$r['reservation_id'],'Different reason',
    'rsv-release-'.$tag,'tester','rsv-e5'),'key reuse with changed payload');
$reject(static fn()=> $products->releaseStock($org,$r['reservation_id'],'Again',
    'rsv-release-again-'.$tag,'tester','rsv-e5'),'released reservation cannot be released twice');
$reject(static fn()=> $products->releaseStock($other,$r['reservation_id'],'Foreign',
    'rsv-release-other-'.$tag,'intruder','rsv-e5'),'tenant cannot release foreign reservation');
$balance=$products->balance($org,$v,$loc);
$assert((string)$balance['on_hand']==='5.000000'
    &&(string)$balance['reserved']==='0.000000'
    &&(string)$balance['available']==='5.000000',
    'release returns available without a stock movement');
$statement=$pdo->prepare('SELECT COUNT(*),SUM(quantity_delta) FROM pm_stock_movements WHERE organization_id=? AND variant_id=?');
$statement->execute([$org,$v]);[$count,$sum]=$statement->fetch(PDO::FETCH_NUM);
$assert((int)$count===1 &&(string)$sum==='5.000000',
    'reservations must not write physical inventory ledger');
$statement=$pdo->prepare('SELECT COUNT(*) FROM pm_stock_reservations WHERE organization_id=?');
$statement->execute([$org]);
$assert((int)$statement->fetchColumn()===1,'only one immutable-origin reservation recorded');
$statement=$pdo->prepare('SELECT type,aggregate_id FROM cos_events WHERE organization_id=? AND type IN (?,?) ORDER BY type');
$statement->execute([$org,'product_management.stock.reserved','product_management.stock.released']);
$events=$statement->fetchAll(PDO::FETCH_ASSOC);
$assert(count($events)===2 &&$events[0]['aggregate_id']===$r['reservation_id']
    &&$events[1]['aggregate_id']===$r['reservation_id'],
    'both events must identify same Reservation aggregate');
$statement=$pdo->prepare('SELECT COUNT(*) FROM cos_event_outbox o JOIN cos_events e ON e.id=o.event_id WHERE e.organization_id=? AND e.type IN (?,?)');
$statement->execute([$org,'product_management.stock.reserved','product_management.stock.released']);
$assert((int)$statement->fetchColumn()===2,'reserve/release must write existing Kernel outbox');
// Approved physical counts: worker-boundary fixture, not a claim of full Plan E2E.
$moduleState=new class implements \Kernel\Module\Contract\ModuleStateRepositoryInterface {
    public bool $enabled=true;
    public function enabledOverride(string $organizationId,string $moduleId):?bool{return $moduleId==='product_management'?$this->enabled:null;}
    public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void{$this->enabled=$enabled;}
};
$catalog=new \Kernel\Module\ModuleCatalog((new \Kernel\Module\ModuleDiscovery($root.'/app/Domains'))->discover());
$modules=new \Kernel\Module\ActiveModuleResolver($catalog,$moduleState);
$handler=new \Domains\ProductManagement\Automation\Action\ProductStockCountApplyHandler($products,$modules);
$makeCount=static function(string $tenant,string $id,string $qty,int $rev,string $source,\Kernel\Action\ActionStatus $status=\Kernel\Action\ActionStatus::Running)use($v,$loc):\Kernel\Action\Action{
    return new \Kernel\Action\Action(bin2hex(random_bytes(16)),$tenant,'product_management.stock.count.apply',
        'product_stock_count',$id,['variant_id'=>$v,'location_id'=>$loc,'counted'=>$qty,'expected_revision'=>$rev,'source_ref'=>$source],
        'USER','42','APPROVAL_REQUIRED','HIGH','wf:'.hash('sha256',$id),new DateTimeImmutable(),$status,'pm-count-test');
};
$revision=(int)$products->balance($org,$v,$loc)['revision'];$countId=bin2hex(random_bytes(16));
$action=$makeCount($org,$countId,'4',$revision,'count:ACT-1');
$assert(!$handler->execute($makeCount($org,$countId,'4',$revision,'count:ACT-1',\Kernel\Action\ActionStatus::PendingApproval))->successful,'Unapproved count rejected');
$moduleState->enabled=false;
$assert(!$handler->execute($action)->successful,'Disabled module rejected');
$moduleState->enabled=true;
$assert(!$handler->execute($makeCount($other,$countId,'4',$revision,'count:ACT-1'))->successful,'Cross tenant count rejected');
$assert(!$handler->execute($makeCount($org,bin2hex(random_bytes(16)),'4',$revision-1,'count:STALE'))->successful,'Stale count rejected');
$assert($products->stockCount($org,$countId)===null,'Denied count did not post');
$assert($handler->execute($action)->successful && $handler->execute($action)->successful,'Count correction and same-key replay');
$count=$products->stockCount($org,$countId);
$assert($count!==null&&$count['delta']==='-1.000000'&&$count['counted']==='4.000000'&&$count['previous_on_hand']==='5.000000'&&$count['movement_id']!==null&&$count['result_revision']===$revision+1,'Count native evidence');
$assert($products->stockCount($other,$countId)===null,'Count read isolation');
$assert(!$handler->execute($makeCount($org,$countId,'3',$revision,'count:ACT-1'))->successful,'Changed payload replay rejected');
$assert(!$handler->execute($makeCount($org,bin2hex(random_bytes(16)),'4',$revision+1,'count:ACT-1'))->successful,'Duplicate source count rejected');
$zeroId=bin2hex(random_bytes(16));$zero=$makeCount($org,$zeroId,'4',$revision+1,'count:ZERO-DIFF');
$assert($handler->execute($zero)->successful,'Zero discrepancy count');
$assert($products->stockCount($org,$zeroId)['movement_id']===null,'No-op count must not create ledger movement');
$res=$products->reserveStock($org,$v,$loc,'3','manual:COUNT-RSV','count-rsv-'.$tag,'42','count-test');
$now=(int)$products->balance($org,$v,$loc)['revision'];
$assert(!$handler->execute($makeCount($org,bin2hex(random_bytes(16)),'2',$now,'count:BELOW-RESERVE'))->successful,'Count below active reservation rejected');
$assert($products->balance($org,$v,$loc)['on_hand']==='4.000000','Rejected count preserved stock');
$products->releaseStock($org,$res['reservation_id'],'Test completed','count-release-'.$tag,'42','count-test');
$now=(int)$products->balance($org,$v,$loc)['revision'];$emptyId=bin2hex(random_bytes(16));
$assert($handler->execute($makeCount($org,$emptyId,'0',$now,'count:EMPTY'))->successful,'Zero physical stock is valid');
$assert($products->balance($org,$v,$loc)['on_hand']==='0.000000','Physical zero applied');
$audit=(new \Domains\ProductManagement\Infrastructure\Persistence\MySqlStockReconciliationAudit($pdo))->audit($org);
$assert($audit['status']==='OK'&&$audit['complete']===true,'Count correction reconciles ledger and active reservations');
$statement=$pdo->prepare('SELECT COUNT(*) FROM cos_event_outbox o JOIN cos_events e ON e.id=o.event_id WHERE e.organization_id=? AND e.type=?');
$statement->execute([$org,'product_management.stock.count_applied']);
$assert((int)$statement->fetchColumn()===3,'Count events/outbox exactly once per confirmed document');
// Session failure after a valid first line must roll back every native change.
$v2=$products->createProduct($org,['name'=>'Second counted SKU','sku'=>'SECOND'.strtoupper($tag),'type'=>'stock'],'count-second-'.$tag,'42','count-test')['variant_id'];
$products->recordMovement($org,$v2,$loc,'5','receipt','session-seed','session-seed-'.$tag,'42','count-test');
$sessionHandler=new \Domains\ProductManagement\Automation\Action\ProductStockCountSessionApplyHandler($products,$modules);
$makeSession=static function(string $tenant,string $id,array $rows,string $source,\Kernel\Action\ActionStatus $status=\Kernel\Action\ActionStatus::Running)use($loc):\Kernel\Action\Action{
    return new \Kernel\Action\Action(bin2hex(random_bytes(16)),$tenant,'product_management.stock.count.session.apply',
        'product_stock_count_session',$id,['location_id'=>$loc,'source_ref'=>$source,'rows'=>$rows],
        'USER','42','APPROVAL_REQUIRED','HIGH','wf:'.hash('sha256',$id),new DateTimeImmutable(),$status,'session-test');
};
$rows=[['variant_id'=>$v,'counted'=>'1','expected_revision'=>(int)$products->balance($org,$v,$loc)['revision']],
       ['variant_id'=>$v2,'counted'=>'4','expected_revision'=>(int)$products->balance($org,$v2,$loc)['revision']]];
$rows=\Domains\ProductManagement\Application\Service\ProductManagementService::countSessionRows($rows);
$badRows=$rows;$badRows[1]['expected_revision']++;
$badId=bin2hex(random_bytes(16));$before1=$products->balance($org,$v,$loc);$before2=$products->balance($org,$v2,$loc);
$assert(!$sessionHandler->execute($makeSession($org,$badId,$badRows,'count:BAD-SESSION'))->successful,'Stale second session row rejected');
$assert($products->countSession($org,$badId)===null&&$products->balance($org,$v,$loc)===$before1&&$products->balance($org,$v2,$loc)===$before2,'Atomic rollback of first row');
$id=bin2hex(random_bytes(16));$action=$makeSession($org,$id,$rows,'count:SESSION-1');
$assert(!$sessionHandler->execute($makeSession($org,$id,$rows,'count:SESSION-1',\Kernel\Action\ActionStatus::PendingApproval))->successful,'Pending session denied');
$assert(!$sessionHandler->execute($makeSession($other,$id,$rows,'count:SESSION-1'))->successful,'Foreign session denied');
$assert(!$sessionHandler->execute($makeSession($org,$id,[$rows[0],$rows[0]],'count:SESSION-1'))->successful,'Duplicate SKU denied');
$assert($sessionHandler->execute($action)->successful&&$sessionHandler->execute($makeSession($org,$id,array_reverse($rows),'count:SESSION-1'))->successful,'Session canonical replay');
$assert($products->countSession($org,$id)['row_count']===2&&$products->countSession($other,$id)===null,'Session evidence isolation');
$assert($products->balance($org,$v,$loc)['on_hand']==='1.000000'&&$products->balance($org,$v2,$loc)['on_hand']==='4.000000','Both count rows applied');
$assert((new \Domains\ProductManagement\Infrastructure\Persistence\MySqlStockReconciliationAudit($pdo))->audit($org)['status']==='OK','Session ledger reconciled');
echo "Product Management E5 reservations, counts and atomic sessions native MySQL acceptance passed.\n";

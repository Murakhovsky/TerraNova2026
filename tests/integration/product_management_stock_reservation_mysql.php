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
echo "Product Management E5 manual reservation/release native MySQL acceptance passed.\n";

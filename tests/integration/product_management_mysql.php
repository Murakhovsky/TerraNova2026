<?php
declare(strict_types=1);

/**
 * Real MySQL acceptance for Product Management. Requires the four E1–E4
 * migrations, COS event and outbox tables, and an isolated CI/dev database.
 * No Shopify network calls or production credentials.
 */
$root=dirname(__DIR__,2);
require (string)(getenv('COS_TEST_AUTOLOAD') ?: $root.'/symfony/vendor/autoload.php');

use Domains\ProductManagement\Application\Service\CsvProductImportService;
use Domains\ProductManagement\Application\Service\PricingService;
use Domains\ProductManagement\Application\Service\ProductManagementService;
use Domains\ProductManagement\Infrastructure\Persistence\MySqlProductManagementStore;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyObservationStore;
use Infrastructure\Platform\Persistence\MySql\Event\MysqlEventStore;

$database=(string)(getenv('DB_DATABASE')?:'cos');
if ($database==='') throw new RuntimeException('Test database missing.');
$pdo=new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        (string)(getenv('DB_HOST')?:'mysql'),(int)(getenv('DB_PORT')?:3306),$database),
    (string)(getenv('DB_USERNAME')?:'cos'),
    (string)(getenv('DB_PASSWORD')?:''),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC],
);
foreach (['pm_products','pm_variants','pm_stock_balances','pm_stock_movements','pm_base_prices',
          'pm_import_batches','pm_external_connections','cos_events','cos_event_outbox'] as $table) {
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $stmt->execute([$table]);
    if ((int)$stmt->fetchColumn()!==1) throw new RuntimeException('Migration not applied: '.$table);
}
$events=new MysqlEventStore($pdo);
$products=new ProductManagementService(new MySqlProductManagementStore($pdo,$events));
$prices=new PricingService($pdo,$events);
$csv=new CsvProductImportService($pdo,$products);
$observations=new ShopifyObservationStore($pdo);
$token=bin2hex(random_bytes(8));
$org='pm-test-'.$token;
$other='pm-other-'.$token;
$assert=static function(bool $condition,string $why):void {
    if (!$condition) throw new RuntimeException('Product Management MySQL: '.$why);
};
$reject=static function(callable $fn,string $why):void {
    try { $fn(); } catch (DomainException|PDOException) { return; }
    throw new RuntimeException('Expected failure: '.$why);
};
$sku='P'.strtoupper($token);
$createKey='pm-create-'.$token;
$created=$products->createProduct($org,['sku'=>$sku,'name'=>'Test product','type'=>'stock'],$createKey,'ci-user','ci-pm');
$assert(isset($created['product_id'],$created['variant_id']),'product must be created');
$again=$products->createProduct($org,['sku'=>$sku,'name'=>'Test product','type'=>'stock'],$createKey,'ci-user','ci-pm');
$assert($again===$created,'exact idempotency replay');
$reject(static fn()=> $products->createProduct($org,['sku'=>$sku,'name'=>'Different','type'=>'stock'],$createKey,'ci-user','ci-pm'),'idempotency payload conflict');
$assert($products->getProduct($other,$created['product_id'])===null,'tenant catalog isolation');
$assert(count($products->searchProducts($org,$sku))===1,'search by SKU');
$reject(static fn()=> $products->createProduct($org,['sku'=>$sku,'name'=>'Duplicate SKU','type'=>'stock'],
    'pm-dup-'.$token,'ci-user','ci-pm'),'duplicate SKU in same tenant');
$otherCreated=$products->createProduct($other,['sku'=>$sku,'name'=>'Other tenant product','type'=>'stock'],
    'pm-other-create-'.$token,'ci-other','ci-pm');
$assert($otherCreated['product_id']!==$created['product_id'],'SKU identity is tenant-local');
$assert(count($products->searchProducts($org,$sku))===1,'other tenant Product hidden from catalog');

$location=$products->createLocation($org,'L-'.$token,'Test warehouse','pm-loc-'.$token,'ci-user','ci-pm');
$variant=$created['variant_id'];$loc=$location['location_id'];
$receipt=$products->recordMovement($org,$variant,$loc,'10.000000','receipt','TEST-PO','pm-in-'.$token,'ci-user','ci-pm');
$replayed=$products->recordMovement($org,$variant,$loc,'10.000000','receipt','TEST-PO','pm-in-'.$token,'ci-user','ci-pm');
$assert($receipt===$replayed,'stock receipt is not duplicated');
$reject(static fn()=> $products->recordMovement($org,$variant,$loc,'-11.000000','issue','TEST-SALE','pm-overdraw-'.$token,'ci-user','ci-pm'),'negative stock');
$products->recordMovement($org,$variant,$loc,'-4.000000','issue','TEST-SALE','pm-out-'.$token,'ci-user','ci-pm');
$balance=$products->balance($org,$variant,$loc);
$assert((string)$balance['on_hand']==='6.000000','on_hand must be 6');
$assert((string)$balance['available']==='6.000000','available must be 6');
$reject(static fn()=> $products->recordMovement($other,$variant,$loc,'2.000000','receipt','TEST-PO-OTHER',
    'pm-cross-'.$token,'ci-other','ci-pm'),'cross-tenant stock write');
$reject(static fn()=> $products->recordMovement($org,$variant,$loc,'-2.000000','adjustment','MISSING-APPROVAL',
    'pm-adjust-'.$token,'ci-user','ci-pm'),'adjustment must be gated outside approved action');

$statement=$pdo->prepare('SELECT COUNT(*),SUM(quantity_delta) FROM pm_stock_movements WHERE organization_id=? AND variant_id=?');
$statement->execute([$org,$variant]);
[$count,$sum]=$statement->fetch(PDO::FETCH_NUM);
$assert((int)$count===2 && (string)$sum==='6.000000','ledger must reconcile projection');
$set=$prices->set($org,$variant,'UAH','129.9900','pm-price-'.$token,'ci-user','ci-pm');
$againPrice=$prices->set($org,$variant,'UAH','129.9900','pm-price-'.$token,'ci-user','ci-pm');
$assert($set===$againPrice && (string)$set['price']==='129.9900','base price idempotency');
$batch=$csv->preview($org,"sku,name,type,uom,track_inventory\nCSV".$token.",Sample,stock,pcs,1\n",'ci-user');
$assert(($batch['counts']['ready']??0)===1 && $batch['state']==='staged','CSV validation-only stage');
$assert(count($products->searchProducts($org,'CSV'.$token))===0,'CSV stage must not write Product');
$connectionId=$observations->provision($org,'pm-unit-test.myshopify.com','shopify_env_primary');
$assert($observations->connection($org,$connectionId)['shop_domain']==='pm-unit-test.myshopify.com','tenant connection');
$reject(static fn()=> $observations->connection($other,$connectionId),'cross tenant connection isolation');
$remoteGid='gid://shopify/Product/123';
$observations->map($org,$connectionId,'product',$remoteGid,$created['product_id']);
$assert($observations->mapping($org,$connectionId,'product',$remoteGid)['canonical_id']===$created['product_id'],'mapping integrity');
$assert($observations->recordVerifiedDelivery($org,$connectionId,'webhook-'.$token,'products/update','{"id":1}'),'new webhook delivery');
$assert(!$observations->recordVerifiedDelivery($org,$connectionId,'webhook-'.$token,'products/update','{"id":1}'),'duplicate webhook is idempotent');
$reject(static fn()=> $observations->recordVerifiedDelivery($org,$connectionId,'webhook-'.$token,'products/update','{"id":2}'),'duplicate webhook different digest');
$stmt=$pdo->prepare('SELECT COUNT(*) FROM cos_events WHERE organization_id=? AND type LIKE ?');
$stmt->execute([$org,'product_management.%']);
$assert((int)$stmt->fetchColumn()===5,'five confirmed native domain events');
$stmt=$pdo->prepare('SELECT actor_type FROM cos_events WHERE organization_id=? AND type=? LIMIT 1');
$stmt->execute([$org,'product_management.product.created']);
$assert($stmt->fetchColumn()==='USER','event actor uses canonical Kernel enum');
$stmt=$pdo->prepare('SELECT COUNT(*) FROM cos_event_outbox o JOIN cos_events e ON e.id=o.event_id WHERE e.organization_id=? AND e.type LIKE ?');
$stmt->execute([$org,'product_management.%']);
$assert((int)$stmt->fetchColumn()===5,'same-transaction event/outbox evidence');
echo "Product Management MySQL E1–E4 acceptance passed: product, ledger, price, CSV, integration inbox.\n";

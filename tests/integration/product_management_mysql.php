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
$assert(
    $again['product_id']===$created['product_id']
    && $again['variant_id']===$created['variant_id']
    && $again['sku']===$created['sku'],
    'product replay must return original native identities'
);
$reject(static fn()=> $products->createProduct($org,['sku'=>$sku,'name'=>'Different','type'=>'stock'],$createKey,'ci-user','ci-pm'),'idempotency payload conflict');
$assert($products->getProduct($other,$created['product_id'])===null,'tenant catalog isolation');
$assert(count($products->searchProducts($org,$sku))===1,'search by SKU');
$ua=$products->upsertProductTranslation($org,$created['product_id'],'uk',
    'Назва товару','Тестовий опис','pm-translation-uk-'.$token,'ci-user','ci-pm');
$uaReplay=$products->upsertProductTranslation($org,$created['product_id'],'uk',
    'Назва товару','Тестовий опис','pm-translation-uk-'.$token,'ci-user','ci-pm');
$assert($ua['locale']===$uaReplay['locale'] && $ua['name']===$uaReplay['name'],
    'Product translation idempotency must retain existing row');
$en=$products->upsertProductTranslation($org,$created['product_id'],'en-US',
    'English product title',null,'pm-translation-en-'.$token,'ci-user','ci-pm');
$assert(count($products->getProduct($org,$created['product_id'])['translations'])===2,
    'Native Product projection must include localized content');
$assert($products->getProduct($other,$created['product_id'])===null,'Cross-tenant translations cannot leak');
$reject(static fn()=> $products->upsertProductTranslation($other,$created['product_id'],'uk',
    'Changed','Other tenant','pm-translation-other-'.$token,'ci-other','ci-pm'),
    'Cross-tenant translation write');
$reject(static fn()=> $products->upsertProductTranslation($org,$created['product_id'],'unknown-LOCALE',
    'Invalid',null,'pm-translation-invalid-'.$token,'ci-user','ci-pm'),
    'Invalid locale must be rejected');

$category=$products->createCategory($org,'Technical Equipment','technical-equipment-'.$token,null,
    'pm-cat-'.$token,'ci-user','ci-pm');
$child=$products->createCategory($org,'Test Components','components-'.$token,$category['category_id'],
    'pm-child-'.$token,'ci-user','ci-pm');
$assert($child['parent_id']===$category['category_id'],'Child category must preserve parent ID');
$assigned=$products->assignCategory($org,$created['product_id'],$child['category_id'],
    'pm-assign-'.$token,'ci-user','ci-pm');
$assert($assigned['product_id']===$created['product_id'],'Product Category assignment must persist');
$assignedAgain=$products->assignCategory($org,$created['product_id'],$child['category_id'],
    'pm-assign-'.$token,'ci-user','ci-pm');
$assert($assignedAgain['category_id']===$child['category_id'],'Idempotent category assignment');
$assert(count($products->getProduct($org,$created['product_id'])['categories'])===1,
    'Product native read must include category membership');
$assert(count($products->categories($org))===2 && $products->categories($other)===[],
    'Tenant category hierarchy isolation');
$catEvent=$pdo->prepare('SELECT aggregate_id FROM cos_events WHERE organization_id=? AND type=? AND aggregate_id=?');
$catEvent->execute([$org,'product_management.category.created',$category['category_id']]);
$assert($catEvent->fetchColumn()===$category['category_id'],'Category event must reference native Category identity');
$reject(static fn()=> $products->assignCategory($other,$created['product_id'],$child['category_id'],
    'pm-cross-cat-'.$token,'ci-other','ci-pm'),'cross-tenant Product/Category assignment');

$reject(static fn()=> $products->createProduct($org,['sku'=>$sku,'name'=>'Duplicate SKU','type'=>'stock'],
    'pm-dup-'.$token,'ci-user','ci-pm'),'duplicate SKU in same tenant');
$otherCreated=$products->createProduct($other,['sku'=>$sku,'name'=>'Other tenant product','type'=>'stock'],
    'pm-other-create-'.$token,'ci-other','ci-pm');
$assert($otherCreated['product_id']!==$created['product_id'],'SKU identity is tenant-local');
$assert(count($products->searchProducts($org,$sku))===1,'other tenant Product hidden from catalog');
$updated=$products->updateProduct($org,$created['product_id'],['name'=>'Renamed Product','brand'=>'COS'],1,
    'pm-update-'.$token,'ci-user','ci-pm');
$assert($updated['revision']===2 && $products->getProduct($org,$created['product_id'])['name']==='Renamed Product',
    'Product update must commit guarded revision');
$reject(static fn()=> $products->updateProduct($org,$created['product_id'],['name'=>'Concurrent edit'],1,
    'pm-stale-'.$token,'ci-user','ci-pm'),'stale Product expected_revision');


$variantChanged=$products->updateVariant($org,$created['variant_id'],
    ['barcode'=>'BAR'.$token,'options'=>[['name'=>'color','value'=>'blue'],['name'=>'size','value'=>'L']]],1,
    'pm-var-update-'.$token,'ci-user','ci-pm');
$assert($variantChanged['revision']===2,'Variant update must increment revision');
$reject(static fn()=> $products->updateVariant($org,$created['variant_id'],['barcode'=>'BAD'],1,
    'pm-var-stale-'.$token,'ci-user','ci-pm'),'stale Variant revision');
$reject(static fn()=> $products->updateVariant($other,$created['variant_id'],['barcode'=>'BAD'],2,
    'pm-var-cross-'.$token,'ci-other','ci-pm'),'cross-tenant Variant update');
$location=$products->createLocation($org,'L-'.$token,'Test warehouse','pm-loc-'.$token,'ci-user','ci-pm');
$locFound=$products->locations($org,'L-'.$token,10,0);
$assert(count($locFound)===1 && $locFound[0]['location_id']===$location['location_id'],
    'tenant warehouse discovery must return the native location');
$assert($products->locations($other,'L-'.$token,10,0)===[],
    'warehouse discovery cannot leak another tenant');

$variant=$created['variant_id'];$loc=$location['location_id'];
$receipt=$products->recordMovement($org,$variant,$loc,'10.000000','receipt','TEST-PO','pm-in-'.$token,'ci-user','ci-pm');
$replayed=$products->recordMovement($org,$variant,$loc,'10.000000','receipt','TEST-PO','pm-in-'.$token,'ci-user','ci-pm');
$assert(
    $receipt['movement_id']===$replayed['movement_id']
    && $receipt['delta']===$replayed['delta']
    && $receipt['balance']['on_hand']===$replayed['balance']['on_hand'],
    'stock replay must return original movement and stock evidence'
);
$reject(static fn()=> $products->recordMovement($org,$variant,$loc,'-11.000000','issue','TEST-SALE','pm-overdraw-'.$token,'ci-user','ci-pm'),'negative stock');
$products->recordMovement($org,$variant,$loc,'-4.000000','issue','TEST-SALE','pm-out-'.$token,'ci-user','ci-pm');
$balance=$products->balance($org,$variant,$loc);
$assert((string)$balance['on_hand']==='6.000000','on_hand must be 6');
$assert((string)$balance['available']==='6.000000','available must be 6');
$reject(static fn()=> $products->updateVariant($org,$variant,['sku'=>'REKEY'.$token],2,
    'pm-var-rekey-'.$token,'ci-user','ci-pm'),'SKU cannot change after stock movements');
$reject(static fn()=> $products->archiveProduct($org,$created['product_id'],2,
    'pm-archive-with-stock-'.$token,'ci-user','ci-pm'),
    'Product with remaining on_hand cannot be archived');
$reject(static fn()=> $products->archiveVariant($org,$variant,2,
    'pm-var-stock-archive-'.$token,'ci-user','ci-pm'),'stocked Variant cannot be archived');

$movements=$products->stockMovements($org,$variant,50,0);
$assert(count($movements)===2 && $movements[0]['variant_id']===$variant,
    'immutable stock movement search must expose both postings');
$assert($products->stockMovements($other,$variant,50,0)===[],'tenant movement isolation');

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
$assert(
    $set['variant_id']===$againPrice['variant_id']
    && $set['revision']===$againPrice['revision']
    && (string)$set['price']===(string)$againPrice['price']
    && (string)$set['price']==='129.9900',
    'base price replay must preserve price revision and amount'
);
$csvPayload="sku,name,type,uom,track_inventory\nCSV".$token.",Sample,stock,pcs,1\n";
$batch=$csv->preview($org,$csvPayload,'ci-user','pm-csv-stage-'.$token);
$againBatch=$csv->preview($org,$csvPayload,'ci-user','pm-csv-stage-'.$token);
$assert($againBatch['batch_id']===$batch['batch_id'] && $againBatch['digest']===$batch['digest'],
    'CSV preview replay must retain one batch and digest');
$reject(static fn()=> $csv->preview($org,$csvPayload."NEW,Another,stock,pcs,1\n",'ci-user','pm-csv-stage-'.$token),
    'CSV preview key reused for changed input');

$assert(($batch['counts']['ready']??0)===1 && $batch['state']==='staged','CSV validation-only stage');
$assert(count($products->searchProducts($org,'CSV'.$token))===0,'CSV stage must not write Product');
$connectionId=$observations->provision($org,'pm-unit-test.myshopify.com','shopify_env_primary');
 // This fixture exercises native local inventory after mapping a Product.
 // Explicit observation_only mode keeps COS the local writer; the default
 // Shopify-authoritative mode must reject these subsequent local mutations.
 $pdo->prepare("UPDATE pm_external_connections SET mode='observation_only' WHERE organization_id=? AND connection_id=?")
     ->execute([$org,$connectionId]);
$assert($observations->connection($org,$connectionId)['shop_domain']==='pm-unit-test.myshopify.com','tenant connection');
$reject(static fn()=> $observations->connection($other,$connectionId),'cross tenant connection isolation');
$remoteGid='gid://shopify/Product/123';
$observations->map($org,$connectionId,'product',$remoteGid,$created['product_id']);
$observations->conflict($org,$connectionId,$remoteGid,'unmapped_variant',['variant'=>'remote-1']);
$observations->conflict($org,$connectionId,$remoteGid,'unmapped_variant',['variant'=>'remote-1']);
$conflictStmt=$pdo->prepare("SELECT COUNT(*) FROM pm_sync_conflicts WHERE organization_id=? AND connection_id=? AND external_gid=? AND state='open'");
$conflictStmt->execute([$org,$connectionId,$remoteGid]);
$assert((int)$conflictStmt->fetchColumn()===1,'repeated Shopify observation must not multiply open conflicts');

$assert($observations->mapping($org,$connectionId,'product',$remoteGid)['canonical_id']===$created['product_id'],'mapping integrity');
$assert($observations->recordVerifiedDelivery($org,$connectionId,'webhook-'.$token,'products/update','{"id":1}'),'new webhook delivery');
$assert(!$observations->recordVerifiedDelivery($org,$connectionId,'webhook-'.$token,'products/update','{"id":1}'),'duplicate webhook is idempotent');
$reject(static fn()=> $observations->recordVerifiedDelivery($org,$connectionId,'webhook-'.$token,'products/update','{"id":2}'),'duplicate webhook different digest');
$products->recordMovement($org,$variant,$loc,'-6.000000','issue','TEST-ZERO-STOCK',
    'pm-zero-'.$token,'ci-user','ci-pm');
$eventIdentity=$pdo->prepare('SELECT COUNT(*) FROM cos_events WHERE organization_id=? AND type=? AND aggregate_id=?');
$eventIdentity->execute([$org,'product_management.variant.updated',$variant]);
$assert((int)$eventIdentity->fetchColumn()===1,'Variant update event must use native Variant aggregate ID');
$target=$products->createLocation($org,'T-'.$token,'Transfer destination',
    'pm-xfer-loc-'.$token,'ci-user','ci-pm');
$dest=$target['location_id'];
$products->recordMovement($org,$variant,$loc,'4.000000','receipt','TRANSFER-SEED',
    'pm-xfer-seed-'.$token,'ci-user','ci-pm');
$tx=$products->transferStock($org,$variant,$loc,$dest,'2.250000','WH-MOVE-1',
    'pm-xfer-'.$token,'ci-user','ci-pm');
$txReplay=$products->transferStock($org,$variant,$loc,$dest,'2.250000','WH-MOVE-1',
    'pm-xfer-'.$token,'ci-user','ci-pm');
$assert($tx['out_movement_id']===$txReplay['out_movement_id'] &&
    $tx['in_movement_id']===$txReplay['in_movement_id'],'transfer replay must preserve same ledger pair');
$assert((string)$products->balance($org,$variant,$loc)['on_hand']==='1.750000' &&
    (string)$products->balance($org,$variant,$dest)['on_hand']==='2.250000',
    'transfer must preserve total exact stock across two warehouses');
$reject(static fn()=> $products->transferStock($org,$variant,$loc,$dest,'2.000000','OVERDRAW',
    'pm-xfer-overdraw-'.$token,'ci-user','ci-pm'),'insufficient source stock');
$reject(static fn()=> $products->transferStock($org,$variant,$loc,$loc,'1','SAME',
    'pm-xfer-same-'.$token,'ci-user','ci-pm'),'transfer to same warehouse');
$reject(static fn()=> $products->transferStock($other,$variant,$loc,$dest,'1','OTHER',
    'pm-xfer-other-'.$token,'ci-other','ci-pm'),'cross-tenant transfer');
$reject(static fn()=> $products->transferStock($org,$variant,$loc,$dest,'1','CHANGED',
    'pm-xfer-'.$token,'ci-user','ci-pm'),'reused idempotency key with different transfer');
$products->recordMovement($org,$variant,$loc,'-1.750000','issue','TRANSFER-DRAIN',
    'pm-xfer-drain-1-'.$token,'ci-user','ci-pm');
$products->recordMovement($org,$variant,$dest,'-2.250000','issue','TRANSFER-DRAIN',
    'pm-xfer-drain-2-'.$token,'ci-user','ci-pm');
$assert((string)$products->balance($org,$variant,$loc)['on_hand']==='0.000000' &&
    (string)$products->balance($org,$variant,$dest)['on_hand']==='0.000000',
    'transfer must leave both warehouse balances zero after drain');
$variantArchived=$products->archiveVariant($org,$variant,2,'pm-var-archive-'.$token,'ci-user','ci-pm');
$assert($variantArchived['revision']===3 && $variantArchived['status']==='archived',
    'Zero-stock Variant may be soft archived');
$reject(static fn()=> $products->updateVariant($org,$variant,['barcode'=>'FAIL'],3,
    'pm-var-archived-update-'.$token,'ci-user','ci-pm'),'Archived Variant is immutable');
$archived=$products->archiveProduct($org,$created['product_id'],2,'pm-archive-'.$token,'ci-user','ci-pm');
$assert($archived['revision']===3 && $archived['status']==='archived','Product archive must preserve state');
$reject(static fn()=> $products->updateProduct($org,$created['product_id'],['name'=>'After archive'],3,
    'pm-edit-archived-'.$token,'ci-user','ci-pm'),'archived Product cannot be edited');
// Exact replay must return the original receipt even after the lifecycle advanced.
$replayedVariant=$products->archiveVariant($org,$variant,2,'pm-var-archive-'.$token,'ci-user','ci-pm');
$assert($replayedVariant===$variantArchived,'Variant archive replay must return the same receipt');
$replayedProduct=$products->archiveProduct($org,$created['product_id'],2,'pm-archive-'.$token,'ci-user','ci-pm');
$assert($replayedProduct===$archived,'Product archive replay must return the same receipt');
$reject(static fn()=> $products->archiveProduct($other,$created['product_id'],2,
    'pm-archive-cross-'.$token,'ci-other','ci-pm'),'cross-tenant Product archive');
$reject(static fn()=> $products->archiveVariant($other,$variant,2,
    'pm-var-archive-cross-'.$token,'ci-other','ci-pm'),'cross-tenant Variant archive');
$reject(static fn()=> $products->recordMovement($org,$variant,$loc,'1','receipt','ARCHIVE-FENCE',
    'pm-after-archive-stock-'.$token,'ci-user','ci-pm'),'Archived Product/Variant cannot receive stock');
$assert((string)$products->balance($org,$variant,$loc)['on_hand']==='0.000000',
    'Archived Variant ledger remains unchanged after denied receipt');
$stmt=$pdo->prepare('SELECT COUNT(*) FROM cos_events WHERE organization_id=? AND type LIKE ?');
$stmt->execute([$org,'product_management.%']);
$assert((int)$stmt->fetchColumn()===20,'twenty confirmed native Domain events');
$stmt=$pdo->prepare('SELECT actor_type FROM cos_events WHERE organization_id=? AND type=? LIMIT 1');
$stmt->execute([$org,'product_management.product.created']);
$assert($stmt->fetchColumn()==='USER','event actor uses canonical Kernel enum');
$stmt=$pdo->prepare('SELECT COUNT(*) FROM cos_event_outbox o JOIN cos_events e ON e.id=o.event_id WHERE e.organization_id=? AND e.type LIKE ?');
$stmt->execute([$org,'product_management.%']);
$assert((int)$stmt->fetchColumn()===20,'same-transaction event/outbox evidence');
echo "Product Management MySQL E1–E4 acceptance passed: product, ledger, price, CSV, integration inbox.\n";

<?php
declare(strict_types=1);

/**
 * E5 P2: simultaneous native writers on independent MySQL sessions.
 * The parent releases a two-process barrier only after both sessions report
 * READY. This tests the real InnoDB CAS/unique receipt invariants, not mocks.
 */
$root=dirname(__DIR__,2);
require (string)(getenv('COS_TEST_AUTOLOAD')?:$root.'/symfony/vendor/autoload.php');

use Domains\ProductManagement\Application\Service\ProductManagementService;
use Domains\ProductManagement\Infrastructure\Persistence\MySqlProductManagementStore;
use Domains\ProductManagement\Infrastructure\Persistence\MySqlStockReconciliationAudit;
use Infrastructure\Platform\Persistence\MySql\Event\MysqlEventStore;

$connect=static fn():PDO=>new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        (string)(getenv('DB_HOST')?:'mysql'),(int)(getenv('DB_PORT')?:3306),
        (string)(getenv('DB_DATABASE')?:'cos')),
    (string)(getenv('DB_USERNAME')?:'cos'),(string)(getenv('DB_PASSWORD')?:''),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC],
);
$service=static function(PDO $pdo):ProductManagementService {
    return new ProductManagementService(new MySqlProductManagementStore($pdo,new MysqlEventStore($pdo)));
};

if (($argv[1]??'')==='worker') {
    // No credentials or other tenants' data are passed in process arguments.
    [,$workerFlag,$mode,$org,$variant,$loc,$qty,$source,$key,$reservationId]=$argv;
    $pdo=$connect();
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=15');
    $products=$service($pdo);
    echo "READY\n";
    fflush(STDOUT);
    if (trim((string)fgets(STDIN))!=='GO') exit(2);
    try {
        $result=$mode==='reserve'
            ? $products->reserveStock($org,$variant,$loc,$qty,$source,$key,'ci-worker','e5-concurrency')
            : $products->releaseStock($org,$reservationId,'Cancel concurrently',$key,'ci-worker','e5-concurrency');
        echo json_encode(['ok'=>true,'reservation_id'=>$result['reservation_id'],'status'=>$result['status']],
            JSON_THROW_ON_ERROR)."\n";
    } catch (Throwable $error) {
        echo json_encode(['ok'=>false,'error'=>get_class($error)],JSON_THROW_ON_ERROR)."\n";
    }
    exit(0);
}

$assert=static function(bool $pass,string $why):void {
    if (!$pass)throw new RuntimeException('E5 concurrent Stock MySQL: '.$why);
};
$pdo=$connect();
$products=$service($pdo);
$audit=new MySqlStockReconciliationAudit($pdo);
$tag=bin2hex(random_bytes(6));
$org='pm-concurrent-'.$tag;
$p=$products->createProduct($org,['name'=>'Concurrent reservation check',
    'sku'=>'CR'.strtoupper($tag),'type'=>'stock'],
    'pm-con-product-'.$tag,'ci-manager','e5-concurrency');
$variant=$p['variant_id'];
$location=$products->createLocation($org,'C-'.$tag,'Test warehouse',
    'pm-con-location-'.$tag,'ci-manager','e5-concurrency')['location_id'];
$products->recordMovement($org,$variant,$location,'5.000000','receipt','TEST-SEED',
    'pm-con-receipt-'.$tag,'ci-manager','e5-concurrency');

$race=static function(array $requests) use ($root,$assert):array {
    if (!function_exists('proc_open')) throw new RuntimeException('CLI proc_open required for concurrent MySQL acceptance.');
    $workers=[];
    foreach ($requests as $args) {
        $pipes=[];
        $process=proc_open(array_merge([PHP_BINARY,__FILE__,'worker'],$args),
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
        $assert(is_resource($process),'Cannot start independent PHP/MySQL writer.');
        $workers[]=['process'=>$process,'pipes'=>$pipes];
    }
    foreach ($workers as $worker) {
        $ready=fgets($worker['pipes'][1]);
        $assert(trim((string)$ready)==='READY','Writer failed to join start barrier.');
    }
    foreach ($workers as $worker) {
        fwrite($worker['pipes'][0],"GO\n");
        fclose($worker['pipes'][0]);
    }
    $results=[];
    foreach ($workers as $worker) {
        $line=fgets($worker['pipes'][1]);
        $stderr=stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exit=proc_close($worker['process']);
        $result=json_decode((string)$line,true);
        $assert($exit===0 && is_array($result) && array_key_exists('ok',$result),
            'Concurrent worker process crashed: '.substr($stderr,0,400));
        $results[]=$result;
    }
    return $results;
};
$initial=$audit->audit($org);
$assert($initial['status']==='OK'&&$initial['complete']===true,'Initial stock audit not clean.');

$pair=$race([
    ['reserve',$org,$variant,$location,'4.000000','manual:WIN-A-'.$tag,'pm-con-a-'.$tag,'-'],
    ['reserve',$org,$variant,$location,'4.000000','manual:WIN-B-'.$tag,'pm-con-b-'.$tag,'-'],
]);
$won=array_values(array_filter($pair,static fn(array $r):bool=>$r['ok']===true));
$lost=array_values(array_filter($pair,static fn(array $r):bool=>$r['ok']===false));
$assert(count($won)===1&&count($lost)===1,'Two concurrent 4/5 reservations cannot both win.');
$winningId=$won[0]['reservation_id'];
$balance=$products->balance($org,$variant,$location);
$assert((string)$balance['on_hand']==='5.000000'
    &&(string)$balance['reserved']==='4.000000'
    &&(string)$balance['available']==='1.000000',
    'Exactly one reservation must persist; failed writer must not leak balance.');
$assert($audit->audit($org)['status']==='OK','Race must not cause ledger/reservation drift.');

$sameSource='manual:ONE-SHARED-'.$tag;
$sameKey='pm-con-shared-'.$tag;
$twins=$race([
    ['reserve',$org,$variant,$location,'1.000000',$sameSource,$sameKey,'-'],
    ['reserve',$org,$variant,$location,'1.000000',$sameSource,$sameKey,'-'],
]);
$assert($twins[0]['ok']&&$twins[1]['ok']
    &&$twins[0]['reservation_id']===$twins[1]['reservation_id'],
    'Concurrent idempotent retries must return one immutable native receipt.');
$sharedId=$twins[0]['reservation_id'];
$assert((string)$products->balance($org,$variant,$location)['reserved']==='5.000000',
    'Concurrent replay must not double reserve one unit.');

$releases=$race([
    ['release',$org,$variant,$location,'0','-', 'pm-con-release-a-'.$tag,$sharedId],
    ['release',$org,$variant,$location,'0','-', 'pm-con-release-b-'.$tag,$sharedId],
]);
$assert(count(array_filter($releases,static fn(array $r):bool=>$r['ok']===true))===1
    &&count(array_filter($releases,static fn(array $r):bool=>$r['ok']===false))===1,
    'Two different concurrent releases must not both apply.');
$assert((string)$products->balance($org,$variant,$location)['reserved']==='4.000000',
    'Concurrent release has to subtract exactly once.');
$assert($audit->audit($org)['status']==='OK','Concurrent native operations must reconcile.');

$pdo->beginTransaction();
try {
    $statement=$pdo->prepare('UPDATE pm_stock_balances SET reserved=reserved-1 WHERE organization_id=? AND variant_id=? AND location_id=?');
    $statement->execute([$org,$variant,$location]);
    $drift=$audit->audit($org);
    $assert($drift['status']==='DRIFT' && $drift['mismatch_count']===1
        && $drift['mismatches'][0]['reservation_matches']===false
        && $drift['automatic_repair_authorized']===false,
        'Read-only audit must flag reservation drift and forbid repair.');
} finally {
    $pdo->rollBack();
}
$assert($audit->audit($org)['status']==='OK','Rollback must restore clean audit.');

$dest=$products->createLocation($org,'D-'.$tag,'Second warehouse',
    'pm-con-dest-'.$tag,'ci-manager','e5-concurrency')['location_id'];
$products->recordMovement($org,$variant,$dest,'1.000000','receipt','TEST-SECOND',
    'pm-con-other-receipt-'.$tag,'ci-manager','e5-concurrency');
$truncated=$audit->audit($org,1);
$assert($truncated['status']==='INCOMPLETE' && $truncated['complete']===false
    && $truncated['checked_pairs']===1,
    'Bounded audits must not claim clean state when more pairs exist.');
$assert($audit->audit($org,2)['status']==='OK',
    'Entire two-location tenant inventory must reconcile.');

$statement=$pdo->prepare('SELECT COUNT(*) FROM pm_stock_reservations WHERE organization_id=?');
$statement->execute([$org]);
$assert((int)$statement->fetchColumn()===2,'Concurrent operations should create exactly two reservation identities.');
$statement=$pdo->prepare('SELECT COUNT(*) FROM cos_events WHERE organization_id=? AND type=?');
$statement->execute([$org,'product_management.stock.reserved']);
$assert((int)$statement->fetchColumn()===2,'Two reservations should create two native events and not four.');
$statement=$pdo->prepare('SELECT COUNT(*) FROM cos_events WHERE organization_id=? AND type=?');
$statement->execute([$org,'product_management.stock.released']);
$assert((int)$statement->fetchColumn()===1,'Concurrent release must create one event.');
$statement=$pdo->prepare('SELECT COUNT(*) FROM pm_stock_movements WHERE organization_id=?');
$statement->execute([$org]);
$assert((int)$statement->fetchColumn()===2,'Reservations must not post any physical Stock movement.');
echo "Product Management E5 concurrent reserve/release and read-only reconciliation MySQL PASSED.\n";

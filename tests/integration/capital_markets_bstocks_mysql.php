<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require (getenv('COS_TEST_AUTOLOAD') ?: $root.'/vendor/autoload.php');

use Domains\CapitalMarkets\Application\Service\BStocksSeedFileReader;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\BStocksSeedImportRepository;

$assert=static function(bool $condition,string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST') ?: '127.0.0.1',(int)(getenv('DB_PORT') ?: 3306),
    getenv('DB_DATABASE') ?: 'cos');
$pdo=new PDO($dsn,getenv('DB_USERNAME') ?: 'cos',getenv('DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$seedPath=getenv('COS_SEED_TEST_CSV') ?: $root.'/resources/capital-markets/universes/binance-bstocks-2026-09-27.csv';
$seed=(new BStocksSeedFileReader())->read($seedPath);
$assert(count($seed['rows'])===80,'Expected the complete 80-row historical universe.');
$org='cm-seed-ci-'.bin2hex(random_bytes(5));
$otherOrg=$org.'-other';
$repository=new BStocksSeedImportRepository($pdo);
try {
    $preview=$repository->preview($org,$seed);
    $assert($preview['new']===80 && $preview['conflicts']===0,'First dry-run must detect 80 new candidates.');
    $count=$pdo->prepare('SELECT COUNT(*) FROM tn_capital_market_seed_import_evidence WHERE organization_id=:org');
    $count->execute(['org'=>$org]);
    $assert((int)$count->fetchColumn()===0,'Dry-run wrote seed rows.');
    $applied=$repository->apply($org,1,$seed);
    $assert($applied['new']===80 && $applied['changed']===0,'Initial seed import failed.');
    $again=$repository->apply($org,1,$seed);
    $assert($again['unchanged']===80 && $again['new']===0,'Repeat import was not idempotent.');
    $count->execute(['org'=>$org]);
    $assert((int)$count->fetchColumn()===80,'Expected exactly 80 persisted rows.');
    $count->execute(['org'=>$otherOrg]);
    $assert((int)$count->fetchColumn()===0,'Foreign tenant inherited seed data.');
    $assert($repository->preview($otherOrg,$seed)['new']===80,'Cross-tenant preview leaked rows.');
    $bad=$seed;
    $bad['rows'][0]['underlying_symbol']='WRONG';
    try {
        $repository->apply($org,1,$bad);
        throw new RuntimeException('Identity conflict incorrectly applied.');
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(),'conflict'),'Wrong error on identity conflict.');
    }
    $older=$seed;
    $older['source_as_of']='2020-01-01T00:00:00Z';
    try {
        $repository->apply($org,1,$older);
        throw new RuntimeException('Older source incorrectly replaced evidence.');
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(),'conflict'),'Wrong error on stale-source conflict.');
    }
    $count->execute(['org'=>$org]);
    $assert((int)$count->fetchColumn()===80,'Conflict damaged persisted seed universe.');
    $batch=$pdo->prepare('SELECT COUNT(*) FROM tn_capital_market_seed_import_batches WHERE organization_id=:org');
    $batch->execute(['org'=>$org]);
    $assert((int)$batch->fetchColumn()===1,'Repeated file should retain one immutable batch record.');
    echo "MySQL seed import: 80 inserted, 80 unchanged, tenant isolation, rollback and conflicts passed.\n";
} finally {
    foreach (['tn_capital_market_seed_import_batches','tn_capital_market_seed_import_evidence'] as $table) {
        $pdo->prepare('DELETE FROM '.$table.' WHERE organization_id IN (:org,:other)')
            ->execute(['org'=>$org,'other'=>$otherOrg]);
    }
}

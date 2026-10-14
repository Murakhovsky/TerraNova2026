<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require (getenv('COS_TEST_AUTOLOAD') ?: $root.'/vendor/autoload.php');

use Domains\CapitalMarkets\Application\Service\BStocksSeedFileReader;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\BStocksSeedImportRepository;
use Domains\CapitalMarkets\Application\Service\CrossVenuePoolProjection;
use Domains\CapitalMarkets\Application\Service\CrossVenueActiveUniverse;
use Domains\CapitalMarkets\Application\Service\CrossVenueUniverse;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\CrossVenuePoolCandidateRepository;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\CrossVenueBboAdmissionFence;

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
    $candidates=new CrossVenuePoolCandidateRepository($pdo,new CrossVenuePoolProjection(),new CrossVenueBboAdmissionFence($pdo));
    $snapshot=[
        'dataset'=>'cross_venue_candidate_discovery','as_of_utc'=>'2026-10-14T20:00:00Z',
        'rows'=>[['candidate'=>['token'=>'AAPLB','underlying'=>'AAPL'],
            'venues'=>[
                ['venue'=>'BINANCE','status'=>'LISTED_EXACT_TOKEN_SYMBOL',
                    'symbol'=>'AAPLBUSDT','quote'=>'USDT'],
                ['venue'=>'BYBIT','status'=>'UNAVAILABLE'],
                ['venue'=>'KRAKEN','status'=>'NOT_LISTED','symbol'=>null],
            ],
        ]],
    ];
    $assert($candidates->reconcile($org,$snapshot)===2,'Unavailable source must not create a false listing.');
    $assert($candidates->reconcile($org,$snapshot)===2,'Repeat candidate reconciliation failed.');
    $summary=$candidates->counts($org);
    $assert(count($summary)===2,'Expected exact listed and not-listed observations.');
    $pdo->prepare("UPDATE tn_capital_market_cross_venue_pool_candidates SET verification_state='VERIFIED',
      economic_equivalence='VERIFIED' WHERE organization_id=:org AND venue='BINANCE'")
      ->execute(['org'=>$org]);
    $changed=$snapshot;
    $changed['rows'][0]['venues'][0]=['venue'=>'BINANCE','status'=>'NOT_LISTED',
        'symbol'=>null,'quote'=>null];
    $candidates->reconcile($org,$changed);
    $check=$pdo->prepare("SELECT verification_state,economic_equivalence,listing_state
      FROM tn_capital_market_cross_venue_pool_candidates
      WHERE organization_id=:org AND venue='BINANCE'");
    $check->execute(['org'=>$org]);
    $current=$check->fetch(PDO::FETCH_ASSOC);
    $assert($current['verification_state']==='UNVERIFIED'
        && $current['economic_equivalence']==='NOT_ESTABLISHED'
        && $current['listing_state']==='NOT_LISTED','Listing drift did not revoke stale verification.');
    // Quote-currency drift is economic identity drift, not an innocent listing refresh.
    $quoteDrift=$snapshot;
    $quoteDrift['as_of_utc']='2026-10-14T20:02:00Z';
    $quoteDrift['rows'][0]['venues'][0]['quote']='USD';
    $pdo->prepare("UPDATE tn_capital_market_cross_venue_pool_candidates
        SET verification_state='VERIFIED',economic_equivalence='VERIFIED'
        WHERE organization_id=:org AND venue='BINANCE'")->execute(['org'=>$org]);
    $candidates->reconcile($org,$quoteDrift);
    $verifyCurrency=$pdo->prepare("SELECT verification_state,economic_equivalence,quote_currency,source_as_of
        FROM tn_capital_market_cross_venue_pool_candidates
        WHERE organization_id=:org AND venue='BINANCE'");
    $verifyCurrency->execute(['org'=>$org]);
    $changedCurrency=$verifyCurrency->fetch(PDO::FETCH_ASSOC);
    $assert($changedCurrency['quote_currency']==='USD'
        && $changedCurrency['verification_state']==='UNVERIFIED'
        && $changedCurrency['economic_equivalence']==='NOT_ESTABLISHED',
        'Changed quote currency retained unsafe verification.');
    // Slower network response from an earlier scan cannot roll back a newer observation.
    $older=$snapshot;
    $older['as_of_utc']='2026-10-14T20:01:00Z';
    $candidates->reconcile($org,$older);
    $verifyCurrency->execute(['org'=>$org]);
    $assert($verifyCurrency->fetch(PDO::FETCH_ASSOC)===$changedCurrency,
        'Out-of-order discovery result overwrote newer candidate evidence.');
    $againDown=$changed;
    $againDown['rows'][0]['venues'][0]=['venue'=>'BINANCE','status'=>'UNAVAILABLE'];
    $candidates->reconcile($org,$againDown);
    $verifyCurrency->execute(['org'=>$org]);
    $assert($verifyCurrency->fetch(PDO::FETCH_ASSOC)===$changedCurrency,
        'Provider outage replaced last-known verified time and currency.');
    $active=new CrossVenueActiveUniverse($repository,new CrossVenueUniverse());
    $bootstrap=$active->load($otherOrg,$seedPath);
    $assert($bootstrap['origin']==='BOOTSTRAP_CSV' && count($bootstrap['rows'])===80,
        'Empty tenant must use bundled historical bootstrap, not another tenant import.');
    $imported=$active->load($org,$seedPath);
    $assert($imported['origin']==='IMPORTED_MYSQL' && count($imported['rows'])===80,
        'Manual and scheduled discovery must switch to imported tenant evidence.');
    $assert(count(array_filter($imported['rows'],
        static fn(array $row):bool=>$row['risk_class']==='LEVERAGED_OR_INVERSE'))===9,
        'Imported universe must preserve negative-leverage inverse ETF classification.');

    $extended=$seed;
    $extended['rows'][]=[
        'underlying_symbol'=>'TEST','token_symbol'=>'TESTB','venue_symbol'=>'TESTBUSDT',
        'declared_status'=>null,'exchange_url'=>null,'issuer_or_instrument_name'=>'Test Reference',
        'declared_instrument_type'=>'Stock','declared_leverage'=>1,
        'risk_class'=>'STANDARD_CANDIDATE',
    ];
    $extended['sha256']=hash('sha256','ci-extended-universe-'.$org);
    $extendedResult=$repository->apply($org,1,$extended);
    $assert($extendedResult['new']===1,'Extended seed import must add exactly one instrument.');
    $universe=$active->load($org,$seedPath);
    $assert($universe['origin']==='IMPORTED_MYSQL' && count($universe['rows'])===81
        && count(array_filter($universe['rows'],static fn(array $row):bool=>
            $row['token']==='TESTB' && $row['market']==='TESTBUSDT'))===1,
        'Imported symbol was missing from future scheduled universe.');
    $assert(count($active->load($otherOrg,$seedPath)['rows'])===80,
        'Newly imported token leaked into another tenant.');
    echo "MySQL seed import: 80 inserted, 80 unchanged, tenant isolation, rollback and conflicts passed.\n";
} finally {
    foreach (['tn_capital_market_seed_import_batches','tn_capital_market_seed_import_evidence','tn_capital_market_cross_venue_pool_candidates'] as $table) {
        $pdo->prepare('DELETE FROM '.$table.' WHERE organization_id IN (:org,:other)')
            ->execute(['org'=>$org,'other'=>$otherOrg]);
    }
}

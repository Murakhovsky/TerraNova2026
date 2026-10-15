<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$load=static function(string $path)use($root):string{
    $data=file_get_contents($root.'/'.$path);
    if(!is_string($data)||$data==='')throw new RuntimeException('Missing Instrument Pool contract: '.$path);
    return $data;
};
$sql=$load('app/migrations/20261015_000141_capital_markets_instrument_pool_snapshots.sql');
$repo=$load('app/Domains/CapitalMarkets/Infrastructure/Persistence/MySql/MysqlInstrumentPoolSnapshotRepository.php');
$cmd=$load('symfony/src/Command/CapitalMarketsInstrumentPoolSnapshotCommand.php');
$config=$load('symfony/config/services.yaml');
$runtime=$load('.github/workflows/runtime.yml');
$module=$load('app/Domains/CapitalMarkets/module.php');
foreach(['tn_capital_market_instrument_pool_snapshots','tn_capital_market_instrument_pool_scan_gates',
    'PRIMARY KEY(organization_id,provider,venue_id)','REFERENCES tn_capital_market_venues',
    'results_json JSON NOT NULL'] as $required){
    if(!str_contains($sql,$required))throw new RuntimeException('Pool SQL invariant missing: '.$required);
}
foreach(['INSERT IGNORE','TIMESTAMPDIFF(SECOND','reserveScan(','dry_run','executable',
    'ORDER BY observed_at DESC,id DESC LIMIT 1','UNAVAILABLE'] as $required){
    if(!str_contains($repo,$required))throw new RuntimeException('Pool persistence invariant missing: '.$required);
}
foreach(['COS_CM_INSTRUMENT_POOL_SNAPSHOT_ENABLED','reserveScan(','->record(',
    'actor-id','INSTRUMENT_POOL_SNAPSHOT_DISABLED'] as $required){
    if(!str_contains($cmd,$required))throw new RuntimeException('Pool CLI safety guard missing: '.$required);
}
if(!str_contains($config,'MysqlInstrumentPoolSnapshotRepository:')
    ||!str_contains($runtime,'capital_markets_instrument_pool_snapshot_mysql.php')
    ||!str_contains($module,'20261015_000141_capital_markets_instrument_pool_snapshots.sql')){
    throw new RuntimeException('Instrument Pool snapshot not integrated with Runtime and Module.');
}
echo "Instrument Pool audited snapshot safety contracts passed.\n";

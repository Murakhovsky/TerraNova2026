<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static function(string $path)use($root):string{
    $data=file_get_contents($root.'/'.$path);
    if(!is_string($data))throw new RuntimeException('Missing release control '.$path);
    return $data;
};
$command=$read('symfony/src/Command/CapitalMarketsInstrumentPoolProductionCommand.php');
$reader=$read('app/Domains/CapitalMarkets/Application/Service/InstrumentPoolProductionEvidenceReader.php');
$proof=$read('app/Domains/CapitalMarkets/Application/Service/InstrumentPoolExternalEvidence.php');
$policy=$read('app/Domains/CapitalMarkets/Application/Service/InstrumentPoolProductionSoakPolicy.php');
$compose=$read('docker-compose.yml');
$schema=$read('app/migrations/20261015_000144_capital_markets_pool_production.sql');
foreach(['COS_CM_POOL_PRODUCTION_ARMED','CANARY','SOAK','ACCEPTED','LIVE',
    'ROLLED_BACK','canaryHealthy','->report(','->setSourceEnabled(',
    'fencing_token=fencing_token+1','capital_markets.market_data.manage',
    'DISABLED','UNTRUSTED','INSERT IGNORE INTO tn_capital_market_pool_production_control',
    'Canary source changed during lock acquisition','24h LIVE 250 BBO + 50 L2'] as $part){
    if(!str_contains($command,$part))throw new RuntimeException('Release command guard missing '.$part);
}
foreach(['source_id IN (__SOURCE_PLACEHOLDERS__)','tn_capital_market_canonical_events',
    'tn_capital_market_quality_metrics','tn_capital_market_stream_worker_leases',
    'tn_capital_market_pool_stream_assignments',
    'tn_capital_market_pool_soak_checkpoints',
    'ALL_SUPERVISED_SOURCES_OBSERVED','mode','ISSUER_IDENTITY'] as $part){
    if(!str_contains($reader,$part))throw new RuntimeException('Production MySQL evidence missing '.$part);
}
foreach(['instrument_pool.approve','instrument_pool.propose',
    "'reviewed_by'", "'submitted_by'", "status='APPROVED'",
    'function revoke(', 'ORDER BY kind,subject_id,id DESC','PENDING'] as $part){
    if(!str_contains($proof,$part)){
        throw new RuntimeException('Independent attestation and revocation contract missing '.$part);
    }
}
if(!str_contains($reader,'function recordSoakCheckpoint(')
    ||!str_contains($reader,'function soakCheckpoints(')
    ||!str_contains($command,'RECORDING_LIVE_OBSERVATIONS')){
    throw new RuntimeException('Durable watchdog checkpoint evidence gate missing.');
}
if(!str_contains($schema,'reviewed_by <> submitted_by')
    ||!str_contains($schema,'tn_capital_market_pool_stream_assignments')
    ||!str_contains($command,"'assign-shard'")){
    throw new RuntimeException('Independent proof and explicit worker sharding schema missing.');
}
if(!str_contains($proof,"'https'")||!str_contains($policy,"'BBO_250'")
    ||!str_contains($policy,"'L2_50'"))throw new RuntimeException('Strict production gates missing.');
if(!str_contains($compose,'profiles: [cm-pool-watchdog]')
    ||!str_contains($compose,'sleep 300')
    ||!str_contains($compose,'COS_CM_POOL_WATCHDOG_ACTOR_ID')
    ||!str_contains($compose,'COS_CM_POOL_PRODUCTION_ARMED')
    ||!str_contains($schema,'tn_capital_market_pool_production_control')){
    throw new RuntimeException('Production flag/config/schema not installed.');
}
echo "Production Instrument Pool guarded release boundaries passed.\n";

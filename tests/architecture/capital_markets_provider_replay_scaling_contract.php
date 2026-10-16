<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$read=static function(string $p)use($root):string{
    $s=file_get_contents($root.'/'.$p);
    if(!is_string($s)||$s==='')throw new RuntimeException('Missing P1/P2 file: '.$p);
    return $s;
};
$m=$read('app/Domains/CapitalMarkets/module.php');
$cfg=$read('symfony/config/services.yaml');
$schema=$read('app/migrations/20261015_000148_capital_markets_replay_scaling.sql');
$raw=$read('app/Domains/CapitalMarkets/Infrastructure/Persistence/MySql/MysqlRawMarketEventRepository.php');
$replay=$read('app/Domains/CapitalMarkets/Application/Service/RawMarketReplaySimulator.php');
$audit=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/MysqlRawMarketProvenance.php');
$policy=$read('app/Domains/CapitalMarkets/Application/Service/CrossVenueBboConsensus.php');
$venueReader=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/MysqlCrossVenueBboReconciliation.php');
if(!str_contains($venueReader,"proof.kind='SOURCE_ENTITLEMENT'")
    ||!str_contains($venueReader,"proof.status='APPROVED'")
    ||!str_contains($venueReader,'VALIDATION_SOURCE')){
    throw new RuntimeException('Cross-venue fallback bypasses independently approved data entitlements.');
}

$placement=$read('app/Domains/CapitalMarkets/Application/Service/StreamingFleetPlacementPolicy.php');
$fleet=$read('symfony/src/Command/CapitalMarketsMultiSymbolFleetCommand.php');
$rest=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Adapter/Okx/OkxSpotMarketDataAdapter.php');
$cli=$read('symfony/src/Command/CapitalMarketsStreamingEvidenceCommand.php');
foreach(['ix_cm_cross_venue_latest','ix_cm_reconcile_active_instrument',
    'ALGORITHM=INPLACE','LOCK=NONE'] as $index){
    if(!str_contains($schema,$index))throw new RuntimeException('Missing online cross-venue query index '.$index);
}
foreach(['raw_provenance','venue_reconciliation'] as $table){
    if(!str_contains($schema,$table))throw new RuntimeException('Missing P1/P2 MySQL schema: '.$table);
}
if(!str_contains($m,'20261015_000148_capital_markets_replay_scaling')
    ||!str_contains($cfg,'OkxSpotMarketDataAdapter')
    ||!str_contains($cfg,'OkxSpotMarketDataDecoder')
    ||!str_contains($cfg,'MysqlRawMarketProvenance')){
    throw new RuntimeException('P1/P2 services or migration not in canonical manifest.');
}
if(!str_contains($raw,'$this->provenance->record(')
    ||!str_contains($raw,'$this->connection->beginTransaction()')
    ||!str_contains($audit,'hash_equals(')
    ||!str_contains($audit,'READ_ONLY_NO_LIVE_PUBLICATION')
    ||!str_contains($replay,'MarketDataMode::Replay')
    ||!str_contains($replay,'no_execution_or_opportunity_side_effects')
    ||!str_contains($replay,'REPLAY_PARTIAL')
    ||!str_contains($replay,'NO_EVIDENCE')
    ||!str_contains($read('app/Domains/CapitalMarkets/Domain/MarketData/RawMarketEvent.php'),'assertSafeTransportMetadata')){

    throw new RuntimeException('Raw market provenance not coupled atomically or REPLAY not isolated.');
}
if(!str_contains($policy,"'for_execution'=>false")
    ||!str_contains($policy,"'CURRENCY_MISMATCH'")
    ||!str_contains($policy,"'NO_QUORUM'")
    ||!str_contains($rest,'www.okx.com')
    ||!str_contains($rest,'OkxPublicPayloadParser')){
    throw new RuntimeException('OKX Spot/backup cross-venue trading safety boundaries missing.');
}
if(!str_contains($placement,'topology_digest')
    ||!str_contains($placement,'ESTIMATE_NOT_INVOICE')
    ||!str_contains($fleet,'COS_CM_STREAMING_FLEET_TOPOLOGY_DIGEST')
    ||!str_contains($fleet,'$selected')
    ||!str_contains($cli,'MarketDataReplayManage')){
    throw new RuntimeException('Distributed source-placement pinning/authorization absent.');
}
echo "Provider expansion, raw provenance/read-only replay and budgeted distributed fleet contracts passed.\n";

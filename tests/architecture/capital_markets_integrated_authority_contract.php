<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static function(string $p)use($root):string{
    $c=file_get_contents($root.'/'.$p);
    if(!is_string($c)||$c==='')throw new RuntimeException('Missing integrated COS authority: '.$p);
    return $c;
};
$module=$read('app/Domains/CapitalMarkets/module.php');
$controller=$read('symfony/src/Command/CapitalMarketsInstrumentPoolProductionCommand.php');
$evidence=$read('app/Domains/CapitalMarkets/Application/Service/InstrumentPoolExternalEvidence.php');
$reader=$read('app/Domains/CapitalMarkets/Application/Service/InstrumentPoolProductionEvidenceReader.php');
$gateway=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/StreamingFencedIngestionGateway.php');
$l2=$read('symfony/src/Command/CapitalMarketsL2StreamCommand.php');
$publisher=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/L2IngestionPublisher.php');
$policy=$read('app/Domains/CapitalMarkets/Application/Service/InstrumentPoolProductionSoakPolicy.php');
$patterns=[
    '20261015_000144_capital_markets_pool_production.sql',
    '20261015_000145_capital_markets_instrument_stream_trust.sql',
    '20261015_000146_capital_markets_provider_ops.sql',
    '20261015_000147_capital_markets_derivatives_stream.sql',
    '20261015_000148_capital_markets_replay_scaling.sql',
    '20261016_000149_capital_markets_pool_evidence_scope.sql',
];
foreach($patterns as $p){
    if(substr_count($module,$p)!==1)throw new RuntimeException('Noncanonical migration registry: '.$p);
}
foreach([
    'app/Domains/CapitalMarkets/Application/Service/InstrumentPoolProductionReadiness.php',
    'app/Domains/CapitalMarkets/Application/Service/InstrumentPoolExternalAttestations.php',
    'app/migrations/20261015_000144_capital_markets_production_attestations.sql',
] as $p){
    if(file_exists($root.'/'.$p))throw new RuntimeException('Duplicate production authority: '.$p);
}
foreach([
    "cos:capital-markets:pool:production"=>$controller,
    "tn_capital_market_pool_external_evidence"=>$evidence,
    'approvedDigests('=>$evidence,
    'InstrumentPoolIssuerE4Policy::valid('=>$reader,
    'MASSIVE_NBBO_ENTITLEMENT'=>$evidence,
    'VENUE_ENTITLEMENT'=>$reader,
    '->ingest('=>$gateway,
    'L2SourceFencedSession'=>$l2,
    'COS_CM_L2_LEGACY_VPS_QA_ENABLED'=>$l2,
    'A production L2 publisher must have a source-fenced writer.'=>$publisher,
    'five_minute_buckets'=>$policy,
    '24H_ELAPSED'=>$policy,
] as $needle=>$haystack){
    if(!str_contains($haystack,$needle))throw new RuntimeException('Integrated authority contract absent: '.$needle);
}
if(str_contains($controller,'cos:capital-markets:instrument-pool:production')
    ||str_contains($module,'production_attestations.sql')){
    throw new RuntimeException('Noncanonical duplicate operator/ledger.');
}
echo "Federation/Pool/L2/Production single-authority contract passed.\n";

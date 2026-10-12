<?php
declare(strict_types=1);

/**
 * Static cross-layer invariants, plus the executable provider polling unit
 * case. Real two-connection MySQL deadlock/ABA tests remain a staging gate.
 */
$root=dirname(__DIR__,2);
$read=static function(string $path)use($root):string{
    $contents=file_get_contents($root.'/'.$path);
    if(!is_string($contents)||$contents===''){
        throw new RuntimeException('Missing REST/WS source-writer artifact '.$path);
    }
    return $contents;
};
$gate=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/MysqlMarketSourceWriterGate.php');
$lease=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/MysqlStreamingWorkerLease.php');
$sweeper=$read('app/Domains/CapitalMarkets/Infrastructure/MarketData/Streaming/MysqlBinanceStreamingExpiredLeaseSweeper.php');
$poll=$read('app/Domains/CapitalMarkets/Application/Service/MarketSourcePollingService.php');
$di=$read('symfony/config/services.yaml');
$unit=$read('tests/unit/capital_markets_market_intelligence_polling.php');
foreach(['__source_writer_gate__','INSERT IGNORE','FOR UPDATE','->transactional(',
    'MarketSourceStreamingActiveException','expires_at>UTC_TIMESTAMP(6)'] as $required){
    if(!str_contains($gate,$required)){
        throw new RuntimeException('Missing source guard invariant: '.$required);
    }
}
foreach([$lease,$sweeper] as $component){
    if(!str_contains($component,'MysqlMarketSourceWriterGate::lockSource(')){
        throw new RuntimeException('Stream lease or dead-worker sweeper bypasses source guard.');
    }
}
foreach(['->executeRest(','STREAMING_ACTIVE','MarketSourceRestWriterGateInterface',
    'catch(MarketSourceStreamingActiveException)'] as $required){
    if(!str_contains($poll,$required)){
        throw new RuntimeException('REST bypasses writer gate: '.$required);
    }
}
if(substr_count($poll,'->executeRest(')!==2){
    throw new RuntimeException('Both successful and failing REST writes must be guarded.');
}
foreach(['MysqlMarketSourceWriterGate:','MarketSourceRestWriterGateInterface:'] as $required){
    if(!str_contains($di,$required))throw new RuntimeException('Missing container binding '.$required);
}
foreach(['streamingActive=true','STREAMING_ACTIVE','->health('] as $required){
    if(!str_contains($unit,$required))throw new RuntimeException('Missing polling race unit case '.$required);
}
echo "Capital Markets source-level REST/WS write arbitration contracts passed.\n";

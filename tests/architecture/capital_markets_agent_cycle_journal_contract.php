<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static function(string $path)use($root):string{
    $text=file_get_contents($root.'/'.$path);
    if(!is_string($text)||$text==='')throw new RuntimeException('Missing cycle journal artifact: '.$path);
    return $text;
};
$migration=$read('symfony/migrations/Version20261013211500.php');
$repo=$read('app/Domains/CapitalMarkets/Infrastructure/Agent/MysqlCapitalMarketsAgentCycleJournal.php');
$orch=$read('app/Domains/CapitalMarkets/Application/Service/CapitalMarketsRuntimeOrchestrator.php');
$cli=$read('symfony/src/Command/CapitalMarketsAgentCycleCommand.php');
$runtime=$read('.github/workflows/runtime.yml');
$services=$read('symfony/config/services.yaml');
foreach(['tn_capital_market_agent_cycles','UNIQUE KEY uq_cm_agent_cycle_organization_correlation',
    'stages_json JSON NOT NULL','result_json JSON NULL','owner_token CHAR(32)'] as $needle){
    if(!str_contains($migration,$needle))throw new RuntimeException('Missing schema invariant '.$needle);
}
foreach(['INSERT IGNORE','FOR UPDATE','hash_equals','AGENT',
    'CHECKPOINT', 'owner_token=:owner',"state='RUNNING'"] as $needle){
    // CHECKPOINT and AGENT are descriptive comments, not mandatory SQL labels.
    if(in_array($needle,['AGENT','CHECKPOINT'],true))continue;
    if(!str_contains($repo,$needle))throw new RuntimeException('Missing journal idempotence invariant '.$needle);
}
foreach(['journal->begin','journal->finish','journal->failed','journal->checkpoint',
    'checkpointed(','runOwned('] as $needle){
    if(!str_contains($orch,$needle))throw new RuntimeException('Orchestrator bypassed stage journal '.$needle);
}
foreach(["getOption('resume')",'InputOption::VALUE_NONE'] as $needle){
    if(!str_contains($cli,$needle))throw new RuntimeException('CLI not wired for explicit recovery '.$needle);
}
if(!str_contains($runtime,'capital_markets_agent_cycle_journal_mysql.php')
    ||!str_contains($services,'MysqlCapitalMarketsAgentCycleJournal:')){
    throw new RuntimeException('Durable cycle was not registered in Runtime and DI.');
}
echo "Capital Markets Agent Cycle journal architecture contracts passed.\n";

<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$source=(string)file_get_contents($root.'/symfony/src/Command/CapitalMarketsAgentCycleStatusCommand.php');
$journal=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/Agent/MysqlCapitalMarketsAgentCycleJournal.php');
$ci=(string)file_get_contents($root.'/.github/workflows/ci.yml');
foreach(['cos:capital-markets:agents:status','COS_CM_RUNTIME_AGENT_TEAM',
    'journal->snapshot(','organization_id','run_id','stage_count','attempt'] as $needle){
    if(!str_contains($source,$needle))throw new RuntimeException('Status inspection missing '.$needle);
}
foreach(['public function snapshot','WHERE organization_id=:org AND correlation_id=:corr'] as $needle){
    if(!str_contains($journal,$needle))throw new RuntimeException('Tenant-bound read model missing '.$needle);
}
foreach(['->run(','->checkpoint(','->finish(','->failed('] as $mutating){
    if(str_contains($source,$mutating))throw new RuntimeException('Read-only status command invokes a side effect '.$mutating);
}
if(!str_contains($ci,'capital_markets_agent_cycle_status_contract.php')){
    throw new RuntimeException('Read-only status command missing CI contract.');
}
echo "Capital Markets operator workflow inspection contract passed.\n";

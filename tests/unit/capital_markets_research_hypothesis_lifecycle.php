<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\CapitalMarkets\Domain\Research\HypothesisLifecyclePolicy;

$policy=new HypothesisLifecyclePolicy();
$policy->assertTransition('IDEA','DRAFT');
$policy->assertTransition('DRAFT','READY_FOR_RESEARCH');
$policy->assertTransition('READY_FOR_RESEARCH','RESEARCHING');
$policy->assertTransition('REJECTED','DRAFT');

foreach([['DRAFT','PAPER'],['ARCHIVED','DRAFT'],['REJECTED','VALIDATED']] as [$from,$to]){
    try{
        $policy->assertTransition($from,$to);
        throw new RuntimeException('Unsupported lifecycle transition accepted: '.$from.' -> '.$to);
    }catch(InvalidArgumentException){}
}

$root=dirname(__DIR__,2);
$repository=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/Persistence/MySql/MysqlResearchLabRepository.php');
$service=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/ResearchLabService.php');
$controller=(string)file_get_contents($root.'/symfony/src/Http/Api/V1/Controller/CapitalMarketsResearchLabController.php');
$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
$migration=(string)file_get_contents($root.'/app/migrations/20261006_000133_capital_markets_research_lab.sql');

foreach([
    [$repository,"'revision'"],
    [$migration,'uq_cm_research_hypothesis_revision'],
    [$service,'reviseHypothesis('],
    [$service,'assertTransition('],
    [$controller,'reviseHypothesis('],
    [$routes,'/api/v1/capital-markets/research/hypotheses/{id}/revisions'],
] as [$body,$needle]){
    if(!str_contains($body,$needle))throw new RuntimeException('Hypothesis revision contract missing: '.$needle);
}

echo "Research hypothesis revision lifecycle passed.\n";

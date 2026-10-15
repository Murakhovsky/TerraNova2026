<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$get=static function(string $path)use($root):string{
    $s=file_get_contents($root.'/'.$path);
    if(!is_string($s)||$s==='')throw new RuntimeException('Missing Instrument Pool approval artifact '.$path);
    return $s;
};
$migration=$get('app/migrations/20261015_000142_capital_markets_instrument_pool_approval.sql');
$service=$get('app/Domains/CapitalMarkets/Application/Service/InstrumentPoolApprovalService.php');
$routes=$get('symfony/config/routes.yaml');
$controller=$get('symfony/src/Web/CapitalMarkets/CapitalMarketsPageController.php');
$twig=$get('symfony/templates/experience/capital_markets/workspace.html.twig');
$yaml=$get('symfony/config/services.yaml');
$manifest=$get('app/Domains/CapitalMarkets/module.php');
$owner=$get('app/Infrastructure/Platform/Persistence/TableOwnership.php');
foreach(['tn_capital_market_instrument_pool_changes','UNIQUE KEY uq_cm_pool_proposal',
    'fk_cm_pool_change_snapshot','CHECK(reviewed_by IS NULL OR reviewed_by <> proposed_by)',
    "'capital_markets.instrument_pool.approve'", "'capital_markets.instrument_pool.propose'"] as $needle){
    if(!str_contains($migration,$needle))throw new RuntimeException('Unsafe governance migration '.$needle);
}
foreach(['function propose(','function decide(','function queue(','capital_markets.instrument_pool.approve',
    'capital_markets.instrument_pool.propose','capital_markets.instrument_pool.view',
    'if((int)$change[\'proposed_by\']===$actor)',
    "WHERE organization_id=:org AND id=:id FOR UPDATE",
    "'PENDING'","'APPLIED'","'REJECTED'",
    'evidence_digest','latestHealthy(','assertProposable(','$this->transactions->transactional(',
    'registerVenueInstrument(new RegisterVenueInstrument','createInstrument(new CreateInstrument',
    "'status'=>'SUSPENDED'",'Three consecutive healthy full-mapping observations required.',
    "['ACTIVE','SUSPENDED']",'tn_capital_market_venue_instruments',
    'AND status=:previous',"InstrumentPoolMappingStatusChanged"] as $needle){
    if(!str_contains($service,$needle))throw new RuntimeException('Instrument Pool approval invariant missing '.$needle);
}
foreach(['/capital-markets/instrument-pool','proposeInstrumentPoolChange','decideInstrumentPoolChange'] as $needle){
    if(!str_contains($routes,$needle)||!str_contains($controller,$needle)){
        throw new RuntimeException('Approval UI route unavailable '.$needle);
    }
}
foreach(['canProposeInstrumentPool','canApproveInstrumentPool','instrument_pool'] as $needle){
    if(!str_contains($twig,$needle))throw new RuntimeException('Approval UI missing '.$needle);
}
if(!str_contains($yaml,'InstrumentPoolApprovalService:')
    ||!str_contains($manifest,'20261015_000142_capital_markets_instrument_pool_approval.sql')
    ||!str_contains($owner,'tn_capital_market_instrument_pool_changes')){
    throw new RuntimeException('Approval domain ownership or autowiring missing.');
}
echo "Instrument Pool approved import and no-self-approval contracts passed.\n";

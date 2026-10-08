<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Application\Service;
use DateTimeImmutable;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsTradingRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\CapitalRiskRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use Domains\CapitalMarkets\Domain\Allocation\AllocationPolicy;
use Domains\CapitalMarkets\Domain\Portfolio\PortfolioRiskState;
use Domains\CapitalMarkets\Domain\Risk\RiskEnvelope;
use Domains\CapitalMarkets\Domain\Risk\RiskEnvelopeLevel;
use Domains\CapitalMarkets\Domain\Risk\RiskLimit;
use Domains\CapitalMarkets\Domain\Risk\RiskLimitType;
use Domains\CapitalMarkets\Domain\Service\CapitalAllocationEngine;
use Domains\CapitalMarkets\Domain\Service\EconomicExposureEngine;
use Domains\CapitalMarkets\Domain\Service\PortfolioStressEngine;
use Domains\CapitalMarkets\Domain\Service\PortfolioRiskEngine;
use Domains\CapitalMarkets\Domain\Service\PortfolioRebalanceEngine;
use Domains\CapitalMarkets\Domain\Stress\PortfolioStressScenario;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use RuntimeException;

final readonly class CapitalRiskService
{
 public function __construct(
  private CapitalMarketsTradingRepositoryInterface $trading,
  private ResearchLabRepositoryInterface $research,
  private CapitalRiskRepositoryInterface $repository,
  private EconomicExposureEngine $exposure,
  private CapitalAllocationEngine $allocator,
  private PortfolioStressEngine $stress,
  private PortfolioRiskEngine $portfolioRisk,
  private PortfolioRebalanceEngine $rebalance,
 ){}

 public function workspace(string $organizationId,string $portfolioId='paper-master'):array
 {
  $portfolio=$this->trading->paperPortfolio($organizationId)??[];
  return [
   'portfolio'=>$portfolio,
   'positions'=>$this->trading->listPositions($organizationId,1000),
   'balances'=>$this->trading->listPaperBalances($organizationId),
   'opportunities'=>$this->trading->listOpportunities($organizationId,200),
   'exposure'=>$this->repository->latestExposureSnapshot($organizationId,$portfolioId),
   'risk'=>$this->repository->latestRiskSnapshot($organizationId,$portfolioId),
   'risk_envelope'=>$this->repository->latestRiskEnvelope($organizationId,$portfolioId),
   'allocation_plan'=>$this->repository->latestAllocationPlan($organizationId,$portfolioId),
   'strategy_allocations'=>$this->repository->listStrategyAllocations($organizationId,$portfolioId),
   'rebalance'=>$this->repository->latestRebalancePlan($organizationId,$portfolioId),
   'correlation'=>$this->repository->latestCorrelationSnapshot($organizationId,$portfolioId),
   'stress_results'=>$this->repository->listStressResults($organizationId,$portfolioId,20),
  ];
 }

 public function refreshExposure(string $organizationId,string $portfolioId='paper-master'):array
 {
  $positions=[];
  foreach($this->trading->listPositions($organizationId,1000) as $p){
   $payload=is_array($p['payload']??null)?$p['payload']:$p;
   $quantity=(string)($payload['quantity']??$p['quantity']??'0');
   $mark=(string)($payload['mark_price']??$payload['markPrice']??$p['mark_price']??'0');
   $notional=DecimalMath::multiply(Decimal::fromString($quantity),Decimal::fromString($mark))->value();
   $positions[]=[
    'instrument_id'=>(string)($p['instrument_id']??$payload['instrument_id']??'unknown'),
    'underlying_key'=>(string)($payload['underlying_key']??$payload['underlying']??''),
    'notional'=>$notional,
    'side'=>(string)($payload['side']??$p['side']??'LONG'),
    'venue'=>(string)($p['venue_id']??$payload['venue_id']??''),
    'strategy'=>(string)($p['strategy_id']??$payload['strategy_id']??''),
    'asset'=>(string)($payload['asset']??$payload['symbol']??''),
    'currency'=>(string)($payload['currency']??$payload['quote_asset']??'USD'),
    'counterparty'=>(string)($payload['counterparty']??$p['venue_id']??''),
    'chain'=>(string)($payload['chain']??''),
    'liquidity_bucket'=>(string)($payload['liquidity_bucket']??'UNKNOWN'),
    'relationship_valid'=>(bool)($payload['relationship_valid']??($payload['underlying_key']??null)!==null),
    'delta'=>(string)($payload['delta']??'1'),
   ];
  }
  $snapshot=$this->exposure->snapshot($portfolioId,$positions);
  $record=[
   'snapshot_id'=>'exp-'.bin2hex(random_bytes(10)),
   'portfolio_id'=>$portfolioId,
   'created_at'=>$snapshot->timestamp->format('Y-m-d H:i:s.u'),
   'gross_exposure'=>$snapshot->grossExposure->value(),
   'net_exposure'=>$snapshot->netExposure->value(),
   'by_underlying'=>$this->decimalMap($snapshot->byUnderlying),
   'by_asset'=>$this->decimalMap($snapshot->byAsset),
   'by_venue'=>$this->decimalMap($snapshot->byVenue),
   'by_strategy'=>$this->decimalMap($snapshot->byStrategy),
   'by_currency'=>$this->decimalMap($snapshot->byCurrency),
   'by_counterparty'=>$this->decimalMap($snapshot->byCounterparty),
   'by_chain'=>$this->decimalMap($snapshot->byChain),
   'by_liquidity_bucket'=>$this->decimalMap($snapshot->byLiquidityBucket),
   'unknown_exposure'=>$snapshot->unknownExposure,
  ];
  $this->repository->saveExposureSnapshot($organizationId,$record);
  return $record;
 }

 public function simulateOpportunityImpact(string $organizationId,string $opportunityId,string $capital,string $portfolioId='paper-master'):array
 {
  $opportunity=$this->trading->getOpportunity($organizationId,$opportunityId)??throw new RuntimeException('Opportunity not found.');
  $current=$this->repository->latestExposureSnapshot($organizationId,$portfolioId)??$this->refreshExposure($organizationId,$portfolioId);
  $amount=Decimal::fromString($capital);
  if(!$amount->isPositive())throw new RuntimeException('Simulation capital must be positive.');
  $portfolio=$this->trading->paperPortfolio($organizationId)??[];
  $available=Decimal::fromString((string)($portfolio['available_capital']??'0'));
  $after=DecimalMath::subtract($available,$amount);
  $venues=(array)($current['by_venue']??[]);
  $venue=(string)($opportunity['venue_id']??$opportunity['buy_venue_id']??$opportunity['venue']??'');
  if($venue!==''){
   $venues[$venue]=DecimalMath::add(Decimal::fromString((string)($venues[$venue]??'0')),$amount)->value();
  }
  $strategy=(string)($opportunity['strategy_version']??$opportunity['strategy_id']??'unknown');
  $strategies=(array)($current['by_strategy']??[]);
  $strategies[$strategy]=DecimalMath::add(Decimal::fromString((string)($strategies[$strategy]??'0')),$amount)->value();
  $decision=$after->isNegative()?'REJECT':'ACCEPT';
  $reasons=$after->isNegative()?['INSUFFICIENT_AVAILABLE_CAPITAL']:[];
  if(strtoupper((string)($opportunity['valuation_quality']??'TRUSTED'))!=='TRUSTED'){
   $decision='MANUAL_REVIEW';$reasons[]='VALUATION_QUALITY_DEGRADED';
  }
  return [
   'opportunity_id'=>$opportunityId,
   'capital_required'=>$amount->value(),
   'capital_after'=>$after->value(),
   'gross_exposure_change'=>$amount->value(),
   'net_exposure_change'=>(string)($opportunity['net_exposure_change']??$amount->value()),
   'venue_exposure_after'=>$venues,
   'strategy_exposure_after'=>$strategies,
   'asset_exposure_after'=>(array)($current['by_asset']??[]),
   'margin_change'=>(string)($opportunity['margin_change']??'0'),
   'liquidity_change'=>(string)($opportunity['liquidity_change']??'0'),
   'risk_score_change'=>(string)($opportunity['risk_score_change']??'0'),
   'correlation_effect'=>(string)($opportunity['correlation_effect']??'0'),
   'decision'=>$decision,
   'reasons'=>$reasons,
  ];
 }

 public function recalculateAllocation(string $organizationId,array $input,string $portfolioId='paper-master'):array
 {
  $portfolio=$this->trading->paperPortfolio($organizationId)??throw new RuntimeException('Paper portfolio is not initialized.');
  $available=Decimal::fromString((string)($input['available_capital']??$portfolio['available_capital']??'0'));
  foreach(['minimum_cash_buffer','emergency_hedge_buffer','settlement_buffer'] as $buffer){
   $value=Decimal::fromString((string)($input[$buffer]??'0'));
   $available=DecimalMath::subtract($available,$value);
  }
  if($available->isNegative())$available=Decimal::fromString('0');

  $mode=strtoupper((string)($input['mode']??'BALANCED'));
  $version=(string)($input['policy_version']??'v1');
  $policy=new AllocationPolicy('policy-'.$mode.'-'.$version,$version,'SCORE_BASED',$mode,['score_multiplier'=>'1'],(array)($input['constraints']??[]));
  $state=PortfolioRiskState::tryFrom(strtoupper((string)($input['risk_state']??'NORMAL')))??PortfolioRiskState::Normal;
  $opportunities=$this->prepareOpportunities($organizationId,(array)($input['opportunities']??[]),(string)($input['portfolio_mode']??'PAPER'));

  $plan=$this->allocator->allocate($portfolioId,$available,$opportunities,$policy,$state,(array)($input['hard_caps']??[]));
  $record=[
   'plan_id'=>$plan->id,
   'portfolio_id'=>$portfolioId,
   'created_at'=>$plan->createdAt->format('Y-m-d H:i:s.u'),
   'capital_available'=>$plan->capitalAvailable->value(),
   'allocations'=>array_map(fn($i)=>[
    'strategy_version_id'=>$i->strategyVersionId,
    'opportunity_id'=>$i->opportunityId,
    'requested_capital'=>$i->requestedCapital->value(),
    'approved_capital'=>$i->approvedCapital->value(),
    'priority'=>$i->priority,
    'expected_net_return'=>$i->expectedNetReturn->value(),
    'expected_value'=>$i->expectedValue->value(),
    'capacity'=>$i->capacity->value(),
    'decision'=>$i->decision,
    'reason'=>$i->reason,
    'risk_budget'=>$i->riskBudget,
   ],$plan->allocations),
   'reservations'=>[],
   'expected_return'=>$plan->expectedReturn->value(),
   'expected_risk'=>$plan->expectedRisk,
   'expected_liquidity'=>$plan->expectedLiquidity,
   'expected_drawdown'=>$plan->expectedDrawdown->value(),
   'constraints'=>$plan->constraints,
   'reasoning_summary'=>$plan->reasoningSummary,
   'status'=>$plan->status,
   'policy_version'=>$plan->policyVersion,
   'input_fingerprint'=>$plan->inputFingerprint,
   'proposal_actor_type'=>strtoupper((string)($input['proposal_actor_type']??'HUMAN')),
   'proposal_actor_id'=>(string)($input['proposal_actor_id']??''),
  ];
  $this->repository->saveAllocationPlan($organizationId,$record);
  return $record;
 }

 public function approveAndReserve(string $organizationId,string $planId,string $actorId):array
 {
  $plan=$this->repository->getAllocationPlan($organizationId,$planId)??throw new RuntimeException('Allocation plan not found.');
  if(strtoupper((string)($plan['proposal_actor_type']??''))==='AGENT'&&(string)($plan['proposal_actor_id']??'')===$actorId){
   throw new RuntimeException('NO_SELF_APPROVAL');
  }
  if((string)($plan['status']??'')==='APPROVED'){
   return ['approved'=>true,'idempotent'=>true,'reservations'=>$plan['reservations']??[]];
  }
  if((string)($plan['status']??'')!=='PROPOSED')throw new RuntimeException('Allocation plan is not approvable.');

  $expires=(new DateTimeImmutable('+15 minutes'))->format(DATE_ATOM);
  $reservationRows=[];$created=[];
  foreach((array)($plan['allocations']??[]) as $item){
   if(!in_array((string)($item['decision']??''),['ACCEPT','ACCEPT_REDUCED_SIZE'],true))continue;
   $amount=Decimal::fromString((string)($item['approved_capital']??'0'));
   if(!$amount->isPositive())continue;
   $opportunityId=(string)($item['opportunity_id']??'');
   $reservationId='cm_alloc_res_'.substr(hash('sha256',$planId.'|'.$opportunityId),0,40);
   $existing=$this->trading->getCapitalReservation($organizationId,$reservationId);
   if($existing!==null){
    if(!in_array((string)($existing['status']??''),['RESERVED','CONSUMED'],true))throw new RuntimeException('Existing allocation reservation is not active.');
    $reservationRows[]=$existing;continue;
   }
   if(!$this->trading->reserveCapital($organizationId,$reservationId,$opportunityId,$amount->value(),$expires)){
    foreach($created as $id)$this->trading->releaseReservation($organizationId,$id);
    throw new RuntimeException('CAPITAL_RESERVATION_FAILED');
   }
   $created[]=$reservationId;
   $reservationRows[]=['reservation_id'=>$reservationId,'opportunity_id'=>$opportunityId,'amount'=>$amount->value(),'status'=>'RESERVED','expires_at'=>$expires];
  }

  if(!$this->repository->approveAllocation($organizationId,$planId,$actorId,gmdate('Y-m-d H:i:s'))){
   $fresh=$this->repository->getAllocationPlan($organizationId,$planId);
   if((string)($fresh['status']??'')!=='APPROVED')throw new RuntimeException('Allocation approval race detected.');
  }
  return ['approved'=>true,'idempotent'=>false,'reservations'=>$reservationRows];
 }

 public function saveRiskEnvelope(string $organizationId,array $input,string $portfolioId='paper-master'):array
 {
  $version=(string)($input['version']??'v1');
  $limits=(array)($input['limits']??[]);
  if($limits===[])throw new RuntimeException('Risk envelope requires limits.');
  $record=[
   'envelope_id'=>'risk-env-'.substr(hash('sha256',$portfolioId.'|'.$version.'|'.json_encode($limits)),0,24),
   'portfolio_id'=>$portfolioId,
   'version'=>$version,
   'status'=>'ACTIVE',
   'limits'=>$limits,
   'created_at'=>gmdate('Y-m-d H:i:s'),
  ];
  $this->repository->saveRiskEnvelope($organizationId,$record);
  return $record;
 }

 public function refreshRisk(string $organizationId,array $input=[],string $portfolioId='paper-master'):array
 {
  $portfolio=$this->trading->paperPortfolio($organizationId)??[];
  $exposure=$this->repository->latestExposureSnapshot($organizationId,$portfolioId)??$this->refreshExposure($organizationId,$portfolioId);
  $envelopeRecord=$this->repository->latestRiskEnvelope($organizationId,$portfolioId);
  if($envelopeRecord===null)throw new RuntimeException('Risk envelope is not configured.');

  $equity=Decimal::fromString((string)($input['equity']??$portfolio['initial_capital']??'0'));
  $gross=Decimal::fromString((string)($exposure['gross_exposure']??'0'));
  $net=Decimal::fromString((string)($exposure['net_exposure']??'0'));
  $leverage=$equity->isZero()?Decimal::fromString('0'):DecimalMath::divide($gross,$equity);
  $dailyLoss=DecimalMath::abs(Decimal::fromString((string)($input['daily_loss']??'0')));
  $drawdown=Decimal::fromString((string)($input['drawdown']??'0'));
  $margin=Decimal::fromString((string)($input['margin_utilization']??'0'));

  $limits=[];
  foreach((array)($envelopeRecord['limits']??[]) as $row){
   if(!is_array($row))continue;
   $limits[]=new RiskLimit(
    (string)($row['metric']??''),
    RiskEnvelopeLevel::tryFrom(strtoupper((string)($row['level']??'PORTFOLIO')))??RiskEnvelopeLevel::Portfolio,
    RiskLimitType::tryFrom(strtoupper((string)($row['type']??'ABSOLUTE')))??RiskLimitType::Absolute,
    Decimal::fromString((string)($row['limit']??'0')),
    isset($row['scope_id'])?(string)$row['scope_id']:null,
    (bool)($row['hard']??strtoupper((string)($row['type']??''))==='HARD')
   );
  }
  $envelope=new RiskEnvelope((string)$envelopeRecord['envelope_id'],$portfolioId,(string)$envelopeRecord['version'],$limits);
  $metrics=[
   'gross_exposure'=>$gross,'net_exposure'=>DecimalMath::abs($net),'leverage'=>$leverage,
   'drawdown'=>$drawdown,'daily_loss'=>$dailyLoss,'margin_utilization'=>$margin,
   'available_capital'=>Decimal::fromString((string)($portfolio['available_capital']??'0')),
  ];
  $assessment=$this->portfolioRisk->assess($envelope,$metrics,PortfolioRiskState::tryFrom(strtoupper((string)($input['current_state']??'NORMAL')))??PortfolioRiskState::Normal);
  $headroom=[];foreach($assessment['headroom'] as $key=>$h)$headroom[$key]=['metric'=>$h->metric,'current'=>$h->current->value(),'limit'=>$h->limit->value(),'headroom'=>$h->headroom->value(),'utilization'=>$h->utilization->value(),'hard'=>$h->hard,'breached'=>$h->breached];

  $record=[
   'snapshot_id'=>'risk-'.bin2hex(random_bytes(10)),'portfolio_id'=>$portfolioId,'created_at'=>gmdate('Y-m-d H:i:s'),
   'equity'=>$equity->value(),'gross_exposure'=>$gross->value(),'net_exposure'=>$net->value(),'leverage'=>$leverage->value(),
   'drawdown'=>$drawdown->value(),'daily_pnl'=>(string)($input['daily_pnl']??'0'),'margin_utilization'=>$margin->value(),
   'risk_limit_utilization'=>$headroom,'breaches'=>$assessment['breaches'],'warnings'=>$assessment['warnings'],
   'status'=>$assessment['state']->value,'valuation_quality'=>(string)($input['valuation_quality']??'TRUSTED'),
  ];
  $this->repository->saveRiskSnapshot($organizationId,$record);
  return $record;
 }

 public function proposeRebalance(string $organizationId,array $input,string $portfolioId='paper-master'):array
 {
  $cost=Decimal::fromString((string)($input['estimated_cost']??'0'));
  $returnImpact=Decimal::fromString((string)($input['expected_return_impact']??'0'));
  $threshold=Decimal::fromString((string)($input['rebalance_threshold']??'0.05'));
  $plan=$this->rebalance->propose($portfolioId,(array)($input['current_allocation']??[]),(array)($input['target_allocation']??[]),$cost,(int)($input['risk_improvement_pct']??0),$returnImpact,$threshold);
  if($plan->decision==='REBALANCE'&&isset($input['expected_benefit'])){
   $benefit=Decimal::fromString((string)$input['expected_benefit']);
   if($cost->compareTo($benefit)>0){
    $record=['plan_id'=>$plan->id,'portfolio_id'=>$portfolioId,'current_allocation'=>$plan->currentAllocation,'target_allocation'=>$plan->targetAllocation,'actions'=>[],'estimated_costs'=>$cost->value(),'expected_risk_improvement_pct'=>$plan->expectedRiskImprovementPct,'expected_return_impact'=>$returnImpact->value(),'status'=>'HOLD','reason'=>'Rebalance costs exceed expected benefit.','created_at'=>gmdate('Y-m-d H:i:s')];
    $this->repository->saveRebalancePlan($organizationId,$record);return $record;
   }
  }
  $record=['plan_id'=>$plan->id,'portfolio_id'=>$portfolioId,'current_allocation'=>$plan->currentAllocation,'target_allocation'=>$plan->targetAllocation,'actions'=>$plan->actions,'estimated_costs'=>$plan->estimatedCosts->value(),'expected_risk_improvement_pct'=>$plan->expectedRiskImprovementPct,'expected_return_impact'=>$plan->expectedReturnImpact->value(),'status'=>$plan->decision,'reason'=>$plan->reason,'created_at'=>gmdate('Y-m-d H:i:s')];
  $this->repository->saveRebalancePlan($organizationId,$record);return $record;
 }

 public function runStress(string $organizationId,array $input,string $portfolioId='paper-master'):array
 {
  $portfolio=$this->trading->paperPortfolio($organizationId)??[];
  $capital=Decimal::fromString((string)($portfolio['equity']??$portfolio['capital']??$portfolio['initial_capital']??'0'));
  $positions=[];
  foreach($this->trading->listPositions($organizationId,1000) as $p){
   $payload=is_array($p['payload']??null)?$p['payload']:$p;
   $q=Decimal::fromString((string)($payload['quantity']??'0'));
   $m=Decimal::fromString((string)($payload['mark_price']??'0'));
   $positions[]=[
    'position_id'=>(string)($p['position_id']??''),
    'asset'=>(string)($payload['asset']??$payload['symbol']??''),
    'venue'=>(string)($p['venue_id']??''),
    'notional'=>DecimalMath::multiply($q,$m)->value()
   ];
  }
  $scenario=new PortfolioStressScenario((string)($input['scenario_id']??'custom'),(string)($input['name']??'Custom scenario'),(array)($input['shocks']??[]));
  $result=$this->stress->run($scenario,$capital,$positions);
  $record=[
   'result_id'=>'stress-'.bin2hex(random_bytes(10)),
   'portfolio_id'=>$portfolioId,
   'created_at'=>(new DateTimeImmutable())->format('Y-m-d H:i:s.u'),
   'scenario_id'=>$result->scenarioId,
   'estimated_loss'=>$result->estimatedLoss->value(),
   'margin_impact'=>$result->marginImpact->value(),
   'positions_affected'=>$result->positionsAffected,
   'venues_affected'=>$result->venuesAffected,
   'risk_limits_breached'=>$result->riskLimitsBreached,
   'capital_remaining'=>$result->capitalRemaining->value()
  ];
  $this->repository->saveStressResult($organizationId,$record);
  return $record;
 }

 private function prepareOpportunities(string $organizationId,array $opportunities,string $portfolioMode):array
 {
  $balances=$this->trading->listPaperBalances($organizationId);
  $availableByLocation=[];
  foreach($balances as $row)$availableByLocation[(string)$row['venue_id'].'|'.(string)$row['asset_key']]=Decimal::fromString((string)($row['available_amount']??'0'));

  foreach($opportunities as &$opportunity){
   if(!is_array($opportunity))continue;
   $blocked='';
   $valuation=strtoupper((string)($opportunity['valuation_quality']??'TRUSTED'));
   if(in_array($valuation,['STALE','UNKNOWN','DEGRADED'],true))$blocked='STALE_OR_UNTRUSTED_VALUATION';
   $strategyStatus=strtoupper((string)($opportunity['strategy_status']??'PAPER'));
   if(strtoupper($portfolioMode)==='LIVE'&&!in_array($strategyStatus,['LIMITED_LIVE','LIVE','SCALE'],true))$blocked='STRATEGY_NOT_LIVE_VALIDATED';
   foreach((array)($opportunity['capital_locations']??[]) as $location){
    if(!is_array($location))continue;
    $key=(string)($location['venue_id']??'').'|'.(string)($location['asset_key']??'');
    $needed=Decimal::fromString((string)($location['required_amount']??'0'));
    $available=$availableByLocation[$key]??Decimal::fromString('0');
    if($needed->compareTo($available)>0){$blocked='INSUFFICIENT_LOCAL_CAPITAL';break;}
   }
   if($blocked!=='')$opportunity['blocked_reason']=$blocked;
  }
  unset($opportunity);
  return $opportunities;
 }

 private function decimalMap(array $values):array
 {
  $out=[];foreach($values as $k=>$v)$out[$k]=$v instanceof Decimal?$v->value():(string)$v;return $out;
 }
}

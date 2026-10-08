<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Application\Service;
use DateTimeImmutable;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsTradingRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\CapitalRiskRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsEventPublisherInterface;
use Domains\CapitalMarkets\Domain\Event\CapitalRiskLifecycleEvent;
use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use Domains\CapitalMarkets\Domain\Allocation\AllocationPolicy;
use Domains\CapitalMarkets\Domain\Portfolio\PortfolioRiskState;
use Domains\CapitalMarkets\Domain\Observability\CapitalMarketsAlertType;
use Domains\CapitalMarkets\Domain\Risk\RiskEnvelope;
use Domains\CapitalMarkets\Domain\Risk\RiskEnvelopeLevel;
use Domains\CapitalMarkets\Domain\Risk\RiskLimit;
use Domains\CapitalMarkets\Domain\Risk\RiskLimitType;
use Domains\CapitalMarkets\Domain\Service\CapitalAllocationEngine;
use Domains\CapitalMarkets\Domain\Service\EconomicExposureEngine;
use Domains\CapitalMarkets\Domain\Service\PortfolioStressEngine;
use Domains\CapitalMarkets\Domain\Service\PortfolioRiskEngine;
use Domains\CapitalMarkets\Domain\Service\PortfolioRebalanceEngine;
use Domains\CapitalMarkets\Domain\Service\LiquidityCapacityEngine;
use Domains\CapitalMarkets\Domain\Service\DrawdownEngine;
use Domains\CapitalMarkets\Domain\Service\CapitalStateEngine;
use Domains\CapitalMarkets\Domain\Service\CorrelationEngine;
use Domains\CapitalMarkets\Domain\Service\MarginAggregationEngine;
use Domains\CapitalMarkets\Domain\Service\LiquidationClusterEngine;
use Domains\CapitalMarkets\Domain\Risk\LiquidityBudget;
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
  private LiquidityCapacityEngine $liquidity,
  private DrawdownEngine $drawdowns,
  private CapitalStateEngine $capitalStates,
  private TokenizedEquityReadService $tokenizedRead,
  private CorrelationEngine $correlations,
  private CapitalRiskTelemetry $telemetry,
  private CapitalMarketsEventPublisherInterface $events,
  private MarginAggregationEngine $margins,
  private LiquidationClusterEngine $liquidationClusters,
 ){}

 public function workspace(string $organizationId,string $portfolioId='paper-master'):array
 {
  $portfolio=$this->trading->paperPortfolio($organizationId)??[];
  $capitalState=$this->capitalState($organizationId,$portfolioId);
  $this->telemetry->metric($organizationId,'portfolio_equity',(string)($capitalState['total']??'0'));
  $this->telemetry->metric($organizationId,'available_capital',(string)($capitalState['available']??'0'));
  $this->telemetry->metric($organizationId,'deployed_capital',(string)($capitalState['deployed']??'0'));
  $this->telemetry->metric($organizationId,'reserved_capital',(string)($capitalState['reserved']??'0'));
  return [
   'portfolio'=>$portfolio,
   'capital_state'=>$capitalState,
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

 public function capitalState(string $organizationId,string $portfolioId='paper-master',array $buffers=[]):array
 {
  $portfolio=$this->trading->paperPortfolio($organizationId)??[];
  $availableRaw=Decimal::fromString((string)($portfolio['available_capital']??'0'));
  $reservedRaw=Decimal::fromString((string)($portfolio['reserved_capital']??'0'));
  $reservations=$this->trading->listCapitalReservations($organizationId,'RESERVED',5000);
  $executions=$this->trading->listExecutions($organizationId,5000);
  $statusByOpportunity=[];
  foreach($executions as $execution)$statusByOpportunity[(string)($execution['opportunity_id']??'')]=(string)($execution['status']??'');

  $deployed=Decimal::fromString('0');$unsettled=Decimal::fromString('0');
  foreach($reservations as $reservation){
   $amount=Decimal::fromString((string)($reservation['amount']??'0'));
   $status=$statusByOpportunity[(string)($reservation['opportunity_id']??'')]??'';
   if(in_array($status,['OPEN'],true))$deployed=DecimalMath::add($deployed,$amount);
   elseif(in_array($status,['READY','PARTIALLY_EXECUTED','EXECUTING','COMPENSATING'],true))$unsettled=DecimalMath::add($unsettled,$amount);
  }
  $pending=DecimalMath::subtract($reservedRaw,DecimalMath::add($deployed,$unsettled));if($pending->isNegative())$pending=Decimal::fromString('0');
  $total=DecimalMath::add($availableRaw,$reservedRaw);
  $allocated=Decimal::fromString('0');
  foreach($this->repository->listStrategyAllocations($organizationId,$portfolioId) as $row)$allocated=DecimalMath::add($allocated,Decimal::fromString((string)($row['allocated_capital']??'0')));

  $locations=[];
  foreach($this->trading->listPaperBalances($organizationId) as $row)$locations[]=[
   'venue_id'=>(string)$row['venue_id'],'asset_key'=>(string)$row['asset_key'],
   'available'=>(string)($row['available_amount']??'0'),'reserved'=>(string)($row['reserved_amount']??'0')
  ];
  $snapshot=$this->capitalStates->snapshot(
   $portfolioId,$total,$allocated,$pending,$deployed,Decimal::fromString('0'),Decimal::fromString('0'),$unsettled,
   Decimal::fromString((string)($buffers['minimum_cash_buffer']??'0')),
   Decimal::fromString((string)($buffers['emergency_hedge_buffer']??'0')),
   Decimal::fromString((string)($buffers['settlement_buffer']??'0')),$locations
  );
  return [
   'portfolio_id'=>$snapshot->portfolioId,'timestamp'=>$snapshot->timestamp->format(DATE_ATOM),
   'total'=>$snapshot->total->value(),'available'=>$snapshot->available->value(),'allocated'=>$snapshot->allocated->value(),
   'reserved'=>$snapshot->reserved->value(),'deployed'=>$snapshot->deployed->value(),'locked'=>$snapshot->locked->value(),
   'margined'=>$snapshot->margined->value(),'unsettled'=>$snapshot->unsettled->value(),
   'minimum_cash_buffer'=>$snapshot->minimumCashBuffer->value(),'emergency_hedge_buffer'=>$snapshot->emergencyHedgeBuffer->value(),
   'settlement_buffer'=>$snapshot->settlementBuffer->value(),'by_location'=>$snapshot->byLocation,
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
    'instrument_family'=>(string)($payload['instrument_family']??$payload['family']??''),
    'issuer'=>(string)($payload['issuer']??$payload['issuer_reference']??''),
    'sector'=>(string)($payload['sector']??''),
    'jurisdiction'=>(string)($payload['jurisdiction']??$payload['country']??''),
    'collateral'=>(string)($payload['collateral']??$payload['collateral_asset']??''),
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
   'by_instrument_family'=>$this->decimalMap($snapshot->byInstrumentFamily),
   'by_issuer'=>$this->decimalMap($snapshot->byIssuer),
   'by_sector'=>$this->decimalMap($snapshot->bySector),
   'by_jurisdiction'=>$this->decimalMap($snapshot->byJurisdiction),
   'by_collateral'=>$this->decimalMap($snapshot->byCollateral),
   'unknown_exposure'=>$snapshot->unknownExposure,
  ];
  $this->repository->saveExposureSnapshot($organizationId,$record);
  return $record;
 }

 public function opportunityDetail(string $organizationId,string $opportunityId,string $portfolioId='paper-master'):array
 {
  $opportunity=$this->trading->getOpportunity($organizationId,$opportunityId)??throw new RuntimeException('Opportunity not found.');
  $capital=(string)($opportunity['required_capital']??$opportunity['capital_required']??$opportunity['expected_capital']??'0');
  $impact=null;
  if(Decimal::fromString($capital)->isPositive())$impact=$this->simulateOpportunityImpact($organizationId,$opportunityId,$capital,$portfolioId);

  $plan=$this->repository->latestAllocationPlan($organizationId,$portfolioId);
  $allocationItem=null;
  foreach((array)($plan['allocations']??[]) as $item){
   if((string)($item['opportunity_id']??'')===$opportunityId){$allocationItem=$item;break;}
  }
  $reservations=array_values(array_filter(
   $this->trading->listCapitalReservations($organizationId,null,5000),
   static fn(array $row):bool=>(string)($row['opportunity_id']??'')===$opportunityId
  ));

  return [
   'opportunity'=>$opportunity,
   'portfolio_impact'=>$impact,
   'allocation_plan_id'=>$plan['plan_id']??null,
   'allocation_item'=>$allocationItem,
   'reservations'=>$reservations,
   'execution'=>$this->trading->getExecutionForOpportunity($organizationId,$opportunityId),
   'portfolio_state'=>$this->capitalState($organizationId,$portfolioId),
   'risk'=>$this->repository->latestRiskSnapshot($organizationId,$portfolioId),
   'exposure'=>$this->repository->latestExposureSnapshot($organizationId,$portfolioId),
  ];
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
  $reconciliation=$this->tokenizedRead->reconcile($organizationId);
  if(($reconciliation['ok']??false)!==true)$state=PortfolioRiskState::ReduceOnly;
  if(isset($input['equity'],$input['peak_equity'])){
   $dd=$this->drawdowns->drawdown(Decimal::fromString((string)$input['equity']),Decimal::fromString((string)$input['peak_equity']));
   $ddState=$this->drawdowns->state($dd,(array)($input['drawdown_thresholds']??[]));
   if(in_array($ddState->value,['STOP_NEW_RISK','EMERGENCY'],true))$state=PortfolioRiskState::ReduceOnly;
   elseif($ddState->value==='REDUCED_RISK'&&$state===PortfolioRiskState::Normal)$state=PortfolioRiskState::Restricted;
   elseif($ddState->value==='CAUTION'&&$state===PortfolioRiskState::Normal)$state=PortfolioRiskState::Caution;
  }
  if(isset($input['daily_loss_budget'],$input['daily_loss'])){
   $dailyBudget=Decimal::fromString((string)$input['daily_loss_budget']);
   $dailyConsumed=DecimalMath::abs(Decimal::fromString((string)$input['daily_loss']));
   if($dailyConsumed->compareTo($dailyBudget)>=0)$state=PortfolioRiskState::ReduceOnly;
   elseif(!$dailyBudget->isZero()&&DecimalMath::divide($dailyConsumed,$dailyBudget)->compareTo(Decimal::fromString('0.8'))>=0&&$state===PortfolioRiskState::Normal)$state=PortfolioRiskState::Caution;
  }
  if(isset($input['weekly_loss_budget'],$input['weekly_loss'])){
   $weeklyBudget=Decimal::fromString((string)$input['weekly_loss_budget']);
   $weeklyConsumed=DecimalMath::abs(Decimal::fromString((string)$input['weekly_loss']));
   if($weeklyConsumed->compareTo($weeklyBudget)>=0)$state=PortfolioRiskState::ReduceOnly;
  }
  $opportunities=$this->prepareOpportunities($organizationId,(array)($input['opportunities']??[]),(string)($input['portfolio_mode']??'PAPER'));

  $plan=$this->allocator->allocate($portfolioId,$available,$opportunities,$policy,$state,(array)($input['hard_caps']??[]));
  $existingPlan=$this->repository->getAllocationPlan($organizationId,$plan->id);
  if($existingPlan!==null&&(string)($existingPlan['input_fingerprint']??'')===$plan->inputFingerprint){
   return $existingPlan;
  }

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
   'decision_inputs'=>[
    'portfolio'=>$portfolio,
    'exposure'=>$this->repository->latestExposureSnapshot($organizationId,$portfolioId),
    'risk'=>$this->repository->latestRiskSnapshot($organizationId,$portfolioId),
    'risk_envelope'=>$this->repository->latestRiskEnvelope($organizationId,$portfolioId),
    'strategy_scorecards'=>$this->research->listScorecards($organizationId,500),
    'opportunities'=>$opportunities,
    'policy'=>['id'=>$policy->id,'version'=>$policy->version,'type'=>$policy->type,'mode'=>$policy->mode,'weights'=>$policy->weights,'constraints'=>$policy->constraints],
    'risk_state'=>$state->value,
    'reconciliation'=>$reconciliation,
   ],
  ];
  $this->repository->saveAllocationPlan($organizationId,$record);
  $this->publish($organizationId,$portfolioId,'capital_markets.allocation.plan_created.v1',['plan_id'=>$record['plan_id'],'policy_version'=>$record['policy_version'],'input_fingerprint'=>$record['input_fingerprint']]);
  $rejections=0;$reductions=0;
  foreach($record['allocations'] as $item){if(($item['decision']??'')==='REJECT')$rejections++;if(($item['decision']??'')==='ACCEPT_REDUCED_SIZE')$reductions++;}
  $this->telemetry->metric($organizationId,'allocation_rejections',$rejections);
  $this->telemetry->metric($organizationId,'allocation_reductions',$reductions);
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
   $this->publish($organizationId,(string)($plan['portfolio_id']??'paper-master'),'capital_markets.capital.reservation_created.v1',['reservation_id'=>$reservationId,'opportunity_id'=>$opportunityId,'amount'=>$amount->value(),'plan_id'=>$planId]);
  }

  if(!$this->repository->approveAllocation($organizationId,$planId,$actorId,gmdate('Y-m-d H:i:s'))){
   $fresh=$this->repository->getAllocationPlan($organizationId,$planId);
   if((string)($fresh['status']??'')!=='APPROVED')throw new RuntimeException('Allocation approval race detected.');
  }
  $this->publish($organizationId,(string)($plan['portfolio_id']??'paper-master'),'capital_markets.allocation.approved.v1',['plan_id'=>$planId,'approved_by'=>$actorId,'reservations'=>$reservationRows]);
  return ['approved'=>true,'idempotent'=>false,'reservations'=>$reservationRows];
 }

 public function assignStrategyAllocation(string $organizationId,array $input,string $portfolioId='paper-master'):array
 {
  $strategyVersionId=trim((string)($input['strategy_version_id']??''));
  if($strategyVersionId==='')throw new RuntimeException('strategy_version_id is required.');
  $strategy=$this->research->getStrategyVersion($organizationId,$strategyVersionId);
  if($strategy===null)throw new RuntimeException('Strategy version not found.');

  $allocated=Decimal::fromString((string)($input['allocated_capital']??'0'));
  if(!$allocated->isPositive())throw new RuntimeException('allocated_capital must be positive.');
  $capital=$this->capitalState($organizationId,$portfolioId);
  if($allocated->compareTo(Decimal::fromString((string)($capital['total']??'0')))>0){
   throw new RuntimeException('STRATEGY_ALLOCATION_EXCEEDS_PORTFOLIO_CAPITAL');
  }

  $existing=$this->repository->listStrategyAllocations($organizationId,$portfolioId);
  $other=Decimal::fromString('0');
  foreach($existing as $row){
   if((string)($row['strategy_version_id']??'')===$strategyVersionId)continue;
   if(!in_array((string)($row['status']??''),['ACTIVE','RAMPING'],true))continue;
   $other=DecimalMath::add($other,Decimal::fromString((string)($row['allocated_capital']??'0')));
  }
  if(DecimalMath::add($other,$allocated)->compareTo(Decimal::fromString((string)($capital['total']??'0')))>0){
   throw new RuntimeException('STRATEGY_ALLOCATIONS_OVERSUBSCRIBE_PORTFOLIO');
  }

  $riskBudget=(array)($input['risk_budget']??[]);
  $record=[
   'allocation_id'=>'strat-alloc-'.substr(hash('sha256',$portfolioId.'|'.$strategyVersionId.'|'.gmdate('YmdHis.u')),0,28),
   'portfolio_id'=>$portfolioId,
   'strategy_version_id'=>$strategyVersionId,
   'allocated_capital'=>$allocated->value(),
   'reserved'=>(string)($input['reserved']??'0'),
   'deployed'=>(string)($input['deployed']??'0'),
   'available'=>$allocated->value(),
   'risk_budget'=>[
    'maximum_drawdown'=>(string)($riskBudget['maximum_drawdown']??'0'),
    'maximum_venue_exposure'=>(string)($riskBudget['maximum_venue_exposure']??$allocated->value()),
    'maximum_unhedged_exposure'=>(string)($riskBudget['maximum_unhedged_exposure']??$allocated->value()),
    'daily_loss_budget'=>(string)($riskBudget['daily_loss_budget']??'0'),
    'weekly_loss_budget'=>(string)($riskBudget['weekly_loss_budget']??'0'),
   ],
   'effective_from'=>gmdate('Y-m-d H:i:s'),
   'status'=>strtoupper((string)($input['status']??'ACTIVE')),
   'created_at'=>gmdate('Y-m-d H:i:s'),
  ];
  $this->repository->saveStrategyAllocation($organizationId,$record);
  $this->publish($organizationId,$portfolioId,'capital_markets.strategy.allocation_changed.v1',['allocation'=>$record]);
  return $record;
 }

 public function refreshCorrelation(string $organizationId,array $input,string $portfolioId='paper-master'):array
 {
  $normalSeries=(array)($input['normal_series']??[]);
  if($normalSeries===[])throw new RuntimeException('Correlation calculation requires normal_series.');
  $normal=$this->correlations->matrix($normalSeries);
  $stressSeries=(array)($input['stress_series']??[]);
  $stress=$stressSeries===[]?[]:$this->correlations->matrix($stressSeries);
  $encode=static function(array $matrix):array{
   $out=[];foreach($matrix as $a=>$row){foreach($row as $b=>$value)$out[$a][$b]=$value instanceof Decimal?$value->value():(string)$value;}return $out;
  };
  $record=[
   'snapshot_id'=>'corr-'.bin2hex(random_bytes(10)),'portfolio_id'=>$portfolioId,'created_at'=>gmdate('Y-m-d H:i:s'),
   'window'=>(string)($input['window']??'configurable'),'normal'=>$encode($normal),'stress'=>$encode($stress),
   'series_type'=>(string)($input['series_type']??'STRATEGY_RETURNS'),
  ];
  $this->repository->saveCorrelationSnapshot($organizationId,$record);return $record;
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
  $positionsForMargin=[];
  foreach($this->trading->listPositions($organizationId,1000) as $position){
   $payload=is_array($position['payload']??null)?$position['payload']:$position;
   $positionsForMargin[]=[
    'position_id'=>(string)($position['position_id']??$payload['position_id']??''),
    'venue_id'=>(string)($position['venue_id']??$payload['venue_id']??''),
    'initial_margin'=>(string)($payload['initial_margin']??'0'),
    'maintenance_margin'=>(string)($payload['maintenance_margin']??'0'),
    'available_margin'=>(string)($payload['available_margin']??'0'),
    'mark_price'=>(string)($payload['mark_price']??'0'),
    'estimated_liquidation_price'=>$payload['estimated_liquidation_price']??null,
   ];
  }
  $marginSnapshot=$this->margins->aggregate($positionsForMargin);
  $margin=isset($input['margin_utilization'])?Decimal::fromString((string)$input['margin_utilization']):$marginSnapshot->marginUtilization;
  $clusters=$this->liquidationClusters->detect($positionsForMargin,Decimal::fromString((string)($input['liquidation_cluster_gap']??'0.03')));

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
   'equity'=>$equity,'capital'=>$equity,
   'gross_exposure'=>$gross,'net_exposure'=>DecimalMath::abs($net),'leverage'=>$leverage,
   'drawdown'=>$drawdown,'daily_loss'=>$dailyLoss,'margin_utilization'=>$margin,
   'available_capital'=>Decimal::fromString((string)($portfolio['available_capital']??'0')),
   'dynamic_limit_multiplier'=>Decimal::fromString((string)($input['dynamic_limit_multiplier']??'1')),
  ];
  foreach((array)($exposure['by_venue']??[]) as $key=>$value)$metrics['venue_exposure:'.$key]=DecimalMath::abs(Decimal::fromString((string)$value));
  foreach((array)($exposure['by_asset']??[]) as $key=>$value)$metrics['asset_exposure:'.$key]=DecimalMath::abs(Decimal::fromString((string)$value));
  foreach((array)($exposure['by_strategy']??[]) as $key=>$value)$metrics['strategy_exposure:'.$key]=DecimalMath::abs(Decimal::fromString((string)$value));
  foreach((array)($exposure['by_currency']??[]) as $key=>$value)$metrics['currency_exposure:'.$key]=DecimalMath::abs(Decimal::fromString((string)$value));
  foreach((array)($exposure['by_counterparty']??[]) as $key=>$value)$metrics['counterparty_exposure:'.$key]=DecimalMath::abs(Decimal::fromString((string)$value));
  foreach((array)($exposure['by_chain']??[]) as $key=>$value)$metrics['chain_exposure:'.$key]=DecimalMath::abs(Decimal::fromString((string)$value));
  $assessment=$this->portfolioRisk->assess($envelope,$metrics,PortfolioRiskState::tryFrom(strtoupper((string)($input['current_state']??'NORMAL')))??PortfolioRiskState::Normal);
  $headroom=[];foreach($assessment['headroom'] as $key=>$h)$headroom[$key]=['metric'=>$h->metric,'current'=>$h->current->value(),'limit'=>$h->limit->value(),'headroom'=>$h->headroom->value(),'utilization'=>$h->utilization->value(),'hard'=>$h->hard,'breached'=>$h->breached];

  $record=[
   'snapshot_id'=>'risk-'.bin2hex(random_bytes(10)),'portfolio_id'=>$portfolioId,'created_at'=>gmdate('Y-m-d H:i:s'),
   'equity'=>$equity->value(),'gross_exposure'=>$gross->value(),'net_exposure'=>$net->value(),'leverage'=>$leverage->value(),
   'drawdown'=>$drawdown->value(),'daily_pnl'=>(string)($input['daily_pnl']??'0'),'margin_utilization'=>$margin->value(),
   'initial_margin'=>$marginSnapshot->initialMargin->value(),'maintenance_margin'=>$marginSnapshot->maintenanceMargin->value(),
   'available_margin'=>$marginSnapshot->availableMargin->value(),'margin_by_venue'=>$marginSnapshot->byVenue,
   'liquidation_clusters'=>$clusters,
   'risk_limit_utilization'=>$headroom,'breaches'=>$assessment['breaches'],'warnings'=>$assessment['warnings'],
   'status'=>$assessment['state']->value,'valuation_quality'=>(string)($input['valuation_quality']??'TRUSTED'),
  ];
  $previous=$this->repository->latestRiskSnapshot($organizationId,$portfolioId);
  $this->repository->saveRiskSnapshot($organizationId,$record);
  if(($previous['status']??null)!==$record['status'])$this->publish($organizationId,$portfolioId,'capital_markets.portfolio.risk_state_changed.v1',['from'=>$previous['status']??null,'to'=>$record['status'],'snapshot_id'=>$record['snapshot_id']]);
  $this->telemetry->metric($organizationId,'gross_exposure',$gross->value());
  $this->telemetry->metric($organizationId,'net_exposure',$net->value());
  $this->telemetry->metric($organizationId,'portfolio_leverage',$leverage->value());
  $this->telemetry->metric($organizationId,'drawdown',$drawdown->value());
  $this->telemetry->metric($organizationId,'risk_utilization',count($headroom));
  if($assessment['breaches']!==[])$this->telemetry->alert($organizationId,CapitalMarketsAlertType::RiskEnvelopeBreached,['breaches'=>$assessment['breaches'],'portfolio_id'=>$portfolioId]);
  elseif($assessment['warnings']!==[])$this->telemetry->alert($organizationId,CapitalMarketsAlertType::RiskEnvelopeApproaching,['warnings'=>$assessment['warnings'],'portfolio_id'=>$portfolioId]);
  if($margin->compareTo(Decimal::fromString('0.8'))>=0)$this->telemetry->alert($organizationId,CapitalMarketsAlertType::MarginUtilizationHigh,['utilization'=>$margin->value()]);
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
  $this->repository->saveRebalancePlan($organizationId,$record);
  $this->publish($organizationId,$portfolioId,'capital_markets.rebalance.plan_created.v1',['plan_id'=>$record['plan_id'],'status'=>$record['status'],'reason'=>$record['reason']]);
  return $record;
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
    'venue'=>(string)($p['venue_id']??$payload['venue_id']??''),
    'side'=>(string)($payload['side']??$p['side']??'LONG'),
    'notional'=>DecimalMath::multiply($q,$m)->value(),
    'initial_margin'=>(string)($payload['initial_margin']??'0'),
    'maintenance_margin'=>(string)($payload['maintenance_margin']??'0'),
    'available_margin'=>(string)($payload['available_margin']??'0'),
    'collateral_asset'=>(string)($payload['collateral_asset']??$payload['collateral']??''),
    'collateral_value'=>(string)($payload['collateral_value']??'0'),
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
  $this->telemetry->metric($organizationId,'stress_estimated_loss',$result->estimatedLoss->value(),['scenario'=>$result->scenarioId]);
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
   $strategyVersionId=trim((string)($opportunity['strategy_version_id']??$opportunity['strategy_version']??''));
   $strategyStatus=strtoupper((string)($opportunity['strategy_status']??''));
   if($strategyVersionId!==''){
    $version=$this->research->getStrategyVersion($organizationId,$strategyVersionId);
    if($version===null)$blocked='STRATEGY_VERSION_NOT_FOUND';
    else $strategyStatus=strtoupper((string)($version['status']??''));
   }
   if($strategyStatus==='')$strategyStatus='RESEARCH';
   $mode=strtoupper($portfolioMode);
   if($mode==='LIVE'&&!in_array($strategyStatus,['LIMITED_LIVE','VALIDATED'],true))$blocked='STRATEGY_NOT_LIVE_VALIDATED';
   if($mode==='PAPER'&&!in_array($strategyStatus,['PAPER','LIMITED_LIVE','VALIDATED'],true))$blocked='STRATEGY_NOT_PAPER_VALIDATED';
   $opportunity['resolved_strategy_status']=$strategyStatus;
   if(isset($opportunity['visible_depth'],$opportunity['stress_exit_depth'],$opportunity['estimated_exit_seconds'])){
    $budgetData=(array)($opportunity['liquidity_budget']??[]);
    $budget=new LiquidityBudget(
     Decimal::fromString((string)($budgetData['maximum_illiquid_capital']??$opportunity['requested_capital']??'0')),
     Decimal::fromString((string)($budgetData['maximum_stress_exit_loss']??'0')),
     (int)($budgetData['maximum_exit_seconds']??3600)
    );
    $liq=$this->liquidity->assess(
     Decimal::fromString((string)($opportunity['requested_capital']??'0')),
     Decimal::fromString((string)$opportunity['visible_depth']),
     Decimal::fromString((string)$opportunity['stress_exit_depth']),
     $budget,(int)$opportunity['estimated_exit_seconds']
    );
    $opportunity['liquidity_bucket']=$liq['bucket']->value;
    if(!$liq['can_enter'])$blocked='INSUFFICIENT_ENTRY_LIQUIDITY';
    elseif(!$liq['can_exit_stress'])$blocked='INSUFFICIENT_STRESS_EXIT_CAPACITY';
   }
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

 private function publish(string $organizationId,string $aggregateId,string $type,array $payload):void
 {
  $this->events->publish(new CapitalRiskLifecycleEvent($type,'cmcr_'.bin2hex(random_bytes(12)),new DateTimeImmutable(),$organizationId,$aggregateId,$payload));
 }

 private function decimalMap(array $values):array
 {
  $out=[];foreach($values as $k=>$v)$out[$k]=$v instanceof Decimal?$v->value():(string)$v;return $out;
 }
}

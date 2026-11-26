<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
use Domains\Marketing\Domain\MarketingDraftPolicy;
use Domains\Marketing\Application\Service\PaidMediaMutationGate;
use Kernel\Module\CapabilityContract;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;

$assert=static function(bool $ok,string $why):void { if(!$ok)throw new RuntimeException($why); };
$manifest=require $root.'/app/Domains/Marketing/module.php';
$assert($manifest['id']==='marketing'&&$manifest['enabled_by_default']===false,'Disabled marketing domain required');
$policy=new MarketingDraftPolicy();
$draft=$policy->validate(['name'=>'Autumn product campaign','business_objective'=>'Lead generation','product_refs'=>['P-1','P-1']]);
$assert($draft['product_refs']===['P-1'],'Product link deduplication');
foreach([
    ['name'=>'AB','business_objective'=>'leads'],
    ['name'=>'Valid name','business_objective'=>'go','organization_id'=>'forged'],
    ['name'=>'Valid name','business_objective'=>'go','status'=>'ACTIVE'],
    ['name'=>'Valid name','business_objective'=>'go','product_refs'=>['../../secret']],
] as $bad) {
    try { $policy->validate($bad); } catch(DomainException) { continue; }
    throw new RuntimeException('Invalid marketing draft was accepted');
}
try { (new PaidMediaMutationGate())->assertAllowed('campaign.activate'); }
catch(DomainException) { $blocked=true; }
$assert($blocked??false,'Paid mutations MUST fail closed');
$validator=new CapabilityJsonInputValidator();
$found=[];
foreach($manifest['contributions']['capability_contracts'] as $raw) {
    $contract=CapabilityContract::fromArray($raw);
    $found[$contract->id]=true;
    $assert(is_file($root.'/'.$contract->inputSchema),'Input schema missing: '.$contract->id);
    $assert(is_file($root.'/'.$contract->outputSchema),'Output schema missing: '.$contract->id);
}
$validator->validate(CapabilityContract::fromArray($manifest['contributions']['capability_contracts'][2]),['name'=>'Test launch','business_objective'=>'Leads']);
$assert(count($found)===58,'Expected 52 typed Marketing contracts including experiment and rule evaluation');
echo "Marketing M0 foundation PASS\n";


$attr=\Domains\Marketing\Domain\MarketingAttributionPolicy::class;
$campaign='mkt-'.str_repeat('a',24);$plan='mkpl-'.str_repeat('b',24);
$lead=['lead_id'=>'42','created_at'=>'2026-01-01 10:00:00','utm_campaign'=>$plan,'utm_source'=>'meta','utm_medium'=>'paid_social'];
$e=$attr::evidence($lead,$campaign,$plan);
$assert($e['model']==='utm-exact-v1'&&$e['evidence_hash']===$attr::evidence($lead,$campaign,$plan)['evidence_hash'],'Deterministic attribution evidence required.');
foreach([[...$lead,'utm_campaign'=>$campaign],[...$lead,'utm_source'=>'google'],[...$lead,'utm_medium'=>'organic'],[...$lead,'lead_id'=>'../../x']] as $bad){
    $blocked=false;try{$attr::evidence($bad,$campaign,$plan);}catch(DomainException){$blocked=true;}
    $assert($blocked,'Wrong native attribution tag accepted.');
}
$assert($attr::minor('12.340000')===1234&&$attr::minor('12.345')===null&&$attr::ratio(5,0)===null,'Currency precision or zero denominator unsafe.');
$metrics=$attr::media(['id'=>'mis-'.str_repeat('c',24),'revision'=>2,'source_status'=>'REVISED','fetched_at'=>'2026-01-01','currency'=>'USD',
    'spend_decimal'=>'12.50','metrics'=>['impressions'=>'1000','clicks'=>'25']]);
$assert($metrics['ctr_percent']===2.5&&$metrics['cpc']===0.5&&$metrics['cpm']===12.5,'M4 media metric formulas incorrect.');
$attr::assertLeadPlanWindow($e,['id'=>$plan,'period_start'=>'2026-01-01','period_end'=>'2026-01-01']);
$blocked=false;try{$attr::assertLeadPlanWindow($e,['id'=>$plan,'period_start'=>'2026-01-02','period_end'=>'2026-01-07']);}catch(DomainException){$blocked=true;}
$assert($blocked,'Out-of-window paid acquisition admitted.');
$assert(!$attr::wonWithin(['won'=>true,'closed_at'=>'2026-01-01 09:59:59'],$e['created_at'],'2026-01-01')
    &&$attr::wonWithin(['won'=>true,'closed_at'=>'2026-01-01 10:00:00'],$e['created_at'],'2026-01-01')
    &&!$attr::wonWithin(['won'=>true,'closed_at'=>'2026-01-02 00:00:00'],$e['created_at'],'2026-01-01'),'Native outcome chronology incorrect.');
echo "Marketing M4 exact attribution and guarded KPI policy PASS\n";

$mp=\Domains\Marketing\Domain\MarketingMediaAnalyticsPolicy::class;
$a=['ad_account_id'=>'act_123','currency'=>'USD','timezone'=>'UTC'];
$rows=$mp::sanitize($a,'campaign','2026-01-01','2026-01-03',[['account_id'=>'123','campaign_id'=>'456','date_start'=>'2026-01-01','date_stop'=>'2026-01-03','spend'=>'1.234567','impressions'=>'100','clicks'=>'2']]);
$assert(count($rows)===1&&$mp::micro('1.234567')===1234567,'Exact media decimal lost.');
foreach([[...$rows,$rows[0]],[[...$rows[0],'account_id'=>'124']],[[...$rows[0],'spend'=>null]]] as $bad){
    $blocked=false;try{$mp::sanitize($a,'campaign','2026-01-01','2026-01-03',$bad);}catch(DomainException){$blocked=true;}
    $assert($blocked,'Unsafe media batch admitted.');
}
$sum=$mp::summarize([['reported_spend_decimal'=>'1.234567','impressions'=>100,'clicks'=>2],['reported_spend_decimal'=>'2.000001','impressions'=>null,'clicks'=>1]]);
$assert($sum['reported_spend_decimal']==='3.234568'&&$sum['impressions']===null&&$sum['clicks']===3&&$sum['reach']===null,'Unknown metric or exact aggregate unsafe.');
echo "Marketing M4 complete media batch validation and exact aggregate PASS\n";

$economicPlan=['id'=>'mkpl-'.str_repeat('a',24),'campaign_id'=>'mkt-'.str_repeat('b',24),'period_start'=>'2026-01-01','period_end'=>'2026-01-07','currency'=>'EUR','lifetime_minor'=>10000];
$scope=['plan_id'=>$economicPlan['id'],'campaign_id'=>$economicPlan['campaign_id'],'window_start'=>$economicPlan['period_start'],'window_end'=>$economicPlan['period_end']];
$lead=[...$scope,'sales_cutoff'=>$scope['window_end'],'leads'=>10,'qualified_leads'=>5,'won_deals'=>2,'missing_source_count'=>0,'source_drift_count'=>0];
$order=[...$scope,'placed_orders'=>2,'unresolved_captured_orders'=>0,'financial_evidence_status'=>'COMPLETE_CAPTURED_COHORT','financial_evidence_count'=>2,'financial_evidence_invalid_count'=>0,'net_cash_received'=>[['currency'=>'EUR','minor'=>8000]]];
$settlement=['state'=>'SETTLED','finance_settled'=>true,'currency'=>'EUR','reservation_id'=>'finr-'.str_repeat('c',24),'committed_minor'=>2000,'released_minor'=>8000,'reserved_minor'=>10000];
$econ=\Domains\Marketing\Domain\MarketingEconomicsPolicy::project($economicPlan,$lead,$order,$settlement);
$assert($econ['cost_per_captured_lead']===2.0&&$econ['cost_per_captured_placed_order']===10.0&&$econ['net_collected_to_settled_media_spend']===4.0&&$econ['roas']===null&&$econ['cac']===null,'Cohort economics fabricated or lost confirmed amounts.');
$econ=\Domains\Marketing\Domain\MarketingEconomicsPolicy::project($economicPlan,[...$lead,'source_drift_count'=>1],[...$order,'financial_evidence_status'=>'PARTIAL'],$settlement);
$assert($econ['cost_per_captured_lead']===null&&$econ['confirmed_collection_minor']===null&&$econ['net_collected_to_settled_media_spend']===null,'Partial cohort promoted to complete economics.');
$econ=\Domains\Marketing\Domain\MarketingEconomicsPolicy::project($economicPlan,$lead,$order,null);
$assert($econ['settled_media_minor']===null&&$econ['cost_per_captured_lead']===null,'Missing settlement represented as zero spend.');
$late='2026-02-06';
$econ=\Domains\Marketing\Domain\MarketingEconomicsPolicy::project($economicPlan,[...$lead,'sales_cutoff'=>$late,'won_deals'=>3],[...$order,'window_end'=>$late,'placed_orders'=>3,'financial_evidence_count'=>3],$settlement,$late);
$assert($econ['conversion_through']===$late&&$econ['window_end']===$economicPlan['period_end']&&$econ['won_deals']===3
    &&$econ['placed_orders']===3&&$econ['settled_media_minor']===2000&&$econ['roas']===null,'Late conversion window corrupted acquisition/spend scope.');
foreach(['2026-01-06','2026-02-07'] as $badCutoff){
    $blocked=false;try{\Domains\Marketing\Domain\MarketingEconomicsPolicy::conversionThrough($economicPlan,$badCutoff);}catch(DomainException){$blocked=true;}
    $assert($blocked,'Out-of-policy conversion cutoff admitted.');
}
$blocked=false;try{\Domains\Marketing\Domain\MarketingEconomicsPolicy::project($economicPlan,$lead,[...$order,'window_end'=>$late],$settlement,$late);}catch(DomainException){$blocked=true;}
$assert($blocked,'Mixed Sales and commerce cutoffs accepted.');
echo "Marketing M4 exact scoped cohort economics, bounded late conversions and unknown financial metrics PASS\n";

$schedulePolicy=\Domains\Marketing\Domain\MarketingAttributionSchedulePolicy::class;
$profile=['profile_id'=>'fixture','organization_id'=>'tenant-a','actor_id'=>'42','campaign_id'=>'mkt-'.str_repeat('a',24),'plan_id'=>null,'source_kind'=>'order','lookback_days'=>30,'max_pages'=>5];
$parsed=$schedulePolicy::parse(json_encode([$profile],JSON_THROW_ON_ERROR));
$assert(count($parsed)===1&&$parsed[0]['max_pages']===5,'Schedule explicit profile lost.');
$clock=new DateTimeImmutable('2026-02-01T00:00:00Z');
$assert($schedulePolicy::window($clock,30,null,'order')===['2026-01-02','2026-01-31'],'Schedule rolling UTC window invalid.');
$assert($schedulePolicy::window($clock,30,['period_start'=>'2026-01-05','period_end'=>'2026-01-10'],'lead')===['2026-01-05','2026-01-10'],'Paid lead scope not bounded.');
$assert($schedulePolicy::window($clock,30,['period_start'=>'2026-01-05','period_end'=>'2026-01-10'],'order')===['2026-01-05','2026-01-31'],'Late paid order scope lost.');
$blocked=false;try{$schedulePolicy::parse(json_encode([$profile,$profile],JSON_THROW_ON_ERROR));}catch(DomainException){$blocked=true;}
$assert($blocked,'Duplicate profile admitted.');
echo "Marketing M4 explicit scheduled profile, rolling UTC windows and bounded paid scope PASS\n";

$budgetPolicy=\Domains\Marketing\Domain\MarketingBudgetOptimizationPolicy::class;
$budgetA=['plan_id'=>'mkpl-'.str_repeat('a',24),'plan_hash'=>str_repeat('a',64),'campaign_id'=>'mkt-'.str_repeat('a',24),
    'provider_campaign_id'=>'111','account_id'=>'mads-'.str_repeat('a',24),'account_revision'=>1,'currency'=>'EUR',
    'window_start'=>'2026-02-01','window_end'=>'2026-02-07','daily_minor'=>10000,'lifetime_minor'=>70000,
    'attribution_hash'=>str_repeat('b',64),'snapshot_ref'=>'mis-'.str_repeat('a',24),'source_revision'=>1,'source_hash'=>str_repeat('c',64),
    'reported_spend_decimal'=>'100.000000','impressions'=>5000,'clicks'=>100];
$budgetB=[...$budgetA,'plan_id'=>'mkpl-'.str_repeat('b',24),'plan_hash'=>str_repeat('b',64),'campaign_id'=>'mkt-'.str_repeat('b',24),
    'provider_campaign_id'=>'222','snapshot_ref'=>'mis-'.str_repeat('b',24),'source_hash'=>str_repeat('d',64),'reported_spend_decimal'=>'50.000000'];
$preview=$budgetPolicy::preview($budgetA,$budgetB);
$assert($preview['status']==='REVIEW_BOUNDED_REALLOCATION'&&$preview['shift_minor']===1000&&$preview['total_daily_minor']===20000
    &&$preview['items'][0]['candidate_daily_minor']===9000&&$preview['items'][1]['candidate_daily_minor']===11000
    &&!$preview['financial_mutation']&&!$preview['paid_activation_approved'],'Budget preview did not conserve bounded daily cap.');
$assert($budgetPolicy::preview($budgetB,$budgetA)===$preview,'Budget preview is not deterministic.');
$assert($budgetPolicy::preview($budgetA,[...$budgetB,'clicks'=>null])['shift_minor']===0,'Unknown click evidence moved budget.');
$assert($budgetPolicy::preview([...$budgetA,'reported_spend_decimal'=>'701.00'],$budgetB)['shift_minor']===0,'Overspend promoted to optimization.');
$assert($budgetPolicy::preview($budgetA,[...$budgetB,'reported_spend_decimal'=>'95.00'])['shift_minor']===0,'Small difference moved budget.');
$blocked=false;try{$budgetPolicy::preview($budgetA,[...$budgetB,'currency'=>'USD']);}catch(DomainException){$blocked=true;}
$assert($blocked,'Incompatible currencies accepted.');
$blocked=false;try{$budgetPolicy::preview($budgetA,[...$budgetB,'provider_campaign_id'=>'111']);}catch(DomainException){$blocked=true;}
$assert($blocked,'Duplicate provider campaign accepted.');
echo "Marketing M5.4 bounded comparative budget preview, no mutation, exact cap conservation PASS\n";

$audiencePolicy=\Domains\Marketing\Domain\MarketingAudienceOptimizationPolicy::class;
$audA=[...$budgetA,'audience_id'=>'mkau-'.str_repeat('a',24),'audience_revision'=>1,'creative_hash'=>str_repeat('f',64),'planned_countries'=>['UA']];
$audB=[...$budgetB,'audience_id'=>'mkau-'.str_repeat('b',24),'audience_revision'=>2,'creative_hash'=>str_repeat('f',64),'planned_countries'=>['PL']];
$compare=$audiencePolicy::preview($audA,$audB);
$assert($compare['status']==='REVIEW_OBSERVED_DIFFERENCE'&&$compare['lower_observed_cpc_plan_id']===$audB['plan_id']
    &&!$compare['targeting_change_recommended']&&!$compare['randomized_experiment']&&!$compare['financial_mutation']
    &&!array_key_exists('candidate_daily_minor',$compare['items'][0]),'Audience comparison invented targeting authority.');
$assert($audiencePolicy::preview($audB,$audA)===$compare,'Audience comparison ordering not deterministic.');
$assert($audiencePolicy::preview($audA,[...$audB,'clicks'=>null])['lower_observed_cpc_plan_id']===null,'Insufficient audience evidence ranked.');
foreach([[...$audB,'planned_countries'=>['UA','PL']],[...$audB,'creative_hash'=>str_repeat('0',64)],
    [...$audB,'daily_minor'=>9999],[...$audB,'currency'=>'USD']] as $bad){
    $blocked=false;try{$audiencePolicy::preview($audA,$bad);}catch(DomainException){$blocked=true;}
    $assert($blocked,'Non-comparable audience scope admitted.');
}
echo "Marketing M5.5 pinned audience comparison advisory and incompatible-scope fences PASS\n";

$experimentPolicy=\Domains\Marketing\Domain\MarketingExperimentPolicy::class;
$armA=['plan_id'=>$budgetA['plan_id'],'plan_hash'=>$budgetA['plan_hash'],'campaign_id'=>$budgetA['campaign_id'],
    'campaign_revision'=>1,'account_id'=>$budgetA['account_id'],'account_revision'=>1,'currency'=>'EUR','account_timezone'=>'UTC',
    'period_start'=>$budgetA['window_start'],'period_end'=>$budgetA['window_end'],'daily_minor'=>10000,'lifetime_minor'=>70000,
    'audience_id'=>$audA['audience_id'],'audience_revision'=>1,'creative_id'=>'mkcr-'.str_repeat('a',24),'creative_revision'=>1,
    'creative_hash'=>str_repeat('a',64),'campaign_scope_hash'=>str_repeat('b',64),'creative_controls_hash'=>str_repeat('c',64),'treatment_hash'=>str_repeat('d',64)];
$armB=[...$armA,'plan_id'=>$budgetB['plan_id'],'plan_hash'=>$budgetB['plan_hash'],'campaign_id'=>$budgetB['campaign_id'],
    'creative_id'=>'mkcr-'.str_repeat('b',24),'creative_hash'=>str_repeat('e',64),'treatment_hash'=>str_repeat('f',64)];
$protocol=$experimentPolicy::protocol($armA,$armB,'HEADLINE','2026-01-31');
$assert($protocol['target_allocation_percent']===50&&$protocol['factor']==='HEADLINE'&&$protocol['control']===$armA,
    'Experiment protocol changed control identity or planned allocation.');
$assert($experimentPolicy::digest($protocol)===$experimentPolicy::digest(array_reverse($protocol,true)),'Canonical protocol digest affected by JSON ordering.');
foreach([[$armA,$armB,'HEADLINE','2026-02-01'],[$armA,$armA,'HEADLINE','2026-01-31'],
    [$armA,[...$armB,'creative_controls_hash'=>str_repeat('0',64)],'HEADLINE','2026-01-31'],
    [$armA,[...$armB,'audience_revision'=>2],'HEADLINE','2026-01-31'],
    [$armA,[...$armB,'daily_minor'=>9999],'HEADLINE','2026-01-31'],
    [$armA,[...$armB,'treatment_hash'=>$armA['treatment_hash']],'HEADLINE','2026-01-31'],[$armA,$armB,'AUDIENCE','2026-01-31']] as $args){
    $blocked=false;try{$experimentPolicy::protocol(...$args);}catch(DomainException){$blocked=true;}
    $assert($blocked,'Confounded or retrospective protocol admitted.');
}
$obs=$experimentPolicy::observe($protocol,$budgetA,$budgetB);
$assert($obs['status']==='REVIEW_OBSERVED_DIFFERENCE'&&$obs['lower_observed_cpc_plan_id']===$budgetB['plan_id']
    &&!$obs['randomization_verified']&&!$obs['winning_creative_confirmed']&&!$obs['causal_attribution']
    &&!array_key_exists('candidate_daily_minor',$obs['control']),'Observation promoted non-randomized data or exposed mutation.');
$obs=$experimentPolicy::observe($protocol,$budgetA,[...$budgetB,'clicks'=>null]);
$assert($obs['status']==='INSUFFICIENT_EVIDENCE'&&$obs['lower_observed_cpc_plan_id']===null,'Insufficient creative evidence ranked.');
$assert($experimentPolicy::observe($protocol,$budgetA,[...$budgetB,'reported_spend_decimal'=>'95.00'])['status']==='NO_MATERIAL_OBSERVED_DIFFERENCE',
    'Immaterial difference ranked.');
$blocked=false;try{$experimentPolicy::observe($protocol,$budgetB,$budgetA);}catch(DomainException){$blocked=true;}
$assert($blocked,'Swapped arm observations accepted.');
$blocked=false;try{$experimentPolicy::observe($protocol,$budgetA,[...$budgetB,'plan_hash'=>str_repeat('0',64)]);}catch(DomainException){$blocked=true;}
$assert($blocked,'Changed plan hash accepted.');
echo "Marketing M5.6 immutable single-factor experiment protocol and M5.7 non-causal creative observation PASS\n";

$rulePolicy=\Domains\Marketing\Domain\MarketingOptimizationRulePolicy::class;
$previousRow=['external_id'=>'90001','reported_spend_decimal'=>'100.00','impressions'=>2000,'clicks'=>100,
    'source_snapshot_ref'=>'mis-'.str_repeat('1',24),'source_revision'=>1,'source_hash'=>str_repeat('1',64)];
$baselineBatch=['id'=>'mkar-'.str_repeat('a',24),'account_id'=>$budgetA['account_id'],'account_revision'=>1,'account_revision_current'=>true,
    'grain'=>'campaign','currency'=>'EUR','timezone'=>'UTC','attribution_hash'=>str_repeat('a',64),
    'window_start'=>'2026-02-01','window_end'=>'2026-02-07','rows'=>[$previousRow]];
$currentRow=[...$previousRow,'reported_spend_decimal'=>'150.00','clicks'=>40,'source_snapshot_ref'=>'mis-'.str_repeat('2',24),'source_hash'=>str_repeat('2',64)];
$currentBatch=[...$baselineBatch,'id'=>'mkar-'.str_repeat('b',24),'window_start'=>'2026-02-08','window_end'=>'2026-02-14','rows'=>[$currentRow]];
$ruleEval=$rulePolicy::evaluate($currentBatch,$baselineBatch,'90001','CTR_DECLINE',50);
$assert($ruleEval['status']==='TRIGGERED_REVIEW_REQUIRED'&&$ruleEval['change_percent']===-60.0&&$ruleEval['review_recommended']&&!$ruleEval['action_created']
    &&$ruleEval['current_source']['source_hash']===$currentRow['source_hash'],'Optimization rule lost exact source evidence or created action.');
$assert($rulePolicy::evaluate($currentBatch,$baselineBatch,'90001','CTR_DECLINE',60)['status']==='TRIGGERED_REVIEW_REQUIRED','Rule equality fence failed.');
$assert($rulePolicy::evaluate($currentBatch,$baselineBatch,'90001','CTR_DECLINE',80)['status']==='NOT_TRIGGERED','Rule threshold ignored.');
$assert($rulePolicy::evaluate($currentBatch,$baselineBatch,'90001','CPC_INCREASE',100)['status']==='TRIGGERED_REVIEW_REQUIRED','CPC threshold not applied.');
$assert($rulePolicy::evaluate($currentBatch,$baselineBatch,'90002','CPM_INCREASE',25)['status']==='INSUFFICIENT_EVIDENCE','Missing source treated as zero.');
$assert($rulePolicy::evaluate([...$currentBatch,'rows'=>[[...$currentRow,'clicks'=>19]]],$baselineBatch,'90001','CPC_INCREASE',25)['status']==='INSUFFICIENT_EVIDENCE','Insufficient CPC sample accepted.');
$assert($rulePolicy::evaluate($currentBatch,$baselineBatch,'90001','CTR_DECLINE',50)===$ruleEval,'Evaluation is not deterministic.');
$blocked=false;try{$rulePolicy::evaluate($currentBatch,$baselineBatch,'90001','CTR_DECLINE',19);}catch(DomainException){$blocked=true;}
$assert($blocked,'Unsafe threshold admitted.');
echo "Marketing M5.8 bounded optimization rule evaluation with exact native evidence and no action authority PASS\n";

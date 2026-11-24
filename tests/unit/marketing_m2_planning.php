<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';

use Domains\Marketing\Domain\MarketingPlanningPolicy;
use Kernel\Module\CapabilityContract;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;

$expect=static function(bool $ok,string $context):void {if(!$ok)throw new RuntimeException($context);};
$blocked=static function(callable $fn,string $context):void {
    try{$fn();}catch(DomainException){return;}
    throw new RuntimeException($context);
};
$policy=new MarketingPlanningPolicy();
$creative=[
    'headline'=>'Eco rental apartment',
    'primary_text'=>'Available viewing this week, subject to confirmation.',
    'call_to_action'=>'Learn more',
    'destination_url'=>'https://example.com/listing/a',
    'language'=>'uk',
    'product_ref'=>'p-item-123'
];
$saved=$policy->creative($creative);
$expect($saved['compliance_state']==='NOT_REVIEWED'
    &&$saved['product_verification']==='PENDING_PRODUCT_ACCEPTANCE'
    &&$saved['destination_verification']==='UNVERIFIED','Creative must never claim verified product');
foreach([
    [...$creative,'destination_url'=>'http://example.com/'],
    [...$creative,'destination_url'=>'https://user:pass@example.com'],
    [...$creative,'language'=>'javascript:'],
    [...$creative,'destination_url'=>'https://127.0.0.1/internal'],
    [...$creative,'product_ref'=>'../bad'],
    [...$creative,'status'=>'APPROVED'],
    [...$creative,'media_sha256'=>'not-a-digest']
] as $invalid) {
    $blocked(static fn()=> $policy->creative($invalid),'Invalid or privileged Creative accepted.');
}
$expect(!array_key_exists('target_spec',$saved),'Legacy Creative payload/hash must remain unchanged.');
$linked=$policy->creative([...$creative,'product_ref'=>str_repeat('a',32),'variant_ref'=>str_repeat('b',32),'location_ref'=>str_repeat('c',32)]);
$expect($linked['variant_ref']===str_repeat('b',32)&&$linked['location_ref']===str_repeat('c',32)
    &&$linked['product_verification']==='PENDING_PRODUCT_ACCEPTANCE','Draft reference must remain unverified.');
$blocked(static fn()=> $policy->creative([...$creative,'variant_ref'=>str_repeat('b',32)]),'Variant without canonical Product accepted.');
$blocked(static fn()=> $policy->creative([...$creative,'location_ref'=>str_repeat('c',32)]),'Location without variant accepted.');
foreach(['https://foo.localhost/','https://a..example.com/','https://example.com:443/','https://2130706433/'] as $invalidUrl){
    $blocked(static fn()=> $policy->creative([...$creative,'destination_url'=>$invalidUrl]),'Invalid destination accepted.');
}
$target=['market_countries'=>['UA','PL'],'audience_hypothesis'=>'Broad demand for local products'];
$targeted=$policy->creative([...$creative,'target_spec'=>$target]);
$expect($targeted['target_spec']['market_countries']===['PL','UA']
    &&$targeted['target_spec']['verification_status']==='UNVERIFIED'
    &&$targeted['target_spec']['provider_eligible']===false,'TargetSpec must be canonical and unverified.');
foreach ([
    null, [], ['market_countries'=>['ua'],'audience_hypothesis'=>'Valid hypothesis'],
    [...$target,'market_countries'=>['UA','UA']],
    [...$target,'market_countries'=>array_fill(0,21,'UA')],
    [...$target,'market_countries'=>['UA','PL'],'provider_eligible'=>true],
    [...$target,'audience_hypothesis'=>'   '],
    [...$target,'provider_audience_id'=>'external-1'],
] as $invalidTarget) {
    $blocked(static fn()=> $policy->creative([...$creative,'target_spec'=>$invalidTarget]),'Invalid TargetSpec accepted.');
}
$au=$policy->audience(['name'=>'Broad local market','target_spec'=>$target]);
$expect($au['target_spec']['provider_eligible']===false,'Audience falsely eligible.');
$ad=['name'=>'Local feed draft','audience_id'=>'mkau-'.str_repeat('a',24),'audience_revision'=>1,
    'creatives'=>[['creative_id'=>'mkcr-'.str_repeat('b',24),'revision'=>1]],'placements'=>['FEED'],
    'optimization'=>'TRAFFIC','planned_daily_budget'=>'15.50','currency'=>'USD'];
$expect($policy->adset($ad)['finance_reservation_confirmed']===false,'Draft budget falsely reserved.');
$blocked(static fn()=> $policy->adset([...$ad,'finance_reservation_confirmed'=>true]),'Forged Finance reservation.');
$blocked(static fn()=> $policy->adset([...$ad,'placements'=>['AUTOMATIC','FEED']]),'Conflicting placements accepted.');
$blocked(static fn()=> $policy->adset([...$ad,'planned_daily_budget'=>'0.00']),'Zero budget accepted.');
$blocked(static fn()=> $policy->adset([...$ad,'creatives'=>[$ad['creatives'][0],$ad['creatives'][0]]]),'Duplicate creative accepted.');
$proposal=[
    'recommendation'=>'Test a shorter headline for Ukrainian users.',
    'rationale'=>'Human hypothesis from an unverified planning note.',
    'evidence'=>[['source_type'=>'MANUAL_NOTE','reference'=>'operator-note-01','note'=>'Manual subjective test note']]
];
$draft=$policy->proposal($proposal);
$expect($draft['origin']==='HUMAN' && !$draft['provider_evidence_verified']
    &&$draft['evidence'][0]['verification_status']==='UNVERIFIED','Proposal must preserve unverified source labeling');
foreach([
    [...$proposal,'origin'=>'HERMES'],
    [...$proposal,'approved'=>true],
    [...$proposal,'evidence'=>[]],
    [...$proposal,'evidence'=>[['source_type'=>'SECRET','reference'=>'x','note'=>'bad source']]],
] as $invalid) {
    $blocked(static fn()=> $policy->proposal($invalid),'Forged/unsupported analyst evidence accepted.');
}
$expect($policy->review(['decision'=>'ACCEPT','reason'=>'Reviewed evidence'])['decision']==='ACCEPT','Review grammar');
$blocked(static fn()=> $policy->review(['decision'=>'APPROVED','reason'=>'bogus action']),'External activation-style review accepted.');
$blocked(static fn()=> $policy->review(['decision'=>'ACCEPT','reason'=>'reason','reviewed_by'=>'someone']),'Caller-supplied review identity accepted.');

$manifest=require $root.'/app/Domains/Marketing/module.php';
$contracts=[];
foreach($manifest['contributions']['capability_contracts'] as $raw){
    $c=CapabilityContract::fromArray($raw);
    $contracts[$c->id]=$c;
}
$expect(count($contracts)===25,'Marketing M0/M1 seven, six M2a and two Product Queries expected.');
$validator=new CapabilityJsonInputValidator();
$validator->validate($contracts['marketing.audience.save'],['name'=>'Broad local market','target_spec'=>$target]);
$validator->validate($contracts['marketing.adset.save'],['campaign_id'=>'mkt-'.str_repeat('a',24),'campaign_revision'=>1,...$ad]);
$validator->validate($contracts['marketing.creative.create'],['campaign_id'=>'mkt-'.str_repeat('a',24),...$creative]);
$validator->validate($contracts['marketing.creative.create'],[
    'campaign_id'=>'mkt-'.str_repeat('a',24),...$creative,'target_spec'=>$target
]);
$validator->validate($contracts['marketing.creative.revise'],[
    'creative_id'=>'mkcr-'.str_repeat('b',24),'expected_revision'=>1,...$creative,'target_spec'=>$target
]);
$blocked(static fn()=> $validator->validate($contracts['marketing.creative.create'],[
    'campaign_id'=>'mkt-'.str_repeat('a',24),...$creative,'target_spec'=>[...$target,'provider_eligible'=>true]
]),'TargetSpec privilege injection accepted at schema gate.');
$validator->validate($contracts['marketing.analyst.proposal.submit'],[
    'campaign_id'=>'mkt-'.str_repeat('a',24),'creative_id'=>'mkcr-'.str_repeat('b',24),
    'campaign_revision'=>1,'creative_revision'=>1,...$proposal
]);
$blocked(static fn()=> $validator->validate($contracts['marketing.analyst.proposal.review'],[
    'proposal_id'=>'mkpr-'.str_repeat('c',24),'expected_revision'=>1,
    'decision'=>'UNSAFE_ACTIVATE','reason'=>'Bogus transition'
]),'Invalid review should fail schema gate');
$history=$contracts['marketing.paid.sync.history']??null;
$expect($history!==null&&$history->kind==='query'
    &&$history->sideEffectLevel==='none'
    &&$history->executionBinding==='query:marketing.paid.sync.history',
    'M2 merge must retain tenant-scoped M1.1 safe read sync history without provider mutations.');
$mergeController=(string)file_get_contents($root.'/symfony/src/Web/Marketing/MarketingPageController.php');
$expect(str_contains($mergeController,'accountHealth')
    &&str_contains($mergeController,'$this->planning->reviewProposal('),
    'M1.1 sync operator queue and M2 independent review must coexist in the same Workspace.');
$expect($manifest['enabled_by_default']===false,'Marketing M2 must remain disabled by default.');
$expect(in_array('app/migrations/20261119_000154_marketing_planning_m2.sql',$manifest['contributions']['migration_files'],true),
    'M2 migration missing from module manifest.');
foreach(['marketing.creative.create','marketing.creative.revise','marketing.analyst.proposal.submit','marketing.analyst.proposal.review'] as $id){
    $c=$contracts[$id];
    $expect($c->kind==='command'&&$c->sideEffectLevel==='internal'
        &&$c->idempotency==='required'&&$c->approvalPolicy!=='none',
        'M2 planning mutation bypassed human-only Command Gateway: '.$id);
}
$controller=(string)file_get_contents($root.'/symfony/src/Web/Marketing/MarketingPageController.php');
$expect(str_contains($controller,'$this->csrf->isValid($request)')
    &&str_contains($controller,'reviewProposal(')
    &&str_contains($controller,'submitProposal('),'M2 UI must use tenant+CSRF and Domain service.');
$gate=(string)file_get_contents($root.'/app/Domains/Marketing/Application/Service/PaidMediaMutationGate.php');
$expect(str_contains($gate,'throw new DomainException'),'M2 must not remove external write guard.');
require_once $root.'/symfony/src/Web/Marketing/MarketingWorkspacePlanningInput.php';
$mapper=\App\Web\Marketing\MarketingWorkspacePlanningInput::class;
$audienceForm=['name'=>'Broad local market','market_countries'=>'ua, pl','audience_hypothesis'=>'General demand'];
$expect($policy->audience($mapper::audience($audienceForm))['target_spec']['market_countries']===['PL','UA'],'Audience form normalization failed.');
$form=['name'=>'Local feed draft','audience_pin'=>$ad['audience_id'].':1',
    'creative_pins'=>[$ad['creatives'][0]['creative_id'].':1'],'placements'=>['FEED'],
    'optimization'=>'TRAFFIC','planned_daily_budget'=>'15.50','currency'=>'USD','campaign_revision'=>'1'];
$expect($mapper::adset($form)===$ad,'AdSet form did not preserve revision pins.');
$expect($mapper::revisionPair(['adset_id'=>'mkas-'.str_repeat('c',24),'expected_revision'=>'2'],'adset_id')
    ===['mkas-'.str_repeat('c',24),2],'AdSet edit revision lost.');
$blocked(static fn()=> $mapper::adset([...$form,'audience_pin'=>$ad['audience_id'].':0']),'Invalid audience pin accepted.');
$blocked(static fn()=> $mapper::adset([...$form,'creative_pins'=>[[]]]),'Nested creative pin accepted.');
$blocked(static fn()=> $mapper::revisionPair(['adset_id'=>'mkas-'.str_repeat('c',24)],'adset_id'),'Incomplete revision pair accepted.');
$blocked(static fn()=> $mapper::revision([...$form,'campaign_revision'=>'1.5'],'campaign_revision'),'Coerced campaign revision accepted.');
$view=(string)file_get_contents($root.'/symfony/templates/experience/marketing/planning_audience_adsets.html.twig');
$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
$expect(str_contains($controller,'$this->planning->saveAudience(')&&str_contains($controller,'$this->planning->saveAdset(')
    &&str_contains($view,'adset.pins_stale')&&str_contains($view,'name="creative_pins[]"')
    &&str_contains($view,'name="csrf_token"')&&str_contains($routes,'cos_marketing_audience_save:')
    &&str_contains($routes,'cos_marketing_adset_save:'),'Audience/AdSet Workspace wiring incomplete.');
$contextPolicy=new \Domains\Marketing\Domain\MarketingAnalystRecommendationPolicy();
$contextHash=str_repeat('a',64);
$envelope=['context_hash'=>$contextHash,'context'=>['sources'=>[['alias'=>'creative','record_id'=>'mkcr-'.str_repeat('a',24),'revision'=>1]]]];
$advice=['context_hash'=>$contextHash,'recommendation'=>'Improve the headline','rationale'=>'Draft language needs review','evidence_aliases'=>['creative']];
$validated=$contextPolicy->validate($envelope,$advice);
$expect(!$validated['paid_activation_approved']&&$validated['evidence'][0]['alias']==='creative','Analyst recommendation overclaimed.');
$blocked(static fn()=> $contextPolicy->validate($envelope,[...$advice,'action'=>'activate']),'Analyst injected Action.');
$blocked(static fn()=> $contextPolicy->validate($envelope,[...$advice,'context_hash'=>str_repeat('b',64)]),'Foreign context response accepted.');
$blocked(static fn()=> $contextPolicy->validate($envelope,[...$advice,'evidence_aliases'=>['invented']]),'Invented evidence accepted.');
$validator->validate($contracts['marketing.analyst.context.get'],['campaign_id'=>'mkt-'.str_repeat('a',24),
    'campaign_revision'=>1,'creative_id'=>'mkcr-'.str_repeat('b',24),'creative_revision'=>1]);
$blocked(static fn()=> $validator->validate($contracts['marketing.analyst.context.get'],['campaign_id'=>'mkt-'.str_repeat('a',24),
    'campaign_revision'=>1,'creative_id'=>'mkcr-'.str_repeat('b',24),'creative_revision'=>1,'organization_id'=>'foreign']),'Client supplied tenant.');
$hermesCredential=new \Platform\Integration\Model\Credential('test',\Kernel\Shared\Domain\OrganizationId::fromString('test-tenant'),
    'connection','hermes_analyst','env://TEST_HERMES_TOKEN',['marketing.advice']);
foreach(['http://hermes.example.com/p/test','https://hermes.example.com','https://hermes.example.com/p/test?x=1',
    'https://user@hermes.example.com/p/test','https://127.0.0.1/p/test'] as $badEndpoint){
    $blocked(static fn()=>new \Domains\Marketing\Infrastructure\Hermes\HermesAnalystConnection($badEndpoint,$hermesCredential),
        'Unsafe or non-profile Hermes URL accepted.');
}
$connection=new \Domains\Marketing\Infrastructure\Hermes\HermesAnalystConnection('https://hermes.example.com/p/test',$hermesCredential);
$expect(!$connection->isolatedProfileAccepted,'Hermes profile admission enabled by default.');
$services=(string)file_get_contents($root.'/symfony/config/services.yaml');
$expect(!str_contains($services,'HermesMarketingAnalystAdapter'),'Hermes runtime must remain unbound until durable admission.');
$admissionClass=\Domains\Marketing\Domain\MarketingAnalystAdmissionPolicy::class;
$disabled=new $admissionClass('tenant-a','profile-ref',2,'cost-control-ref');
$blocked(static fn()=> $disabled->assertAllowed('tenant-a'),'Disabled admission accepted.');
$missingCost=new $admissionClass('tenant-a','profile-ref',2,'',true);
$blocked(static fn()=> $missingCost->assertAllowed('tenant-a'),'Missing runtime cost-control acceptance.');
$admitted=new $admissionClass('tenant-a','profile-ref',2,'cost-control-ref',true);
$admitted->assertAllowed('tenant-a');
$blocked(static fn()=> $admitted->assertAllowed('tenant-b'),'Foreign tenant admission accepted.');
$blocked(static fn()=> new $admissionClass('tenant-a','profile-ref',101,'cost-control-ref',true),'Unbounded quota accepted.');
$expect(!str_contains($services,'MarketingAnalystExecutionService'),'Raw execution service must not be directly registered.');
$validator->validate($contracts['marketing.analyst.run.list'],['campaign_id'=>'mkt-'.str_repeat('a',24),'limit'=>50]);
$recoverInput=['campaign_id'=>'mkt-'.str_repeat('a',24),'run_id'=>'mkar-'.str_repeat('b',24),
    'reason'=>'Verified external termination','evidence_ref'=>'operator-check-01','local_and_remote_stopped'=>true];
$validator->validate($contracts['marketing.analyst.run.recover'],$recoverInput);
$blocked(static fn()=> $validator->validate($contracts['marketing.analyst.run.recover'],[...$recoverInput,'paid_activation_approved'=>true]),'Recovery injected paid authority.');
$expect(str_contains($controller,'$this->analystReviews->review(')&&str_contains($controller,'$this->analystReviews->recover('),'Analyst journal decisions not wired.');
$profilesClass=\Domains\Marketing\Infrastructure\Hermes\HermesAnalystConfigurationRegistry::class;
$configRaw=['enabled'=>true,'profile_ref'=>'mkt-test','profile_url'=>'https://hermes.example.com/p/mkt-test',
    'credential_ref'=>'env://TEST_HERMES_SECRET','daily_run_limit'=>4,'cost_control_ref'=>'provider-cap-fixture',
    'isolation_evidence_ref'=>'isolation-fixture','accepted_until'=>gmdate('Y-m-d\\TH:i:s\\Z',time()+86400),'max_cost_usd'=>'0.2500'];
$profiles=new $profilesClass(json_encode(['tenant-a'=>$configRaw],JSON_THROW_ON_ERROR));
$configuration=$profiles->forTenant('tenant-a');
$expect($configuration!==null&&$configuration->maxCostUsd===0.25&&$profiles->forTenant('tenant-b')===null,'Tenant profile registry leaked.');
$configuration->assertAccepted('tenant-a');
$blocked(static fn()=> $configuration->assertAccepted('tenant-b'),'Foreign Hermes configuration accepted.');
$changed=new $profilesClass(json_encode(['tenant-a'=>[...$configRaw,'max_cost_usd'=>'0.3000']],JSON_THROW_ON_ERROR));
$expect($changed->forTenant('tenant-a')->configurationHash!==$configuration->configurationHash,'Changed ceiling did not invalidate config hash.');
foreach([['max_cost_usd'=>'0.0000'],['max_cost_usd'=>'11.0000'],['credential_ref'=>'raw-secret'],['profile_url'=>'http://hermes.example.com/p/mkt-test']] as $invalid){
    $bad=new $profilesClass(json_encode(['tenant-a'=>[...$configRaw,...$invalid]],JSON_THROW_ON_ERROR));
    $blocked(static fn()=> $bad->forTenant('tenant-a'),'Invalid profile accepted.');
}
$expired=new $profilesClass(json_encode(['tenant-a'=>[...$configRaw,'accepted_until'=>'2020-01-01T00:00:00Z']],JSON_THROW_ON_ERROR));
$blocked(static fn()=> $expired->forTenant('tenant-a')->assertAccepted('tenant-a'),'Expired operator acceptance admitted.');
$validator->validate($contracts['marketing.analyst.runtime.status'],[]);
$launch=$contracts['marketing.analyst.run.request'];
$expect($launch->approvalPolicy==='required'&&$launch->executionBinding==='action:marketing.analyst.run.request'
    &&$launch->sideEffectLevel==='external'&&$launch->retryPolicy['max_attempts']===1,'Hermes launch bypasses approval.');
$validator->validate($launch,['target_type'=>'marketing_campaign','target_id'=>'mkt-'.str_repeat('a',24),'parameters'=>[
    'campaign_revision'=>1,'creative_id'=>'mkcr-'.str_repeat('b',24),'creative_revision'=>1,
    'configuration_hash'=>str_repeat('c',64),'context_hash'=>str_repeat('d',64)]]);
$handlerSource=(string)file_get_contents($root.'/app/Domains/Marketing/Automation/Action/MarketingAnalystRunHandler.php');
$moduleSource=(string)file_get_contents($root.'/app/Domains/Marketing/Bootstrap/MarketingDomainModule.php');
$expect(str_contains($handlerSource,'$this->workerAdmission->assertAuthorized($action)')
    &&str_contains($handlerSource,'$this->identities->resolve(')&&str_contains($moduleSource,'PolicyDecision::ApprovalRequired')
    &&str_contains($services,'MarketingDomainModule'),'Canonical analyst Action admission not wired.');
$expect(str_contains($controller,'$this->analystPlans->propose(')&&!str_contains($controller,'->recommend('),'Workspace bypasses canonical Plan.');
$expect($mapper::analystPin(['creative_pin'=>'mkcr-'.str_repeat('a',24).':2'],'creative_pin','mkcr-')===['mkcr-'.str_repeat('a',24),2],'Analyst form pin failed.');
echo "Marketing M2 typed planning policy, provenance, CSRF and boundary PASS\n";


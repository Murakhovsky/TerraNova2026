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
$expect(count($contracts)===12,'Marketing M0/M1 six plus six M2 capabilities expected.');
$validator=new CapabilityJsonInputValidator();
$validator->validate($contracts['marketing.creative.create'],['campaign_id'=>'mkt-'.str_repeat('a',24),...$creative]);
$validator->validate($contracts['marketing.analyst.proposal.submit'],[
    'campaign_id'=>'mkt-'.str_repeat('a',24),'creative_id'=>'mkcr-'.str_repeat('b',24),
    'campaign_revision'=>1,'creative_revision'=>1,...$proposal
]);
$blocked(static fn()=> $validator->validate($contracts['marketing.analyst.proposal.review'],[
    'proposal_id'=>'mkpr-'.str_repeat('c',24),'expected_revision'=>1,
    'decision'=>'UNSAFE_ACTIVATE','reason'=>'Bogus transition'
]),'Invalid review should fail schema gate');
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
echo "Marketing M2 typed planning policy, provenance, CSRF and boundary PASS\n";


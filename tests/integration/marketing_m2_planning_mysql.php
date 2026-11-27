<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require (getenv('COS_TEST_AUTOLOAD')?:$root.'/vendor/autoload.php');

use Domains\Marketing\Domain\MarketingDraftPolicy;
use Domains\Marketing\Domain\MarketingPlanningPolicy;
use Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingCampaignRepository;
use Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPlanningRepository;

$assert=static function(bool $ok,string $msg):void {if(!$ok)throw new RuntimeException($msg);};
$denied=static function(callable $fn,string $msg):void{
    try{$fn();}catch(DomainException){return;}
    throw new RuntimeException($msg);
};
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST')?:'127.0.0.1',getenv('DB_PORT')?:'3306',getenv('DB_DATABASE')?:'cos'),
    getenv('DB_USERNAME')?:'cos',getenv('DB_PASSWORD')?:'',[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    ]);
$orgA='marketing-m2-a-'.bin2hex(random_bytes(5));
$orgB='marketing-m2-b-'.bin2hex(random_bytes(5));
$campaigns=new MysqlMarketingCampaignRepository($db);
$repo=new MysqlMarketingPlanningRepository($db);
$policy=new MarketingPlanningPolicy();
$campaign=$campaigns->createDraft($orgA,'101',(new MarketingDraftPolicy())->validate([
    'name'=>'Marketing M2 creative acceptance',
    'business_objective'=>'Human approved planning only',
    'product_refs'=>['prod-future-1'],
    'brief'=>'No Meta writes or paid delivery',
]),'campaign-m2-01');
$campaignId=$campaign['id'];
$creativeData=$policy->creative([
    'headline'=>'Eco materials local offer',
    'primary_text'=>'This draft is not an advertisement until verified.',
    'call_to_action'=>'Read more',
    'destination_url'=>'https://example.com/catalog/product',
    'language'=>'uk',
    'product_ref'=>str_repeat('a',32),
    'variant_ref'=>str_repeat('b',32),
    'location_ref'=>str_repeat('c',32),
    'target_spec'=>['market_countries'=>['UA','PL'],'audience_hypothesis'=>'Broad local product demand'],
]);
$creative=$repo->createCreative($orgA,$campaignId,'101',$creativeData,'creative-create-01');
$creativeId=$creative['id'];
$assert($creative['content']['variant_ref']===str_repeat('b',32)&&$creative['content']['location_ref']===str_repeat('c',32),'Variant/location revision not persisted.');
$assert($creative['revision']===1&&$creative['status']==='DRAFT'
    &&$creative['content']['product_verification']==='PENDING_PRODUCT_ACCEPTANCE','Creative must begin unverified DRAFT');
$assert($creative['content']['target_spec']['market_countries']===['PL','UA']
    &&$creative['content']['target_spec']['provider_eligible']===false,'TargetSpec native readback missing.');
$again=$repo->createCreative($orgA,$campaignId,'101',$creativeData,'creative-create-01');
$assert($again['id']===$creativeId,'Creative create idempotency violated');
$denied(static fn()=> $repo->createCreative($orgA,$campaignId,'101',
    [...$creativeData,'headline'=>'Conflicting draft'],'creative-create-01'),'Idempotency key payload mismatch accepted');
$denied(static fn()=> $repo->creatives($orgB,$campaignId),'Cross-tenant creative listing accepted');
$assert(count($repo->creatives($orgA,$campaignId))===1,'Duplicate creative exists');

$evidence=$policy->proposal([
    'recommendation'=>'Consider shorter local-language headline.',
    'rationale'=>'Human planning note requires confirmation before launch.',
    'evidence'=>[['source_type'=>'MANUAL_NOTE','reference'=>'human-planning-note-1','note'=>'Manual preliminary opinion']],
]);
$proposal=$repo->submitProposal($orgA,$campaignId,$creativeId,1,1,'101',$evidence,'proposal-submit-01');
$proposalId=$proposal['id'];
$assert($proposal['status']==='PROPOSED'&&$proposal['origin']==='HUMAN'
    &&$proposal['evidence'][0]['verification_status']==='UNVERIFIED'
    &&!$proposal['paid_activation_approved'],'Human note must not assert provider evidence or delivery');
$again=$repo->submitProposal($orgA,$campaignId,$creativeId,1,1,'101',$evidence,'proposal-submit-01');
$assert($again['id']===$proposalId,'Proposal submit idempotency failed');
$denied(static fn()=> $repo->reviewProposal($orgA,$proposalId,'101',1,
    ['decision'=>'ACCEPT','reason'=>'Same actor'], 'review-self-01'),'Self-review should be rejected');
$denied(static fn()=> $repo->reviewProposal($orgB,$proposalId,'202',1,
    ['decision'=>'ACCEPT','reason'=>'Foreign organization'], 'review-foreign-01'),'Cross-tenant review accepted');
$review=$repo->reviewProposal($orgA,$proposalId,'202',1,
    ['decision'=>'ACCEPT','reason'=>'Useful subject to product verification'], 'review-accept-01');
$assert($review['status']==='ACCEPTED'&&$review['revision']===2
    &&$review['reviewed_by']==='202'&&!$review['paid_activation_approved'],
    'Human recommendation acceptance did not persist safely');
$repeat=$repo->reviewProposal($orgA,$proposalId,'202',1,
    ['decision'=>'ACCEPT','reason'=>'Useful subject to product verification'], 'review-accept-01');
$assert($repeat['id']===$review['id']&&$repeat['revision']===2,'Duplicate review advanced state');
$denied(static fn()=> $repo->reviewProposal($orgA,$proposalId,'203',2,
    ['decision'=>'REJECT','reason'=>'Already reviewed'], 'review-after-accept-01'),'Double review accepted');

$creativeV2=$repo->reviseCreative($orgA,$creativeId,'101',1,
    [...$creativeData,'headline'=>'Local offer revised','target_spec'=>[...$creativeData['target_spec'],'market_countries'=>['UA']]],'creative-revise-01');
$assert($creativeV2['revision']===2&&$creativeV2['content']['headline']==='Local offer revised','Creative revision append failed');
$assert($creativeV2['content']['target_spec']['market_countries']===['UA'],'TargetSpec revision did not persist.');
$denied(static fn()=> $repo->reviseCreative($orgA,$creativeId,'101',1,
    [...$creativeData,'headline'=>'Old revision conflict'],'creative-revise-old'),'Stale creative revision accepted');
$proposal2=$repo->submitProposal($orgA,$campaignId,$creativeId,1,2,'101',$evidence,'proposal-submit-02');
$repo->reviseCreative($orgA,$creativeId,'101',2,
    [...$creativeData,'headline'=>'Local offer third revision'],'creative-revise-02');
$denied(static fn()=> $repo->reviewProposal($orgA,$proposal2['id'],'202',1,
    ['decision'=>'ACCEPT','reason'=>'Stale supporting Creative'], 'review-stale-01'),'Stale evidence accepted after Creative changed');
$assert(count($repo->proposals($orgA,$campaignId))===2
    &&count($repo->creatives($orgA,$campaignId))===1,'Planning projections duplicated');

$q=$db->prepare('SELECT content_json FROM marketing_creative_revisions WHERE organization_id=? AND creative_id=? AND revision=1');
$q->execute([$orgA,$creativeId]);
$first=json_decode((string)$q->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
$assert($first['target_spec']['market_countries']===['PL','UA'],'Original TargetSpec was overwritten.');
$q=$db->prepare('SELECT COUNT(*) FROM marketing_creative_revisions WHERE organization_id=? AND creative_id=?');
$q->execute([$orgA,$creativeId]);$assert((int)$q->fetchColumn()===3,'Creative append-only revision history lost');
$q=$db->prepare('SELECT COUNT(*) FROM marketing_planning_intents WHERE organization_id=?');
$q->execute([$orgA]);$assert((int)$q->fetchColumn()===6,'Planning idempotency receipt count unexpected');
$au=$policy->audience(['name'=>'Local audience','target_spec'=>['market_countries'=>['UA'],'audience_hypothesis'=>'General local demand']]);
$aud=$repo->saveAudience($orgA,'101',null,null,$au,'aud-create-01');
$ad=$policy->adset(['name'=>'Feed plan','audience_id'=>$aud['id'],'audience_revision'=>1,
    'creatives'=>[['creative_id'=>$creativeId,'revision'=>3]],'placements'=>['FEED'],'optimization'=>'TRAFFIC',
    'planned_daily_budget'=>'12.50','currency'=>'USD']);
$set=$repo->saveAdset($orgA,$campaignId,1,'101',null,null,$ad,'adset-create-01');
$assert(!$set['launch_eligible']&&!$set['content']['finance_reservation_confirmed'],'Draft falsely approved.');
$assert(count($repo->audiences($orgA))===1&&$repo->audiences($orgB)===[],'Audience tenant isolation failed.');
$assert($repo->adsets($orgA,$campaignId)[0]['pins_stale']===false,'Current pins falsely stale.');
$denied(static fn()=> $repo->adsets($orgB,$campaignId),'Foreign AdSet listing accepted.');
$denied(static fn()=> $repo->saveAdset($orgB,$campaignId,1,'101',null,null,$ad,'foreign-adset-01'),'Foreign campaign accepted.');
$au2=$repo->saveAudience($orgA,'101',$aud['id'],1,[...$au,'name'=>'Revised audience'],'aud-revise-01');
$assert($au2['revision']===2&&$repo->adsets($orgA,$campaignId)[0]['pins_stale'],'Audience revision did not stale AdSet.');
$replay=$repo->saveAudience($orgA,'101',null,null,$au,'aud-create-01');
$assert($replay['revision']===1&&$replay['id']===$aud['id'],'Original audience replay lost.');
$denied(static fn()=> $repo->saveAdset($orgA,$campaignId,1,'101',$set['id'],1,$ad,'adset-stale-01'),'Stale Audience accepted.');
$set2=$repo->saveAdset($orgA,$campaignId,1,'101',$set['id'],1,[...$ad,'audience_revision'=>2],'adset-revise-01');
$assert($set2['revision']===2&&!$repo->adsets($orgA,$campaignId)[0]['pins_stale'],'AdSet revision failed.');
$denied(static fn()=> $repo->saveAdset($orgA,$campaignId,1,'101',$set['id'],1,[...$ad,'audience_revision'=>2],'adset-old-01'),'Stale AdSet revision accepted.');
$contextActor=static fn(string $org)=>new \Kernel\Tenant\Model\TenantContext(
    \Kernel\Shared\Domain\UserId::fromString('101'),\Kernel\Shared\Domain\OrganizationId::fromString($org),
    \Kernel\Identity\Model\OrganizationRole::fromString('manager'),[
        \Kernel\Tenant\Model\Permission::fromString(\Kernel\Tenant\Model\TenantPermissions::ACCESS),
        \Kernel\Tenant\Model\Permission::fromString(\Kernel\Tenant\Model\TenantPermissions::MANAGE)]);
$disabledProduct=new class implements \Domains\Marketing\Application\Contract\MarketingProductDraftReadPort {
    public function inspect(string $organizationId,string $productId,?string $variantId,string $currency):?array {throw new DomainException('Product disabled.');}
    public function search(string $organizationId,string $query,int $limit,int $offset):array {throw new DomainException('Product disabled.');}
    public function stock(string $organizationId,string $productId,string $variantId,string $locationId):?array {throw new DomainException('Product disabled.');}
};
$contexts=new \Domains\Marketing\Application\Service\MarketingAnalystContextService(
    new \Domains\Marketing\Application\Service\MarketingCampaignService($campaigns,new MarketingDraftPolicy()),
    new \Domains\Marketing\Application\Service\MarketingPlanningService($repo,$policy),
    new \Domains\Marketing\Application\Service\MarketingProductDraftCheckService($disabledProduct));
$actor=$contextActor($orgA);
$envelope=$contexts->build($actor,$campaignId,1,$creativeId,3,$set['id'],2);
$assert(!$envelope['delegated']&&$envelope['context']['facts']['product']['status']==='UNAVAILABLE'
    &&$envelope['context']['facts']['performance']['status']==='NO_VERIFIED_CAMPAIGN_BINDING'
    &&count($envelope['context']['sources'])===4,'Scoped analyst context missing or overclaimed.');
$assert(!str_contains(json_encode($envelope,JSON_THROW_ON_ERROR),'destination_url'),'Raw destination URL leaked.');
$assert($contexts->build($actor,$campaignId,1,$creativeId,3,$set['id'],2)['context_hash']===$envelope['context_hash'],'Stable context hash changed.');
$denied(static fn()=> $contexts->build($contextActor($orgB),$campaignId,1,$creativeId,3),'Cross-tenant analyst context accepted.');
$denied(static fn()=> $contexts->build($actor,$campaignId,1,$creativeId,2),'Stale Creative context accepted.');
$denied(static fn()=> $contexts->build($actor,$campaignId,1,$creativeId,3,$set['id'],1),'Stale AdSet context accepted.');
$credential=new \Platform\Integration\Model\Credential('hermes-fixture',\Kernel\Shared\Domain\OrganizationId::fromString($orgA),
    'hermes-fixture-connection','hermes_analyst','env://TEST_HERMES_TOKEN',['marketing.advice']);
$vault=new class implements \Platform\Integration\Contract\CredentialVaultInterface {
    public function resolve(\Platform\Integration\Model\Credential $credential):array{return ['access_token'=>'fixture-token'];}
};
$transport=new class implements \Domains\Marketing\Infrastructure\Hermes\HermesAnalystTransport {
    public bool $tools=false;
    public bool $incomplete=false;
    public bool $timeout=false;
    public mixed $usage=null;
    public int $posts=0;
    public array $payload=[];
    public string $key='';
    public function get(string $url,string $token):array {
        if(!str_ends_with($url,'/p/marketing-fixture/v1/toolsets')||$token!=='fixture-token')throw new RuntimeException('Wrong profile/auth.');
        return [['enabled'=>$this->tools,'tools'=>['terminal']]];
    }
    public function post(string $url,string $token,string $key,array $payload):array {
        if(!str_ends_with($url,'/p/marketing-fixture/v1/chat/completions')||$token!=='fixture-token')throw new RuntimeException('Wrong endpoint/auth.');
        $this->posts++;$this->payload=$payload;$this->key=$key;
        if($this->timeout)throw new RuntimeException('Fixture remote timeout.');
        $facts=json_decode($payload['messages'][1]['content'],true,32,JSON_THROW_ON_ERROR);
        return ['id'=>'chatcmpl-fixture','usage'=>$this->usage,'choices'=>[['finish_reason'=>$this->incomplete?'length':'stop','message'=>[
            'role'=>'assistant','content'=>json_encode(['context_hash'=>$facts['context_hash'],'recommendation'=>'Improve draft headline',
                'rationale'=>'Creative wording needs review','evidence_aliases'=>['creative']],JSON_THROW_ON_ERROR)]]]];
    }
};
$connection=new \Domains\Marketing\Infrastructure\Hermes\HermesAnalystConnection('https://hermes.example.com/p/marketing-fixture',$credential,true);
$runtime=new \Domains\Marketing\Infrastructure\Hermes\HermesMarketingAnalystAdapter($contexts,$connection,$vault,$transport,
    new \Domains\Marketing\Domain\MarketingAnalystRecommendationPolicy());
$advice=$runtime->recommend($actor,$envelope,'fixture-correlation-01');
$assert($advice['runtime']==='HERMES_AGENT'&&$advice['runtime_response_id']==='chatcmpl-fixture'
    &&!$advice['paid_activation_approved']&&$transport->posts===1,'Hermes adapter output invalid.');
$sent=$transport->payload['messages'][1]['content'];
$assert(!str_contains($sent,$campaignId)&&!str_contains($sent,$creativeId)&&!str_contains($sent,'fixture-token')
    &&!str_contains($sent,'destination_url'),'Hermes received unnecessary identifiers or secrets.');
$key=$transport->key;
$runtime->recommend($actor,$envelope,'fixture-correlation-01');
$assert($key===$transport->key,'Hermes idempotency identity changed.');
$transport->tools=true;
$denied(static fn()=> $runtime->recommend($actor,$envelope,'fixture-correlation-02'),'Tool-enabled Hermes was admitted.');
$assert($transport->posts===2,'Unsafe profile received a turn.');
$transport->tools=false;$transport->incomplete=true;
$denied(static fn()=> $runtime->recommend($actor,$envelope,'fixture-correlation-03'),'Incomplete Hermes turn accepted.');
$denied(static fn()=> $runtime->recommend($contextActor($orgB),$envelope,'fixture-correlation-04'),'Foreign tenant used Hermes profile.');
$denied(static fn()=> $runtime->recommend($actor,[...$envelope,'context_hash'=>str_repeat('f',64)],'fixture-correlation-05'),'Forged context accepted.');
$closed=new \Domains\Marketing\Infrastructure\Hermes\HermesMarketingAnalystAdapter($contexts,
    new \Domains\Marketing\Infrastructure\Hermes\HermesAnalystConnection('https://hermes.example.com/p/marketing-fixture',$credential),
    $vault,$transport,new \Domains\Marketing\Domain\MarketingAnalystRecommendationPolicy());
$denied(static fn()=> $closed->recommend($actor,$envelope,'fixture-correlation-06'),'Unaccepted Hermes profile admitted.');
// UX-06 exact integer readback limits; this is an isolated transport fixture,
// not a claim that the live gateway enforces a generation-time cap.
$limits=new \Domains\Marketing\Domain\MarketingAnalystTokenBudget(800,400,1000,'fixture-token-cap');
$strictRuntime=new \Domains\Marketing\Infrastructure\Hermes\HermesMarketingAnalystAdapter($contexts,$connection,$vault,$transport,
    new \Domains\Marketing\Domain\MarketingAnalystRecommendationPolicy(),$limits);
$transport->incomplete=false;
$transport->usage=['prompt_tokens'=>800,'completion_tokens'=>200,'total_tokens'=>1000,'private_extra'=>'discard'];
$bounded=$strictRuntime->recommend($actor,$envelope,'strict-fixture-exact01');
$assert($bounded['token_usage']['total_tokens']===1000&&$bounded['token_usage']['prompt_tokens']===800
    &&$bounded['token_usage']['hard_cap_verified']===false&&!isset($bounded['token_usage']['private_extra']),'Exact ceiling receipt rejected or leaked metadata.');
$transport->usage=['prompt_tokens'=>600,'completion_tokens'=>400,'total_tokens'=>1000];
$bounded=$strictRuntime->recommend($actor,$envelope,'strict-fixture-exact02');
$assert($bounded['token_usage']['completion_tokens']===400,'Exact completion ceiling rejected.');
foreach([
    null,
    ['prompt_tokens'=>801,'completion_tokens'=>199,'total_tokens'=>1000],
    ['prompt_tokens'=>599,'completion_tokens'=>401,'total_tokens'=>1000],
    ['prompt_tokens'=>700,'completion_tokens'=>301,'total_tokens'=>1001],
    ['prompt_tokens'=>600,'completion_tokens'=>399,'total_tokens'=>1000],
    ['prompt_tokens'=>600,'completion_tokens'=>400],
    ['prompt_tokens'=>'600','completion_tokens'=>400,'total_tokens'=>1000],
    ['prompt_tokens'=>600.0,'completion_tokens'=>400,'total_tokens'=>1000],
    ['prompt_tokens'=>0,'completion_tokens'=>400,'total_tokens'=>400],
    ['prompt_tokens'=>600,'completion_tokens'=>-1,'total_tokens'=>599],
    ['prompt_tokens'=>PHP_INT_MAX,'completion_tokens'=>PHP_INT_MAX,'total_tokens'=>1000],
] as $index=>$invalidUsage){
    $transport->usage=$invalidUsage;
    $priorPosts=$transport->posts;
    $denied(static fn()=> $strictRuntime->recommend($actor,$envelope,'strict-fixture-invalid-'.$index),'Invalid exact token receipt accepted.');
    $assert($transport->posts===$priorPosts+1,'Invalid usage was retried.');
}
$transport->timeout=true;$priorPosts=$transport->posts;
try{
    $strictRuntime->recommend($actor,$envelope,'strict-fixture-timeout01');
    throw new LogicException('Timeout became advice.');
}catch(RuntimeException $error){
    $assert($error->getMessage()==='Fixture remote timeout.','Unexpected fixture timeout failure.');
}
$assert($transport->posts===$priorPosts+1,'Remote timeout was retried.');
$transport->timeout=false;$transport->usage=null;
echo "UX06 exact token receipt ceilings, malformed counts, timeout and no retry PASS.\n";

$catalog=new \Kernel\Module\ModuleCatalog([\Kernel\Module\ModuleDefinition::fromArray(require $root.'/app/Domains/Marketing/module.php')]);
$state=new class($orgA) implements \Kernel\Module\Contract\ModuleStateRepositoryInterface {
    public function __construct(private string $org){}
    public function enabledOverride(string $organizationId,string $moduleId):?bool{return $organizationId===$this->org&&$moduleId==='marketing';}
    public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void{throw new RuntimeException('Read-only fixture.');}
};
$modules=new \Kernel\Module\ActiveModuleResolver($catalog,$state);
$runStore=new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingAnalystRunStore($db);
$admission=new \Domains\Marketing\Domain\MarketingAnalystAdmissionPolicy($orgA,'hermes-fixture',2,'fixture-provider-cap',true);
$execution=new \Domains\Marketing\Application\Service\MarketingAnalystExecutionService($contexts,$strictRuntime,$runStore,$admission,
    new \Domains\Marketing\Domain\MarketingAnalystRecommendationPolicy(),$modules,$limits);
$transport->incomplete=false;$beforePosts=$transport->posts;
$transport->usage=['prompt_tokens'=>800,'completion_tokens'=>200,'total_tokens'=>1000];
$run=$execution->request($actor,$campaignId,1,$creativeId,3,$set['id'],2,'hermes-run-01');
$assert($run['state']==='SUCCEEDED'&&$run['advice']['status']==='UNREVIEWED_ADVICE'&&!$run['finance_cost_confirmed']
    &&$run['advice']['token_usage']['total_tokens']===1000
    &&!$run['paid_activation_approved']&&$transport->posts===$beforePosts+1,'Durable Hermes receipt failed.');
$again=$execution->request($actor,$campaignId,1,$creativeId,3,$set['id'],2,'hermes-run-01');
$assert($again['id']===$run['id']&&$transport->posts===$beforePosts+1,'Replay issued another remote turn.');
$denied(static fn()=> $execution->request($actor,$campaignId,1,$creativeId,3,null,null,'hermes-run-01'),'Different pins reused an execution key.');
$denied(static fn()=> $execution->request($contextActor($orgB),$campaignId,1,$creativeId,3,null,null,'hermes-run-01'),'Foreign run receipt leaked.');
$transport->usage=['prompt_tokens'=>700,'completion_tokens'=>301,'total_tokens'=>1001];
$uncertain=$execution->request($actor,$campaignId,1,$creativeId,3,$set['id'],2,'hermes-run-02');
$assert($uncertain['state']==='UNCERTAIN'&&$uncertain['advice']===null&&$uncertain['failure_code']==='REMOTE_OUTCOME_UNCERTAIN'
    &&$transport->posts===$beforePosts+2,'Over-budget remote turn did not consume a durable uncertain slot.');
$again=$execution->request($actor,$campaignId,1,$creativeId,3,$set['id'],2,'hermes-run-02');
$assert($again['state']==='UNCERTAIN'&&$transport->posts===$beforePosts+2,'Uncertain outcome was retried.');
$denied(static fn()=> $execution->request($actor,$campaignId,1,$creativeId,3,$set['id'],2,'hermes-run-03'),'Daily quota bypassed.');
$assert($runStore->byKey($orgB,'hermes-run-01')===null,'Cross-tenant run receipt leaked.');
$reviews=new \Domains\Marketing\Application\Service\MarketingAnalystReviewService($runStore,$contexts,
    new \Domains\Marketing\Application\Service\MarketingCampaignService($campaigns,new MarketingDraftPolicy()),$policy,$modules);
$reviewer=new \Kernel\Tenant\Model\TenantContext(\Kernel\Shared\Domain\UserId::fromString('202'),
    \Kernel\Shared\Domain\OrganizationId::fromString($orgA),\Kernel\Identity\Model\OrganizationRole::fromString('manager'),[
    \Kernel\Tenant\Model\Permission::fromString(\Kernel\Tenant\Model\TenantPermissions::ACCESS),
    \Kernel\Tenant\Model\Permission::fromString(\Kernel\Tenant\Model\TenantPermissions::MANAGE)]);
$denied(static fn()=> $reviews->review($actor,$campaignId,$run['id'],'ACCEPT','Self review denied','self-review-01'),'Requester reviewed own advice.');
$approved=$reviews->review($reviewer,$campaignId,$run['id'],'ACCEPT','Useful planning recommendation','review-ai-01');
$assert($approved['decision']==='ACCEPT'&&!$approved['paid_activation_approved']&&!$approved['finance_cost_confirmed'],'Review granted external authority.');
$again=$reviews->review($reviewer,$campaignId,$run['id'],'ACCEPT','Useful planning recommendation','review-ai-01');
$assert($again['decision']==='ACCEPT','Independent review replay lost.');
$denied(static fn()=> $reviews->review($reviewer,$campaignId,$run['id'],'REJECT','Different decision','review-ai-01'),'Decision replay conflict accepted.');
$denied(static fn()=> $reviews->recover($reviewer,$campaignId,$uncertain['id'],'Verified termination','operator-check-01',true,'recover-ai-01'),'Early recovery allowed.');
$age=$db->prepare('UPDATE marketing_analyst_runs SET created_at=CURRENT_TIMESTAMP(6)-INTERVAL 16 MINUTE WHERE organization_id=? AND id=?');
$age->execute([$orgA,$uncertain['id']]);
$denied(static fn()=> $reviews->recover($reviewer,$campaignId,$uncertain['id'],'Missing termination','operator-check-01',false,'recover-ai-01'),'Missing stop attestation accepted.');
$recovered=$reviews->recover($reviewer,$campaignId,$uncertain['id'],'Verified termination','operator-check-01',true,'recover-ai-01');
$assert($recovered['state']==='UNCERTAIN'&&$recovered['decision']==='RECOVERED_UNCERTAIN'&&$recovered['advice']===null,'Recovery falsely completed advice.');
$denied(static fn()=> $execution->request($actor,$campaignId,1,$creativeId,3,$set['id'],2,'hermes-run-03'),'Recovery restored consumed quota.');
$pending=$runStore->claim($orgA,'fixture-interrupted-01',[
    'request_hash'=>str_repeat('a',64),'requested_by'=>'101','profile_ref'=>'hermes-fixture','cost_control_ref'=>'fixture-cap',
    'campaign_id'=>$campaignId,'context_hash'=>$envelope['context_hash'],'source_pins'=>$envelope['context']['sources']
],3);
$assert($pending['claimed']&&$pending['run']['state']==='STARTED','Crash fixture admission failed.');
$denied(static fn()=> $runStore->claim($orgA,'fixture-interrupted-02',[
    'request_hash'=>str_repeat('b',64),'requested_by'=>'101','profile_ref'=>'hermes-fixture','cost_control_ref'=>'fixture-cap',
    'campaign_id'=>$campaignId,'context_hash'=>$envelope['context_hash'],'source_pins'=>$envelope['context']['sources']
],4),'Unresolved STARTED admission allowed another execution.');
$denied(static fn()=> $runStore->finish($orgA,$uncertain['id'],null),'Completed receipt was overwritten.');
$age->execute([$orgA,$pending['run']['id']]);
$recovered=$reviews->recover($reviewer,$campaignId,$pending['run']['id'],'Worker and profile stopped','operator-check-02',true,'recover-ai-02');
$assert($recovered['state']==='UNCERTAIN'&&$recovered['failure_code']==='OPERATOR_RECOVERED_UNCERTAIN','STARTED recovery failed.');
$denied(static fn()=> $runStore->finish($orgA,$pending['run']['id'],$advice),'Late runtime completion overwrote recovery.');
$transport->incomplete=false;$transport->usage=null;
$extended=new \Domains\Marketing\Application\Service\MarketingAnalystExecutionService($contexts,$runtime,$runStore,
    new \Domains\Marketing\Domain\MarketingAnalystAdmissionPolicy($orgA,'hermes-fixture',4,'fixture-provider-cap',true),
    new \Domains\Marketing\Domain\MarketingAnalystRecommendationPolicy(),$modules);
$staleRun=$extended->request($actor,$campaignId,1,$creativeId,3,$set['id'],2,'hermes-run-stale');
$assert($staleRun['state']==='SUCCEEDED','Recovery failed to unblock new admission.');
$repo->reviseCreative($orgA,$creativeId,'101',3,[...$creativeData,'headline'=>'Revised after analyst run'],'after-analyst-01');
$denied(static fn()=> $reviews->review($reviewer,$campaignId,$staleRun['id'],'ACCEPT','Stale advice test','review-stale-ai'),'Stale AI advice accepted.');
$rejected=$reviews->review($reviewer,$campaignId,$staleRun['id'],'REJECT','Dismiss stale advice','review-stale-ai');
$assert($rejected['decision']==='REJECT','Stale advice cannot be dismissed.');
$assert(count($reviews->listRuns($reviewer,$campaignId))===4,'Journal count mismatch.');
$denied(static fn()=> $reviews->listRuns($contextActor($orgB),$campaignId),'Foreign journal exposed.');
$budgetRepo=new \Infrastructure\Llm\MysqlLlmGovernanceRepository($db);
$budgetConfig=new \Domains\Marketing\Infrastructure\Hermes\HermesAnalystRuntimeConfiguration($orgA,[
    'enabled'=>true,'profile_ref'=>'mkt-budget-fixture','profile_url'=>'https://hermes.example.com/p/mkt-budget-fixture',
    'credential_ref'=>'env://TEST_HERMES_SECRET','daily_run_limit'=>4,'cost_control_ref'=>'provider-cap-fixture',
    'isolation_evidence_ref'=>'isolation-fixture','accepted_until'=>gmdate('Y-m-d\\TH:i:s\\Z',time()+86400),'max_cost_usd'=>'0.2500']);
$mockBudgetRuntime=new class implements \Domains\Marketing\Application\Contract\MarketingAnalystRuntimePort {
    public int $calls=0;
    public function recommend(\Kernel\Tenant\Model\TenantContext $actor,array $envelope,string $correlationId):array{
        $this->calls++;throw new RuntimeException('Fixture remote uncertainty.');
    }
};
$budgeted=new \Domains\Marketing\Infrastructure\Hermes\KernelBudgetedHermesAnalystRuntime($mockBudgetRuntime,$budgetConfig,$budgetRepo);
$denied(static fn()=> $budgeted->recommend($actor,$envelope,'mkar-'.str_repeat('d',24)),'Missing explicit LLM budget admitted.');
$db->prepare("INSERT INTO cos_llm_budgets (organization_id,currency,monthly_limit) VALUES (?,'USD',0.2500)")->execute([$orgA]);
try{$budgeted->recommend($actor,$envelope,'mkar-'.str_repeat('d',24));throw new LogicException('Fixture expected uncertainty.');}
catch(RuntimeException $error){$assert($error->getMessage()==='Fixture remote uncertainty.','Unexpected budget/runtime failure.');}
$assert($mockBudgetRuntime->calls===1&&abs($budgetRepo->monthlySpend($orgA,'USD')-0.25)<0.000001,'Ceiling not consumed before uncertain turn.');
$q=$db->prepare("SELECT cost_source,cost_amount FROM cos_llm_usage WHERE organization_id=? AND correlation_id=?");
$q->execute([$orgA,'mkar-'.str_repeat('d',24)]);$usage=$q->fetch(PDO::FETCH_ASSOC);
$assert(is_array($usage)&&$usage['cost_source']==='AUTHORIZED_CEILING','Cost ceiling provenance lost.');
try{$budgeted->recommend($actor,$envelope,'mkar-'.str_repeat('e',24));throw new LogicException('Budget exhausted but call admitted.');}
catch(\Kernel\Llm\LlmBudgetExceededException){}
$assert($mockBudgetRuntime->calls===1,'Over-budget turn reached runtime.');
$denied(static fn()=> $extended->request($actor,$campaignId,1,$creativeId,4,null,null,'hermes-fenced-01',str_repeat('f',64)),
    'Approved stale context hash admitted.');
$assert($runStore->byKey($orgA,'hermes-fenced-01')===null,'Stale approval created run receipt.');
// M3 native funding candidate uses a current AdSet revision after the M2 stale review test.
$set3=$repo->saveAdset($orgA,$campaignId,1,'101',$set['id'],2,[...$ad,'audience_revision'=>2,
    'creatives'=>[['creative_id'=>$creativeId,'revision'=>4]]],'m3-adset-repin-01');
$paidAccounts=new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidReadRepository($db);
$account=$paidAccounts->registerAccount($orgA,'env://TEST_META_CREDENTIAL',['ad_account_id'=>'act_'.random_int(100000000,999999999),
    'currency'=>'USD','timezone'=>'Europe/Kyiv','connection_status'=>'READ_ONLY','capability_scope'=>['ads_read']]);
$financeRepo=new \Domains\Finance\Infrastructure\Persistence\MySql\MysqlFinanceBudgetRepository($db);
$financePolicy=new \Domains\Finance\Domain\BudgetControlPolicy();
$financeService=new \Domains\Finance\Application\Service\FinanceBudgetControlService($financeRepo,$financePolicy);
$fundModules=new \Kernel\Module\ActiveModuleResolver(new \Kernel\Module\ModuleCatalog([
    \Kernel\Module\ModuleDefinition::fromArray(require $root.'/app/Domains/Marketing/module.php'),
    \Kernel\Module\ModuleDefinition::fromArray(require $root.'/app/Domains/Finance/module.php')
]),new class($orgA) implements \Kernel\Module\Contract\ModuleStateRepositoryInterface{
    public function __construct(private string $org){}
    public function enabledOverride(string $organizationId,string $moduleId):?bool{return $organizationId===$this->org;}
    public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void{}
});
$fundRead=new \App\Infrastructure\Marketing\FinanceFundingReadAdapter($financeService,$fundModules);
$fundPlans=new \Domains\Marketing\Application\Service\MarketingPaidLaunchPlanService(
    new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidLaunchPlanStore($db),
    new \Domains\Marketing\Application\Service\MarketingCampaignService($campaigns,new MarketingDraftPolicy()),
    new \Domains\Marketing\Application\Service\MarketingPlanningService($repo,$policy),$paidAccounts,$fundRead,$fundModules);
$today=gmdate('Y-m-d');$end=gmdate('Y-m-d',time()+2*86400);
$fundPlan=$fundPlans->create($actor,$campaignId,1,$set3['id'],3,$account['id'],1,$today,$end,'m3-plan-01');
$assert($fundPlan['lifetime_minor']===3750&&!$fundPlan['provider_write_authorized'],'M3 amount or authority incorrect.');
$assert($fundPlans->create($actor,$campaignId,1,$set3['id'],3,$account['id'],1,$today,$end,'m3-plan-01')['id']===$fundPlan['id'],'Plan replay failed.');
$denied(static fn()=> $fundPlans->get($contextActor($orgB),$fundPlan['id']),'Foreign plan exposed.');
$denied(static fn()=> $fundPlans->create($actor,$campaignId,1,$set3['id'],3,$account['id'],1,$today,$today,'m3-plan-01'),'Conflicting plan replay.');
$budgetDraft=$financeService->createDraft($actor,['name'=>'M3 funding','source_domain'=>'marketing','source_ref'=>$fundPlan['id'],
    'currency'=>'USD','period_start'=>$today,'period_end'=>$end,'limit_minor'=>3750],'m3-envelope-01');
$missing=$fundPlans->funding($actor,$fundPlan['id'],$budgetDraft['id'],'finr-'.str_repeat('a',24));
$assert(!$missing['funding_observed'],'Missing Finance reservation accepted.');
// Test-local approved native Finance transitions. Real Workflow approval coverage belongs to Finance F1.
$financeOps=new \Domains\Finance\Application\Service\ApprovedFinanceBudgetOperations($financeRepo,$financePolicy);
$activeBudget=$financeOps->activate($orgA,$budgetDraft['id'],'202',str_repeat('a',32),'m3-trace',1,'m3-activate-01');
$reservation=$financeOps->reserve($orgA,$budgetDraft['id'],'202',str_repeat('b',32),'m3-trace',3750,$activeBudget['revision'],
    'marketing',$fundPlan['finance_operation_key'],'Fund bounded lifetime candidate','m3-reserve-01');
$funded=$fundPlans->funding($actor,$fundPlan['id'],$budgetDraft['id'],$reservation['id']);
$assert($funded['status']==='RESERVATION_OBSERVED_NOT_AUTHORIZED'&&$funded['funding_observed']
    &&!$funded['paid_activation_approved']&&!$funded['provider_write_authorized'],'Funding observation falsely authorized or failed.');
$financeOps->release($orgA,$reservation['id'],'202',str_repeat('c',32),'m3-trace','Release test reservation','m3-release-01');
$assert(!$fundPlans->funding($actor,$fundPlan['id'],$budgetDraft['id'],$reservation['id'])['funding_observed'],'Released reservation remained funded.');
$repo->reviseCreative($orgA,$creativeId,'101',4,[...$creativeData,'headline'=>'New revision after funding'],'m3-stale-creative-01');
$assert($fundPlans->funding($actor,$fundPlan['id'],$budgetDraft['id'],$reservation['id'])['status']==='SOURCE_STALE','Stale funding plan not detected.');
$journal=new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidOperationJournal($db);
$operation=$journal->begin($orgA,$fundPlan['id'],'CREATE_CAMPAIGN',str_repeat('d',32),str_repeat('e',64));
$assert($operation['fresh']&&$operation['state']==='STARTED','Provider admission not durable.');
$assert(!$journal->begin($orgA,$fundPlan['id'],'CREATE_CAMPAIGN',str_repeat('d',32),str_repeat('e',64))['fresh'],'Provider replay authorized.');
$denied(static fn()=> $journal->begin($orgA,$fundPlan['id'],'CREATE_CAMPAIGN',str_repeat('f',32),str_repeat('e',64)),'Second Action reused operation.');
$journal->identify($orgA,$fundPlan['id'],'CREATE_CAMPAIGN',str_repeat('d',32),'123456');
$journal->finish($orgA,$fundPlan['id'],'CREATE_CAMPAIGN',str_repeat('d',32),null);
$assert($journal->get($orgA,$fundPlan['id'],'CREATE_CAMPAIGN')['provider_id']==='123456'
    &&$journal->get($orgA,$fundPlan['id'],'CREATE_CAMPAIGN')['state']==='UNCERTAIN','Uncertain receipt lost provider identity.');
$denied(static fn()=> $journal->finish($orgA,$fundPlan['id'],'CREATE_CAMPAIGN',str_repeat('d',32),['status'=>'PAUSED']),'Uncertain receipt overwritten.');
$assert($journal->get($orgB,$fundPlan['id'],'CREATE_CAMPAIGN')===null,'Cross-tenant journal leaked.');
echo "Marketing M2 real MySQL two-person review, tenant fence, immutable Creative revisions, stale gate PASS\n";


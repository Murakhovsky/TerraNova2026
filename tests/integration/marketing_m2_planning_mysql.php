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
    'product_ref'=>'prod-future-1',
    'target_spec'=>['market_countries'=>['UA','PL'],'audience_hypothesis'=>'Broad local product demand'],
]);
$creative=$repo->createCreative($orgA,$campaignId,'101',$creativeData,'creative-create-01');
$creativeId=$creative['id'];
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
echo "Marketing M2 real MySQL two-person review, tenant fence, immutable Creative revisions, stale gate PASS\n";


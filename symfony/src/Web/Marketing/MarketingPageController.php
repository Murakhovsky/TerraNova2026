<?php
declare(strict_types=1);
namespace App\Web\Marketing;

use App\Security\SessionCsrfValidator;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Domains\Marketing\Application\Service\MarketingCampaignService;
use Domains\Marketing\Application\Service\MarketingPlanningService;
use Domains\Marketing\Application\Service\MarketingProductDraftCheckService;
use Domains\Marketing\Application\Service\MarketingPaidReadService;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class MarketingPageController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private ActiveModuleResolver $modules,
        private WorkspaceShellFactory $shells,
        private SessionCsrfValidator $csrf,
        private MarketingCampaignService $campaigns,
        private MarketingPlanningService $planning,
        private MarketingProductDraftCheckService $productChecks,
        private MarketingPaidReadService $paid,
    ){}

    public function overview(Request $request):Response {return $this->render($request,'overview');}
    public function campaigns(Request $request):Response {return $this->render($request,'campaigns');}
    public function accounts(Request $request):Response {return $this->render($request,'accounts');}
    public function performance(Request $request):Response {return $this->render($request,'performance');}
    public function actions(Request $request):Response {return $this->render($request,'actions');}

    public function campaign(Request $request,string $id):Response
    {
        $actor=$this->manager();
        if($actor instanceof Response)return $actor;
        try {
            $campaign=$this->campaigns->get($actor,$id);
            if($campaign===null)return new Response('Campaign not found',404);
            return $this->render($request,'campaign',$campaign);
        }catch(Throwable $e){error_log('marketing.workspace.campaign_read_failed '.$e::class);return new Response('Marketing temporarily unavailable.',503);}
    }

    public function create(Request $request):Response
    {
        $actor=$this->manager();
        if($actor instanceof Response)return $actor;
        if(!$this->csrf->isValid($request))return new Response('CSRF validation failed',400);
        try {
            $refs=preg_split('/[\s,]+/',trim((string)$request->request->get('product_refs','')), -1,PREG_SPLIT_NO_EMPTY)?:[];
            $created=$this->campaigns->createDraft($actor,[
                'name'=>(string)$request->request->get('name',''),
                'business_objective'=>(string)$request->request->get('business_objective',''),
                'brief'=>(string)$request->request->get('brief',''),
                'product_refs'=>$refs,
            ],(string)$request->request->get('idempotency_key',''));
            return new RedirectResponse('/marketing/campaigns/'.$created['id'],303);
        }catch(DomainException $e){return new Response('Draft validation blocked: '.$e->getMessage(),422);}
        catch(Throwable $e){error_log('marketing.workspace.create_failed '.$e::class);return new Response('Draft creation failed; check operation receipt before resubmitting.',503);}
    }


    public function createCreative(Request $request,string $id):Response
    {
        $actor=$this->manager();
        if($actor instanceof Response)return $actor;
        if(!$this->csrf->isValid($request))return new Response('CSRF validation failed',400);
        try{
            $this->planning->createCreative($actor,$id,$this->creativeInput($request),
                (string)$request->request->get('idempotency_key',''));
            return new RedirectResponse('/marketing/campaigns/'.$id,303);
        }catch(DomainException $e){return new Response('Creative planning denied: '.$e->getMessage(),422);}
        catch(Throwable $e){error_log('marketing.workspace.creative_create_failed '.$e::class);return new Response('Creative creation uncertain; check receipt before repeating.',503);}
    }

    public function reviseCreative(Request $request,string $id,string $creativeId):Response
    {
        $actor=$this->manager();
        if($actor instanceof Response)return $actor;
        if(!$this->csrf->isValid($request))return new Response('CSRF validation failed',400);
        try{
            $owned=false;
            foreach($this->planning->creatives($actor,$id) as $c){if($c['id']===$creativeId)$owned=true;}
            if(!$owned)return new Response('Creative not found in this campaign',404);
            $revision=(int)$request->request->get('expected_revision',0);
            $this->planning->reviseCreative($actor,$creativeId,$revision,
                $this->creativeInput($request),(string)$request->request->get('idempotency_key',''));
            return new RedirectResponse('/marketing/campaigns/'.$id,303);
        }catch(DomainException $e){return new Response('Creative revision denied: '.$e->getMessage(),422);}
        catch(Throwable $e){error_log('marketing.workspace.creative_revise_failed '.$e::class);return new Response('Creative revision uncertain; inspect native receipt.',503);}
    }

    public function propose(Request $request,string $id):Response
    {
        $actor=$this->manager();
        if($actor instanceof Response)return $actor;
        if(!$this->csrf->isValid($request))return new Response('CSRF validation failed',400);
        try{
            $this->planning->submitProposal($actor,$id,
                (string)$request->request->get('creative_id',''),
                (int)$request->request->get('campaign_revision',0),
                (int)$request->request->get('creative_revision',0),[
                    'recommendation'=>(string)$request->request->get('recommendation',''),
                    'rationale'=>(string)$request->request->get('rationale',''),
                    'evidence'=>[[
                        'source_type'=>'MANUAL_NOTE',
                        'reference'=>(string)$request->request->get('evidence_ref',''),
                        'note'=>(string)$request->request->get('evidence_note',''),
                    ]],
                ],(string)$request->request->get('idempotency_key',''));
            return new RedirectResponse('/marketing/campaigns/'.$id,303);
        }catch(DomainException $e){return new Response('Proposal denied: '.$e->getMessage(),422);}
        catch(Throwable $e){error_log('marketing.workspace.proposal_failed '.$e::class);return new Response('Proposal creation uncertain; check receipt.',503);}
    }

    public function review(Request $request,string $id,string $proposalId):Response
    {
        $actor=$this->manager();
        if($actor instanceof Response)return $actor;
        if(!$this->csrf->isValid($request))return new Response('CSRF validation failed',400);
        try{
            $owned=false;
            foreach($this->planning->proposals($actor,$id) as $p){if($p['id']===$proposalId)$owned=true;}
            if(!$owned)return new Response('Proposal not found in this campaign',404);
            $this->planning->reviewProposal($actor,$proposalId,
                (int)$request->request->get('expected_revision',0),[
                    'decision'=>(string)$request->request->get('decision',''),
                    'reason'=>(string)$request->request->get('reason',''),
                ],(string)$request->request->get('idempotency_key',''));
            return new RedirectResponse('/marketing/campaigns/'.$id,303);
        }catch(DomainException $e){return new Response('Review denied: '.$e->getMessage(),422);}
        catch(Throwable $e){error_log('marketing.workspace.review_failed '.$e::class);return new Response('Review uncertain; check receipt.',503);}
    }

    /** @return array<string,mixed> */
    private function creativeInput(Request $request):array
    {
        $product=trim((string)$request->request->get('product_ref',''));
        $media=trim((string)$request->request->get('media_sha256',''));
        $content = [
            'headline'=>(string)$request->request->get('headline',''),
            'primary_text'=>(string)$request->request->get('primary_text',''),
            'call_to_action'=>(string)$request->request->get('call_to_action',''),
            'destination_url'=>(string)$request->request->get('destination_url',''),
            'language'=>(string)$request->request->get('language','uk'),
            'product_ref'=>$product!==''?$product:null,
            'media_sha256'=>$media!==''?$media:null,
        ];
        $markets = trim((string)$request->request->get('target_market_countries',''));
        $hypothesis = trim((string)$request->request->get('target_audience_hypothesis',''));
        if ($markets !== '' || $hypothesis !== '') {
            $content['target_spec'] = [
                'market_countries'=>array_map(static fn(string $code): string => strtoupper(trim($code)), explode(',', $markets)),
                'audience_hypothesis'=>$hypothesis,
            ];
        }
        return $content;
    }

    public function connect(Request $request):Response
    {
        $actor=$this->manager();
        if($actor instanceof Response)return $actor;
        if(!$this->csrf->isValid($request))return new Response('CSRF validation failed',400);
        try {
            $this->paid->connect($actor,
                (string)$request->request->get('ad_account_id',''),
                (string)$request->request->get('credential_ref',''));
            return new RedirectResponse('/marketing/accounts',303);
        }catch(DomainException $e){return new Response('Meta connection blocked: '.$e->getMessage(),422);}
        catch(Throwable $e){error_log('marketing.workspace.connect_failed '.$e::class);return new Response('Meta connection unavailable. Check server logs and Vault configuration.',503);}
    }

    public function sync(Request $request,string $id):Response
    {
        $actor=$this->manager();
        if($actor instanceof Response)return $actor;
        if(!$this->csrf->isValid($request))return new Response('CSRF validation failed',400);
        try{
            $this->paid->sync($actor,$id,
                (string)$request->request->get('from',''),
                (string)$request->request->get('to',''));
            return new RedirectResponse('/marketing/performance?account='.$id,303);
        }catch(DomainException $e){return new Response('Meta sync blocked: '.$e->getMessage(),422);}
        catch(Throwable $e){error_log('marketing.workspace.sync_failed '.$e::class);return new Response('Meta read sync failed; existing snapshots were retained.',503);}
    }

    private function render(Request $request,string $section,?array $selected=null):Response
    {
        $actor=$this->manager();
        if($actor instanceof Response)return $actor;
        try {
            $creatives=[];$proposals=[];$productCheck=null;$productCheckError=null;$productPage=null;$productSearchError=null;
            if($section==='campaign'&&is_array($selected)&&is_string($selected['id']??null)){
                $creatives=$this->planning->creatives($actor,$selected['id']);
                $proposals=$this->planning->proposals($actor,$selected['id']);
                if($request->query->has('product_q')){
                    try{
                        $productPage=$this->productChecks->search($actor,(string)$request->query->get('product_q',''),20,
                            $request->query->getInt('product_offset',0));
                    }catch(DomainException){
                        $productSearchError='Product search unavailable: verify module activation, permissions and search bounds.';
                    }
                }
                // Operator-initiated native Product readback; never cached as an
                // approved Creative or paid activation decision.
                if($request->query->has('product_id')){
                    try{
                        $variant=trim((string)$request->query->get('variant_id',''));
                        $productCheck=$this->productChecks->check(
                            $actor,trim((string)$request->query->get('product_id','')),
                            $variant!==''?$variant:null,
                            strtoupper(trim((string)$request->query->get('currency','USD')))
                        );
                    }catch(DomainException){
                        $productCheckError='Product check unavailable: verify Product module activation, reference and tenant permissions.';
                    }
                }
            }
            $accounts=$this->paid->accounts($actor);
            $accountHealth=[];
            if(in_array($section,['accounts','actions'],true)){
                $nowUtc=new DateTimeImmutable('now',new DateTimeZone('UTC'));
                foreach(array_slice($accounts,0,20) as $account){
                    $attempts=$this->paid->syncHistory($actor,(string)$account['id'],10);
                    $lastSuccess=$account['last_synced_at']??null;
                    $isStale=true;
                    if(is_string($lastSuccess)&&$lastSuccess!==''){
                        $lastDate=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$lastSuccess,new DateTimeZone('UTC'));
                        if(!$lastDate)$lastDate=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$lastSuccess,new DateTimeZone('UTC'));
                        if($lastDate && $lastDate->getTimestamp()<= $nowUtc->getTimestamp()
                            &&($nowUtc->getTimestamp()-$lastDate->getTimestamp())<=86400)$isStale=false;
                    }
                    $latestFailed=isset($attempts[0])&&$attempts[0]['state']==='FAILED';
                    $accountHealth[]=[
                        'account'=>$account,'recent_runs'=>$attempts,
                        'last_attempt_failed'=>$latestFailed,
                        'stale_or_never_synced'=>$isStale,
                        'health_label'=>$latestFailed?'LAST_ATTEMPT_FAILED':($isStale?'STALE_OR_NO_SYNC':'RECENT_SYNC'),
                    ];
                }
            }
            $campaigns=$this->campaigns->search($actor,trim((string)$request->query->get('q','')),50);
            $accountId=trim((string)$request->query->get('account',''));
            if($accountId===''&&isset($accounts[0]))$accountId=(string)$accounts[0]['id'];
            $providerCampaigns=[];$providerHierarchy=['campaigns'=>[],'adsets'=>[],'ads'=>[]];$observations=[];$from='';$to='';
            if($accountId!==''){
                $account=null;foreach($accounts as $row){if($row['id']===$accountId)$account=$row;}
                if($account===null)throw new DomainException('Unknown account in tenant.');
                $tz=new DateTimeZone((string)$account['timezone']);
                $now=new DateTimeImmutable('now',$tz);
                $from=$now->modify('-6 days')->format('Y-m-d');$to=$now->format('Y-m-d');
                $providerHierarchy=$this->paid->hierarchy($actor,$accountId);
                $providerCampaigns=$providerHierarchy['campaigns'];
                $observations=$this->paid->insights($actor,$accountId,'campaign',$from,$to);
            }
            $role=$actor->role()->value();
            $titles=[
                'overview'=>'Marketing Overview','campaigns'=>'Marketing Campaigns',
                'accounts'=>'Paid Media Accounts','performance'=>'Marketing Performance',
                'actions'=>'Marketing Action Queue','campaign'=>'Campaign Workspace'
            ];
            $title=$titles[$section]??'Marketing';
            $context=new WebExtensionContext($actor->organizationId()->value(),$role,'workspace','marketing','marketing-'.$section);
            $shell=$this->shells->create($actor,$context,$title,[
                new ShellBreadcrumb('Workspace','/admin'),
                new ShellBreadcrumb('Marketing','/marketing'),
                new ShellBreadcrumb($title),
            ]);
            return new Response($this->twig->render('experience/marketing/workspace.html.twig',[
                'shell'=>$shell,'pageTitle'=>$title,'section'=>$section,
                'accountHealth'=>$accountHealth,
                'accounts'=>$accounts,'campaigns'=>$campaigns,'providerCampaigns'=>$providerCampaigns,
                'providerHierarchy'=>$providerHierarchy,
                'observations'=>$observations,'selectedCampaign'=>$selected,'accountId'=>$accountId,
                'from'=>$from,'to'=>$to,'csrfToken'=>$this->csrf->token($request),
                'creatives'=>$creatives,'proposals'=>$proposals,
                'productCheck'=>$productCheck,'productCheckError'=>$productCheckError,
                'productPage'=>$productPage,'productSearchError'=>$productSearchError,
                'currentUserId'=>$actor->userId()->value(),
                'planningKey'=>'mktplan-'.bin2hex(random_bytes(12)),
                'planningEvidenceRef'=>'human-note-'.bin2hex(random_bytes(10)),
                'draftKey'=>'mktform-'.bin2hex(random_bytes(12)),
                'query'=>$request->query->all(),
            ]),200,['Content-Type'=>'text/html; charset=UTF-8','Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow']);
        }catch(Throwable $e){
            error_log('marketing.workspace.read_failed '.$e::class);
            return new Response('Marketing read model unavailable; migration or module health needs attention.',503);
        }
    }

    private function manager():TenantContext|Response
    {
        $actor=$this->tenants->current();
        if($actor===null)return new RedirectResponse('/auth/login');
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE))
            return new Response('Forbidden',403);
        if(!$this->modules->isEnabled($actor->organizationId()->value(),'marketing'))
            return new Response('Marketing module is disabled for this tenant.',403);
        return $actor;
    }
}

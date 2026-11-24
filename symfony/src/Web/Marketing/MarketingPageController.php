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
use Domains\Marketing\Application\Service\MarketingAnalystReviewService;
use App\Infrastructure\Marketing\HermesAnalystReadinessService;
use App\Persistence\Marketing\MarketingAnalystPlanProposalService;
use Domains\Marketing\Application\Service\MarketingProductDraftCheckService;
use Domains\Marketing\Application\Service\MarketingPaidReadService;
use Domains\Marketing\Application\Service\MarketingPaidLaunchPlanService;
use Domains\Marketing\Application\Service\MarketingPaidOperationsReadService;
use App\Persistence\Marketing\MarketingPaidGoalProposalService;
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
        private MarketingAnalystReviewService $analystReviews,
        private HermesAnalystReadinessService $analystReadiness,
        private MarketingAnalystPlanProposalService $analystPlans,
        private MarketingPaidLaunchPlanService $paidPlans,
        private MarketingPaidOperationsReadService $paidOperations,
        private MarketingPaidGoalProposalService $paidGoals,
        private \Domains\Finance\Application\Service\FinancePaidSettlementReviewService $settlementReviews,
        private \App\Persistence\Finance\FinancePaidSettlementGoalProposalService $settlementGoals,
        private \Domains\Finance\Application\Contract\FinancePaidSettlementStore $settlements,
        private \App\Application\Capability\CanonicalCapabilityGateway $capabilityGateway,
    ){}

    public function overview(Request $request):Response {return $this->render($request,'overview');}
    public function campaigns(Request $request):Response {return $this->render($request,'campaigns');}
    public function accounts(Request $request):Response {return $this->render($request,'accounts');}
    public function performance(Request $request):Response {return $this->render($request,'performance');}
    public function syncMediaAnalytics(Request $request):Response
    {
        $actor=$this->manager();if($actor instanceof Response)return $actor;
        if(!$this->csrf->isValid($request))return new Response('CSRF validation failed',400);
        try{
            $f=$request->request->all();$input=['account_id'=>MarketingWorkspacePlanningInput::field($f,'media_account'),
                'grain'=>MarketingWorkspacePlanningInput::field($f,'media_grain'),'window_start'=>MarketingWorkspacePlanningInput::field($f,'media_from'),
                'window_end'=>MarketingWorkspacePlanningInput::field($f,'media_to')];
            $this->capabilityGateway->invoke($actor,'marketing.analytics.media.sync',$input,
                'human_ui',MarketingWorkspacePlanningInput::field($f,'idempotency_key'),'m4-media-'.bin2hex(random_bytes(12)));
            return new RedirectResponse('/marketing/performance?'.http_build_query(['media_account'=>$input['account_id'],
                'media_grain'=>$input['grain'],'media_from'=>$input['window_start'],'media_to'=>$input['window_end']]),303);
        }catch(DomainException $e){return new Response('Media read import blocked: '.$e->getMessage(),422);}
        catch(Throwable){return new Response('Media read import unavailable; inspect source and native receipt.',503);}
    }
    public function captureCommerceAttribution(Request $request):Response
    {
        $actor=$this->manager();if($actor instanceof Response)return $actor;
        if(!$this->csrf->isValid($request))return new Response('CSRF validation failed',400);
        try{
            $f=$request->request->all();$campaign=MarketingWorkspacePlanningInput::field($f,'campaign_id');
            $plan=MarketingWorkspacePlanningInput::field($f,'plan_id');$ids=MarketingWorkspacePlanningInput::field($f,'order_ids');
            if(strlen($ids)>1700)throw new DomainException('Order attribution batch exceeds input bound.');
            $receipt=$this->capabilityGateway->invoke($actor,'marketing.attribution.commerce.capture',
                ['campaign_id'=>$campaign,'plan_id'=>$plan===''?null:$plan,'order_ids'=>preg_split('/[\s,]+/',$ids,-1,PREG_SPLIT_NO_EMPTY)?:[]],
                'human_ui',MarketingWorkspacePlanningInput::field($f,'idempotency_key'),'commerce-'.bin2hex(random_bytes(12)));
            return $this->render($request,'performance',null,null,$receipt);
        }catch(DomainException){return new Response('Commerce attribution blocked; verify exact native Order scope.',422);}
        catch(Throwable){return new Response('Commerce attribution unavailable; inspect native receipt.',503);}
    }
    public function captureAttribution(Request $request):Response
    {
        $actor=$this->manager();if($actor instanceof Response)return $actor;
        if(!$this->csrf->isValid($request))return new Response('CSRF validation failed',400);
        try{
            $f=$request->request->all();$campaign=MarketingWorkspacePlanningInput::field($f,'campaign_id');
            $plan=MarketingWorkspacePlanningInput::field($f,'plan_id');$ids=MarketingWorkspacePlanningInput::field($f,'lead_ids');
            if(strlen($ids)>1000)throw new DomainException('Attribution batch exceeds input bound.');
            $leadIds=preg_split('/[\s,]+/',$ids,-1,PREG_SPLIT_NO_EMPTY)?:[];
            $receipt=$this->capabilityGateway->invoke($actor,'marketing.attribution.capture',
                ['campaign_id'=>$campaign,'plan_id'=>$plan===''?null:$plan,'lead_ids'=>$leadIds],
                'human_ui',MarketingWorkspacePlanningInput::field($f,'idempotency_key'),'m4-'.bin2hex(random_bytes(12)));
            return $this->render($request,'performance',null,$receipt);
        }catch(DomainException $e){return new Response('Attribution capture blocked: '.$e->getMessage(),422);}
        catch(Throwable){return new Response('Attribution capture unavailable; inspect native receipt.',503);}
    }
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


    public function createPaidPlan(Request $request,string $id):Response
    {
        $actor=$this->manager();if($actor instanceof Response)return $actor;
        if(!$this->csrf->isValid($request))return new Response('CSRF validation failed',400);
        try{
            $form=$request->request->all();
            [$adset,$adsetRevision]=MarketingWorkspacePlanningInput::analystPin($form,'adset_pin','mkas-');
            [$account,$accountRevision]=MarketingWorkspacePlanningInput::analystPin($form,'account_pin','mads-');
            $plan=$this->paidPlans->create($actor,$id,MarketingWorkspacePlanningInput::revision($form,'campaign_revision'),$adset,$adsetRevision,
                $account,$accountRevision,MarketingWorkspacePlanningInput::field($form,'period_start'),MarketingWorkspacePlanningInput::field($form,'period_end'),
                MarketingWorkspacePlanningInput::field($form,'idempotency_key'));
            return new RedirectResponse('/marketing/campaigns/'.$id.'?paid_plan='.$plan['id'],303);
        }catch(DomainException $e){return new Response('Paid plan blocked: '.$e->getMessage(),422);}
        catch(Throwable){return new Response('Paid plan unavailable; inspect native receipt.',503);}
    }

    public function proposePaidGoal(Request $request,string $id,string $planId):Response
    {
        $actor=$this->manager();if($actor instanceof Response)return $actor;
        if(!$this->csrf->isValid($request))return new Response('CSRF validation failed',400);
        try{
            if($this->paidPlans->get($actor,$planId)['campaign_id']!==$id)throw new DomainException('Paid plan campaign mismatch.');
            $form=$request->request->all();$e=MarketingWorkspacePlanningInput::field($form,'envelope_id');$r=MarketingWorkspacePlanningInput::field($form,'reservation_id');
            $this->paidGoals->propose($actor,$planId,MarketingWorkspacePlanningInput::field($form,'operation'),
                MarketingWorkspacePlanningInput::field($form,'idempotency_key'),$e===''?null:$e,$r===''?null:$r);
            return new RedirectResponse('/workspace/goals',303);
        }catch(DomainException $e){return new Response('Paid proposal blocked: '.$e->getMessage(),422);}
        catch(Throwable){return new Response('Paid proposal unavailable; inspect Goals.',503);}
    }

    public function reviewPaidSettlement(Request $request,string $id,string $planId):Response
    {
        $actor=$this->manager();if($actor instanceof Response)return $actor;
        if(!$this->csrf->isValid($request))return new Response('CSRF validation failed',400);
        try{
            $f=$request->request->all();$r=MarketingWorkspacePlanningInput::field($f,'reservation_id');
            $d=MarketingWorkspacePlanningInput::field($f,'document_ref');$s=MarketingWorkspacePlanningInput::field($f,'spend_snapshot');
            $p=$this->settlementReviews->preview($actor,$r,$d,$s);
            if($p['facts']['plan']['id']!==$planId||$this->paidPlans->get($actor,$planId)['campaign_id']!==$id)
                throw new DomainException('Settlement campaign mismatch.');
            $amount=MarketingWorkspacePlanningInput::field($f,'settlement_minor');
            if(!preg_match('/^(0|[1-9][0-9]{0,12})$/D',$amount)||(int)$amount>1000000000000||($f['confirm_review']??null)!=='yes')
                throw new DomainException('Explicit independent document review and exact amount required.');
            $this->settlementReviews->review($actor,$r,$d,$s,MarketingWorkspacePlanningInput::field($f,'document_hash'),
                MarketingWorkspacePlanningInput::field($f,'source_hash'),MarketingWorkspacePlanningInput::revision($f,'source_revision'),
                MarketingWorkspacePlanningInput::field($f,'provider_document_id'),(int)$amount);
            return new RedirectResponse('/marketing/campaigns/'.$id.'?'.http_build_query(['paid_plan'=>$planId,'settlement_reservation'=>$r,'settlement_document'=>$d,'settlement_snapshot'=>$s]),303);
        }catch(DomainException $e){return new Response('Settlement review blocked: '.$e->getMessage(),422);}
        catch(Throwable){return new Response('Settlement review unavailable; inspect native review.',503);}
    }
    public function proposePaidSettlement(Request $request,string $id,string $planId):Response
    {
        $actor=$this->manager();if($actor instanceof Response)return $actor;
        if(!$this->csrf->isValid($request))return new Response('CSRF validation failed',400);
        try{
            $f=$request->request->all();$r=MarketingWorkspacePlanningInput::field($f,'reservation_id');
            $d=MarketingWorkspacePlanningInput::field($f,'document_ref');$s=MarketingWorkspacePlanningInput::field($f,'spend_snapshot');
            $p=$this->settlementReviews->preview($actor,$r,$d,$s);
            if($p['facts']['plan']['id']!==$planId||$this->paidPlans->get($actor,$planId)['campaign_id']!==$id)
                throw new DomainException('Settlement campaign mismatch.');
            $this->settlementGoals->propose($actor,$r,$d,$s,MarketingWorkspacePlanningInput::field($f,'candidate_hash'),
                MarketingWorkspacePlanningInput::revision($f,'expected_revision'),MarketingWorkspacePlanningInput::field($f,'idempotency_key'));
            return new RedirectResponse('/workspace/goals',303);
        }catch(DomainException $e){return new Response('Settlement proposal blocked: '.$e->getMessage(),422);}
        catch(Throwable){return new Response('Settlement proposal unavailable; inspect Goals.',503);}
    }

    public function saveAudience(Request $request,string $id):Response
    {
        $actor=$this->manager();
        if($actor instanceof Response)return $actor;
        if(!$this->csrf->isValid($request))return new Response('CSRF validation failed',400);
        try{
            if($this->campaigns->get($actor,$id)===null)return new Response('Campaign not found',404);
            $form=$request->request->all();
            [$record,$revision]=MarketingWorkspacePlanningInput::revisionPair($form,'audience_id');
            $this->planning->saveAudience($actor,$record,$revision,MarketingWorkspacePlanningInput::audience($form),
                MarketingWorkspacePlanningInput::field($form,'idempotency_key'));
            return new RedirectResponse('/marketing/campaigns/'.$id,303);
        }catch(DomainException $e){return new Response('Audience planning denied: '.$e->getMessage(),422);}
        catch(Throwable $e){error_log('marketing.workspace.audience_save_failed '.$e::class);return new Response('Audience save uncertain; inspect native receipt.',503);}
    }

    public function saveAdset(Request $request,string $id):Response
    {
        $actor=$this->manager();
        if($actor instanceof Response)return $actor;
        if(!$this->csrf->isValid($request))return new Response('CSRF validation failed',400);
        try{
            $form=$request->request->all();
            [$record,$revision]=MarketingWorkspacePlanningInput::revisionPair($form,'adset_id');
            $this->planning->saveAdset($actor,$id,MarketingWorkspacePlanningInput::revision($form,'campaign_revision'),
                $record,$revision,MarketingWorkspacePlanningInput::adset($form),
                MarketingWorkspacePlanningInput::field($form,'idempotency_key'));
            return new RedirectResponse('/marketing/campaigns/'.$id,303);
        }catch(DomainException $e){return new Response('AdSet planning denied: '.$e->getMessage(),422);}
        catch(Throwable $e){error_log('marketing.workspace.adset_save_failed '.$e::class);return new Response('AdSet save uncertain; inspect native receipt.',503);}
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

    public function proposeAnalystPlan(Request $request,string $id):Response
    {
        $actor=$this->manager();
        if($actor instanceof Response)return $actor;
        if(!$this->csrf->isValid($request))return new Response('CSRF validation failed',400);
        try{
            $form=$request->request->all();
            $creative=MarketingWorkspacePlanningInput::analystPin($form,'creative_pin','mkcr-');
            $adset=MarketingWorkspacePlanningInput::field($form,'adset_pin');
            $pin=$adset===''?[null,null]:MarketingWorkspacePlanningInput::analystPin($form,'adset_pin','mkas-');
            $this->analystPlans->propose($actor,$id,MarketingWorkspacePlanningInput::revision($form,'campaign_revision'),
                $creative[0],$creative[1],$pin[0],$pin[1],MarketingWorkspacePlanningInput::field($form,'idempotency_key'));
            return new RedirectResponse('/workspace/goals',303);
        }catch(DomainException $e){return new Response('Analyst plan denied: '.$e->getMessage(),422);}
        catch(Throwable $e){error_log('marketing.workspace.analyst_plan_failed '.$e::class);return new Response('Plan uncertain; inspect Goals.',503);}
    }

    public function reviewAnalystRun(Request $request,string $id,string $runId):Response
    {
        $actor=$this->manager();
        if($actor instanceof Response)return $actor;
        if(!$this->csrf->isValid($request))return new Response('CSRF validation failed',400);
        try{
            $form=$request->request->all();
            $this->analystReviews->review($actor,$id,$runId,MarketingWorkspacePlanningInput::field($form,'decision'),
                MarketingWorkspacePlanningInput::field($form,'reason'),MarketingWorkspacePlanningInput::field($form,'idempotency_key'));
            return new RedirectResponse('/marketing/campaigns/'.$id,303);
        }catch(DomainException $e){return new Response('Analyst review denied: '.$e->getMessage(),422);}
        catch(Throwable $e){error_log('marketing.workspace.analyst_review_failed '.$e::class);return new Response('Review uncertain; inspect receipt.',503);}
    }

    public function recoverAnalystRun(Request $request,string $id,string $runId):Response
    {
        $actor=$this->manager();
        if($actor instanceof Response)return $actor;
        if(!$this->csrf->isValid($request))return new Response('CSRF validation failed',400);
        try{
            $form=$request->request->all();
            $this->analystReviews->recover($actor,$id,$runId,MarketingWorkspacePlanningInput::field($form,'reason'),
                MarketingWorkspacePlanningInput::field($form,'evidence_ref'),
                MarketingWorkspacePlanningInput::field($form,'local_and_remote_stopped')==='1',
                MarketingWorkspacePlanningInput::field($form,'idempotency_key'));
            return new RedirectResponse('/marketing/campaigns/'.$id,303);
        }catch(DomainException $e){return new Response('Analyst recovery denied: '.$e->getMessage(),422);}
        catch(Throwable $e){error_log('marketing.workspace.analyst_recovery_failed '.$e::class);return new Response('Recovery uncertain; inspect receipt.',503);}
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
        foreach(['variant_ref','location_ref'] as $field){
            $ref=trim((string)$request->request->get($field,''));
            if($ref!=='')$content[$field]=$ref;
        }
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

    private function render(Request $request,string $section,?array $selected=null,?array $captureReceipt=null,?array $commerceReceipt=null):Response
    {
        $actor=$this->manager();
        if($actor instanceof Response)return $actor;
        try {
            $paidPlans=[];$selectedPaidPlan=null;$paidStatus=null;$paidFunding=null;$paidSpend=null;$paidError=null;$settlementPreview=null;$settlementCandidate=null;$settlementReceipt=null;$settlementError=null;
            $creatives=[];$proposals=[];$audiences=[];$adsets=[];$analystRuns=[];$analystOffset=0;$analystRuntime=null;$productCheck=null;$productCheckError=null;$productPage=null;$productSearchError=null;
            if($section==='campaign'&&is_array($selected)&&is_string($selected['id']??null)){
                $paidPlans=$this->paidPlans->listForCampaign($actor,$selected['id']);
                if($request->query->has('paid_plan')){
                    try{
                        $selectedPaidPlan=$this->paidPlans->get($actor,(string)$request->query->get('paid_plan'));
                        if($selectedPaidPlan['campaign_id']!==$selected['id']){$selectedPaidPlan=null;throw new DomainException('Paid plan mismatch.');}
                        $paidStatus=$this->paidOperations->status($actor,$selectedPaidPlan['id']);
                        $envelope=(string)$request->query->get('envelope_id','');$reservation=(string)$request->query->get('reservation_id','');
                        if($envelope!==''||$reservation!=='')$paidFunding=$this->paidPlans->funding($actor,$selectedPaidPlan['id'],$envelope,$reservation);
                        $snapshot=(string)$request->query->get('spend_snapshot','');
                        if($snapshot!=='')$paidSpend=$this->paidOperations->compareSpend($actor,$selectedPaidPlan['id'],$snapshot);
                        if($request->query->has('settlement_reservation')){
                            try{
                                $r=(string)$request->query->get('settlement_reservation');$d=(string)$request->query->get('settlement_document');
                                $s=(string)$request->query->get('settlement_snapshot');
                                $p=$this->settlementReviews->preview($actor,$r,$d,$s);
                                if($p['facts']['plan']['id']!==$selectedPaidPlan['id'])throw new DomainException('Settlement scope differs.');
                                $settlementPreview=$p;
                                $settlementReceipt=$this->settlements->receipt($actor->organizationId()->value(),$r);
                                if($settlementReceipt===null){
                                    try{$settlementCandidate=$this->settlementReviews->read($actor,$r,$d,$s);}
                                    catch(DomainException){/* No current independent review. */}
                                }
                            }catch(Throwable){$settlementError='Settlement source or document unavailable for this exact scope.';}
                        }
                    }catch(DomainException){$paidError='Paid plan, funding or spend evidence unavailable for this exact scope.';}
                }
                $creatives=$this->planning->creatives($actor,$selected['id']);
                $proposals=$this->planning->proposals($actor,$selected['id']);
                $audiences=$this->planning->audiences($actor);
                $adsets=$this->planning->adsets($actor,$selected['id']);
                $analystOffset=max(0,min(1000000,$request->query->getInt('analyst_offset',0)));
                $analystRuns=$this->analystReviews->listRuns($actor,$selected['id'],50,$analystOffset);
                $analystRuntime=$this->analystReadiness->status($actor);
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
                        $location=trim((string)$request->query->get('location_id',''));
                        $destination=trim((string)$request->query->get('destination_url',''));
                        $productCheck=$this->productChecks->check(
                            $actor,trim((string)$request->query->get('product_id','')),
                            $variant!==''?$variant:null,
                            strtoupper(trim((string)$request->query->get('currency','USD'))),
                            $location!==''?$location:null,$destination!==''?$destination:null
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
            $analytics=null;$analyticsDiscovery=null;$analyticsTracking=null;$analyticsError=null;$analyticsPlans=[];
            $analyticsFrom=gmdate('Y-m-d',time()-6*86400);$analyticsTo=gmdate('Y-m-d');
            if($section==='performance'&&$request->query->has('analytics_campaign')){
                try{
                    $q=$request->query->all();$campaign=MarketingWorkspacePlanningInput::field($q,'analytics_campaign');
                    $plan=MarketingWorkspacePlanningInput::field($q,'analytics_plan');$plan=$plan===''?null:$plan;
                    $analyticsFrom=MarketingWorkspacePlanningInput::field($q,'analytics_from',$analyticsFrom);
                    $analyticsTo=MarketingWorkspacePlanningInput::field($q,'analytics_to',$analyticsTo);
                    $snapshot=MarketingWorkspacePlanningInput::field($q,'analytics_snapshot');$reservation=MarketingWorkspacePlanningInput::field($q,'analytics_reservation');
                    $analyticsPlans=$this->paidPlans->listForCampaign($actor,$campaign);
                    $base=['campaign_id'=>$campaign,'plan_id'=>$plan];
                    $trace='m4-'.bin2hex(random_bytes(12));
                    $analyticsTracking=$this->capabilityGateway->invoke($actor,'marketing.attribution.tracking.get',
                        [...$base,'source'=>(MarketingWorkspacePlanningInput::field($q,'analytics_source')?:($plan===null?'website':'meta')),
                            'medium'=>(MarketingWorkspacePlanningInput::field($q,'analytics_medium')?:($plan===null?'organic':'paid_social'))],
                        'human_ui','',$trace);
                    $analytics=$this->capabilityGateway->invoke($actor,'marketing.analytics.report.get',
                        [...$base,'window_start'=>$analyticsFrom,'window_end'=>$analyticsTo,'snapshot_ref'=>$snapshot===''?null:$snapshot,
                            'reservation_id'=>$reservation===''?null:$reservation],'human_ui','',$trace);
                    if($request->query->has('analytics_discover')){
                        $offset=$request->query->getInt('analytics_offset',0);
                        $analyticsDiscovery=$this->capabilityGateway->invoke($actor,'marketing.attribution.discover',
                            [...$base,'window_start'=>$analyticsFrom,'window_end'=>$analyticsTo,'offset'=>$offset],'human_ui','',$trace);
                    }
                }catch(Throwable $e){$analyticsError='Analytics scope unavailable: verify active source modules, exact campaign, dates and source references.';}
            }
            $commerceReport=null;$commerceInspect=null;$commerceError=null;
            if($section==='performance'&&$request->query->has('analytics_campaign')){
                try{
                    $q=$request->query->all();$c=MarketingWorkspacePlanningInput::field($q,'analytics_campaign');
                    $p=MarketingWorkspacePlanningInput::field($q,'analytics_plan');$p=$p===''?null:$p;
                    $base=['campaign_id'=>$c,'plan_id'=>$p];
                    $trace='commerce-'.bin2hex(random_bytes(12));
                    $commerceReport=$this->capabilityGateway->invoke($actor,'marketing.analytics.commerce.report',
                        [...$base,'window_start'=>$analyticsFrom,'window_end'=>$analyticsTo],'human_ui','',$trace);
                    $oid=MarketingWorkspacePlanningInput::field($q,'commerce_order_id');
                    if($oid!=='')$commerceInspect=$this->capabilityGateway->invoke($actor,'marketing.attribution.commerce.inspect',
                        [...$base,'order_id'=>$oid],'human_ui','',$trace);
                }catch(Throwable){$commerceError='Native commerce attribution source or selected scope is unavailable. No zero-sales claim is made.';}
            }
            $mediaAnalytics=null;$mediaError=null;
            if($section==='performance'&&$request->query->has('media_account')){
                try{
                    $q=$request->query->all();$bf=MarketingWorkspacePlanningInput::field($q,'media_baseline_from');
                    $bt=MarketingWorkspacePlanningInput::field($q,'media_baseline_to');
                    $mediaAnalytics=$this->capabilityGateway->invoke($actor,'marketing.analytics.media.report',
                        ['account_id'=>MarketingWorkspacePlanningInput::field($q,'media_account'),
                            'grain'=>MarketingWorkspacePlanningInput::field($q,'media_grain'),
                            'window_start'=>MarketingWorkspacePlanningInput::field($q,'media_from'),
                            'window_end'=>MarketingWorkspacePlanningInput::field($q,'media_to'),
                            'baseline_from'=>$bf===''?null:$bf,'baseline_to'=>$bt===''?null:$bt],
                        'human_ui','','m4-media-'.bin2hex(random_bytes(12)));
                }catch(Throwable){$mediaError='No complete media receipt or comparable baseline for the exact scope. Import the required window.';}
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
                'paidPlans'=>$paidPlans,'selectedPaidPlan'=>$selectedPaidPlan,'paidStatus'=>$paidStatus,'paidFunding'=>$paidFunding,'paidSpend'=>$paidSpend,'paidError'=>$paidError,'settlementPreview'=>$settlementPreview,'settlementCandidate'=>$settlementCandidate,'settlementReceipt'=>$settlementReceipt,'settlementError'=>$settlementError,
                'creatives'=>$creatives,'proposals'=>$proposals,'audiences'=>$audiences,'adsets'=>$adsets,
                'analystRuns'=>$analystRuns,'analystOffset'=>$analystOffset,'analystRuntime'=>$analystRuntime,
                'productCheck'=>$productCheck,'productCheckError'=>$productCheckError,
                'productPage'=>$productPage,'productSearchError'=>$productSearchError,
                'currentUserId'=>$actor->userId()->value(),
                'planningKey'=>'mktplan-'.bin2hex(random_bytes(12)),
                'planningEvidenceRef'=>'human-note-'.bin2hex(random_bytes(10)),
                'draftKey'=>'mktform-'.bin2hex(random_bytes(12)),
                'query'=>$request->query->all(),'analytics'=>$analytics,'analyticsDiscovery'=>$analyticsDiscovery,
                'analyticsTracking'=>$analyticsTracking,'analyticsPlans'=>$analyticsPlans,'analyticsError'=>$analyticsError,
                'analyticsFrom'=>$analyticsFrom,'analyticsTo'=>$analyticsTo,'captureReceipt'=>$captureReceipt,
                'mediaAnalytics'=>$mediaAnalytics,'mediaError'=>$mediaError,
                'commerceReport'=>$commerceReport,'commerceInspect'=>$commerceInspect,'commerceError'=>$commerceError,'commerceReceipt'=>$commerceReceipt,
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

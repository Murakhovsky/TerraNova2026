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

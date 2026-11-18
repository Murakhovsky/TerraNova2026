<?php
declare(strict_types=1);
namespace App\Web\Marketing;

use App\Application\Capability\CanonicalCapabilityGateway;
use App\Security\SessionCsrfValidator;
use Domains\Marketing\Application\Service\MetaReadOnlyConnectionService;
use Domains\Marketing\Infrastructure\Persistence\MySqlMarketingStore;
use DomainException;
use InvalidArgumentException;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Observability\CorrelationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class MarketingPageController {
 public function __construct(
  private Environment $twig,
  private TenantContextProviderInterface $tenants,
  private ActiveModuleResolver $modules,
  private SessionCsrfValidator $csrf,
  private MySqlMarketingStore $store,
  private CanonicalCapabilityGateway $capabilities,
  private MetaReadOnlyConnectionService $meta,
 ){}

 public function overview(Request $request):Response {return $this->page($request,'overview');}
 public function campaigns(Request $request):Response {return $this->page($request,'campaigns');}
 public function accounts(Request $request):Response {return $this->page($request,'accounts');}
 public function performance(Request $request):Response {return $this->page($request,'performance');}

 public function create(Request $request):Response {
  $tenant=$this->manager();if($tenant instanceof Response)return $tenant;
  if(!$this->csrf->isValid($request))return new Response('Invalid CSRF token.',400);
  $input=[
   'name'=>trim((string)$request->request->get('name','')),
   'objective'=>trim((string)$request->request->get('objective','')),
   'brief'=>['summary'=>mb_substr(trim((string)$request->request->get('brief','')),0,4000)],
  ];
  $budget=trim((string)$request->request->get('planned_budget_minor',''));
  $currency=trim((string)$request->request->get('currency',''));
  if($budget!==''||$currency!==''){
   if(!preg_match('/^[0-9]{1,12}$/D',$budget)||!preg_match('/^[A-Z]{3}$/D',$currency))
    return new Response('Budget must be integer minor units with ISO currency.',422);
   $input['planned_budget_minor']=(int)$budget;$input['currency']=$currency;
  }
  try{
   $this->capabilities->invoke($tenant,'marketing.campaign.create',$input,'human_ui',
    trim((string)$request->request->get('idempotency_key','')),CorrelationId::generate()->value());
   return new RedirectResponse('/marketing/campaigns?notice=created',303);
  }catch(DomainException|InvalidArgumentException $e){
   return new Response('Draft blocked: '.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'),422);
  }catch(Throwable $e){
   error_log('marketing.campaign.create_failed '.$e->getMessage());
   return new Response('Draft persistence unavailable.',503);
  }
 }

 /** Vault reference is submitted, never a raw Meta access token. */
 public function connect(Request $request):Response {
  $tenant=$this->manager();if($tenant instanceof Response)return $tenant;
  if(!$this->csrf->isValid($request))return new Response('Invalid CSRF token.',400);
  try{
   $this->meta->connect($tenant->organizationId()->value(),
    trim((string)$request->request->get('external_account_id','')),
    trim((string)$request->request->get('credential_ref','')));
   return new RedirectResponse('/marketing/accounts?notice=connected',303);
  }catch(InvalidArgumentException|DomainException $e){
   return new Response('Invalid account or Vault reference.',422);
  }catch(Throwable $e){
   error_log('marketing.meta.account.connect_failed: '.get_class($e));
   return new Response('Meta verification unavailable. Account not connected.',503);
  }
 }

 public function sync(Request $request,string $id):Response {
  $tenant=$this->manager();if($tenant instanceof Response)return $tenant;
  if(!$this->csrf->isValid($request))return new Response('Invalid CSRF token.',400);
  try{
   $this->meta->sync($tenant->organizationId()->value(),$id,3);
   return new RedirectResponse('/marketing/accounts?notice=synced',303);
  }catch(Throwable $e){
   error_log('marketing.meta.sync_failed: '.get_class($e));
   return new Response('Read-only sync unavailable; check provider credentials and rate limits.',503);
  }
 }

 public function syncInsights(Request $request,string $id):Response {
  $tenant=$this->manager();if($tenant instanceof Response)return $tenant;
  if(!$this->csrf->isValid($request))return new Response('Invalid CSRF token.',400);
  $since=trim((string)$request->request->get('since',''));
  $until=trim((string)$request->request->get('until',''));
  try{
   $this->meta->syncInsights($tenant->organizationId()->value(),$id,$since,$until,3);
   return new RedirectResponse('/marketing/performance?account='.$id.'&since='.$since.'&until='.$until.'&notice=synced',303);
  }catch(InvalidArgumentException|DomainException $e){
   return new Response('Invalid Meta Insights range or account.',422);
  }catch(Throwable $e){
   error_log('marketing.meta.insights_failed: '.get_class($e));
   return new Response('Meta Insights read unavailable.',503);
  }
 }

 private function manager():TenantContext|Response {
  $actor=$this->tenants->current();
  if($actor===null)return new RedirectResponse('/auth/login');
  if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE))
   return new Response('Forbidden.',403);
  if(!$this->modules->isEnabled($actor->organizationId()->value(),'marketing'))
   return new Response('Marketing module is disabled for this organization.',403);
  return $actor;
 }

 private function page(Request $request,string $view):Response {
  $actor=$this->manager();if($actor instanceof Response)return $actor;
  $org=$actor->organizationId()->value();
  try{
   $campaigns=$this->store->campaigns($org,'',50);
   $accounts=$this->store->accounts($org);
   $selected=(string)$request->query->get('account','');
   $since=(string)$request->query->get('since',date('Y-m-01'));
   $until=(string)$request->query->get('until',date('Y-m-d'));
   $insights=$selected!==''?$this->store->insightSummary($org,$selected,'campaign',$since,$until):[];
   $html=$this->twig->render('experience/marketing/workspace.html.twig',[
    'title'=>'Marketing · '.ucfirst($view),
    'view'=>$view,'campaigns'=>$campaigns,'accounts'=>$accounts,'insights'=>$insights,
    'csrfToken'=>$this->csrf->token($request),
    'idempotencyKey'=>bin2hex(random_bytes(16)),
    'selectedAccount'=>$selected,'since'=>$since,'until'=>$until,
    'notice'=>(string)$request->query->get('notice',''),
   ]);
   return new Response($html,200,['Content-Type'=>'text/html; charset=UTF-8',
    'Cache-Control'=>'private, no-store','X-Robots-Tag'=>'noindex,nofollow']);
  }catch(Throwable $e){
   error_log('marketing.workspace.read_failed: '.get_class($e));
   return new Response('Marketing read model temporarily unavailable.',503);
  }
 }
}

<?php
declare(strict_types=1);

namespace App\Web\Sales;

use App\Security\SessionCsrfValidator;
use App\Web\Navigation\NavigationBuilder;
use App\Web\Phtml\PhtmlRenderer;
use Domains\Property\Application\Contract\PropertyCatalogInterface;
use Domains\Sales\Application\Contract\ClientCaseReadModelFactoryInterface;
use Domains\Sales\Application\Contract\SalesWorkspaceOperationalReadModelInterface;
use Domains\Sales\Application\Contract\SalesWriteServiceFactoryInterface;
use Domains\Sales\Application\Contract\SalesWriteServiceInterface;
use Domains\Sales\Application\DTO\ClientCaseCommandResult;
use Kernel\Observability\CorrelationId;
use Kernel\Operations\Contract\OperationsReadModelInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class ClientCasePageController
{
    public function __construct(
        private PhtmlRenderer $renderer,
        private TenantContextProviderInterface $tenants,
        private NavigationBuilder $navigation,
        private ClientCaseReadModelFactoryInterface $cases,
        private SalesWorkspaceOperationalReadModelInterface $sales,
        private PropertyCatalogInterface $catalog,
        private SalesWriteServiceFactoryInterface $writes,
        private OperationsReadModelInterface $operations,
        private SessionCsrfValidator $csrf,
    ) {}

    public function show(Request $request,string $id): Response
    {
        $tenant=$this->manager(); if($tenant instanceof Response)return $tenant;
        $caseId=(int)$id; $read=$this->cases->forOrganization($tenant->organizationId()->value());
        try{
            $case=$read->case($caseId);
            if($case===null)return new Response('Client case was not found.',Response::HTTP_NOT_FOUND);
            $intelligence=['decision'=>null,'actions'=>[]];
            try{$intelligence=$this->operations->dealIntelligence($tenant->organizationId()->value(),$caseId);}catch(Throwable){}
            $pipelines=$this->sales->pipelines($tenant->organizationId()->value());
            return $this->render($request,$tenant,'Картка кейсу','cases','client_case/show',[
                'case'=>$case,'inboundRequests'=>$read->inboundRequests($caseId),'activities'=>$read->activities($caseId),
                'propertyMatches'=>$read->propertyMatches($caseId),'requestMatches'=>$read->requestMatches($caseId),
                'aiIntelligence'=>$intelligence,'managerOptions'=>$read->managerOptions(),'propertyTypes'=>$this->catalog->propertyTypes(),
                'locations'=>$this->catalog->locations(),'pipelineStages'=>$pipelines[0]['stages']??[],
                'pageStatus'=>null,'actionStatus'=>(string)$request->query->get('status_message',''),
                'csrfToken'=>$this->csrf->token($request),
            ]);
        }catch(Throwable $error){
            error_log('client-case.show.read_failed '.$error->getMessage());
            return new Response('Client case is temporarily unavailable.',Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    public function create(Request $r): Response
    {
        $t=$this->mutationTenant($r);if($t instanceof Response)return $t;
        $result=$this->write($t)->createOpportunity($r->request->all(),(int)$t->userId()->value());
        $id=(int)($result->data['case_id']??0);
        return $this->redirect($id>0?'client-case/show/'.$id:'client-case',$result);
    }
    public function update(Request $r,string $id): Response
    {
        $t=$this->mutationTenant($r);if($t instanceof Response)return $t;
        return $this->redirect('client-case/show/'.(int)$id,$this->write($t)->updateOpportunity((int)$id,$r->request->all(),(int)$t->userId()->value()));
    }
    public function quickUpdate(Request $r,string $id): Response
    {
        $t=$this->mutationTenant($r);if($t instanceof Response)return $t;
        $result=$this->write($t)->quickUpdateOpportunity((int)$id,$r->request->all(),(int)$t->userId()->value(),$this->correlation($r));
        return $this->redirect($this->returnUrl($r,'client-case'),$result);
    }
    public function activity(Request $r,string $id): Response
    {
        $t=$this->mutationTenant($r);if($t instanceof Response)return $t;
        $result=$this->write($t)->addOpportunityActivity((int)$id,$r->request->all(),(int)$t->userId()->value(),$this->correlation($r));
        return $this->redirect('client-case/show/'.(int)$id,$result);
    }
    public function updateInboundRequest(Request $r,string $id): Response
    {
        $t=$this->mutationTenant($r);if($t instanceof Response)return $t;
        $result=$this->write($t)->updateLead((int)$id,$r->request->all(),(int)$t->userId()->value(),$this->correlation($r));
        return $this->redirect($this->returnUrl($r,'client-case/inbox'),$result);
    }
    public function createFromInboundRequest(Request $r,string $id): Response
    {
        $t=$this->mutationTenant($r);if($t instanceof Response)return $t;
        $input=$r->request->all();if((int)($input['assigned_user_id']??0)<=0)$input['assigned_user_id']=(int)$t->userId()->value();
        $result=$this->write($t)->convertLeadToOpportunity((int)$id,$input,(int)$t->userId()->value(),$this->correlation($r));
        $caseId=(int)($result->data['case_id']??0);
        return $this->redirect($caseId>0?'client-case/show/'.$caseId:$this->returnUrl($r,'client-case/inbox'),$result);
    }
    public function linkInboundRequest(Request $r): Response
    {
        $t=$this->mutationTenant($r);if($t instanceof Response)return $t;
        $caseId=(int)$r->request->get('case_id',0);$leadId=(int)$r->request->get('request_id',0);
        $result=$this->write($t)->attachInboundRequest($caseId,$leadId,(int)$t->userId()->value());
        return $this->redirect($this->returnUrl($r,$caseId>0?'client-case/show/'.$caseId:'client-case/inbox'),$result);
    }
    public function updatePropertyMatch(Request $r,string $id): Response
    {
        $t=$this->mutationTenant($r);if($t instanceof Response)return $t;
        $result=$this->write($t)->updateOpportunityPropertyMatch((int)$id,$r->request->all(),(int)$t->userId()->value());
        $caseId=(int)($result->data['case_id']??0);
        return $this->redirect($caseId>0?'client-case/show/'.$caseId:'client-case',$result);
    }

    private function write(TenantContext $t): SalesWriteServiceInterface { return $this->writes->forOrganization($t->organizationId()->value()); }
    private function mutationTenant(Request $r): TenantContext|Response
    {
        $t=$this->manager();if($t instanceof Response)return $t;
        if(!$this->csrf->isValid($r))return new Response('Invalid CSRF token.',Response::HTTP_FORBIDDEN);
        return $t;
    }
    private function manager(): TenantContext|Response
    {
        $t=$this->tenants->current();if($t===null)return new RedirectResponse('/auth/login');
        if(!$t->isManager())return new Response('Forbidden',Response::HTTP_FORBIDDEN);return $t;
    }
    private function returnUrl(Request $r,string $fallback): string
    {
        $v=ltrim(trim((string)$r->request->get('return_url','')),'/');
        return $v!==''&&preg_match('#^(client-case(?:/inbox)?(?:\?[^\s]*)?|client-case/show/[1-9][0-9]*)$#',$v)?$v:$fallback;
    }
    private function redirect(string $path,ClientCaseCommandResult $result): RedirectResponse
    {
        $sep=str_contains($path,'?')?'&':'?';$m=$result->ok?'OK: '.$result->code:'Помилка: '.$result->code;
        return new RedirectResponse('/'.ltrim($path,'/').$sep.'status_message='.rawurlencode($m));
    }
    private function correlation(Request $r): string
    {
        $v=$r->attributes->get('_cos_correlation_id');return $v instanceof CorrelationId?$v->value():CorrelationId::generate()->value();
    }
    private function render(Request $r,TenantContext $t,string $title,string $active,string $view,array $extra,int $status=200): Response
    {
        $role=$t->role()->value();$vars=array_replace([
            'title'=>$title,'metaTitle'=>$title.' | Terra Nova COS','metaRobots'=>'noindex,nofollow',
            'interfaceSurface'=>'workspace','workspaceSection'=>'clients','workspaceActive'=>$active,
            'workspaceActiveSection'=>$this->navigation->activeSection($active),'pageAssetEntries'=>['clients-workspace'],
            'currentUser'=>['id'=>(int)$t->userId()->value(),'role'=>$role],'role'=>$role,'isTeam'=>true,
            'isAdmin'=>$t->isAdmin(),'workspaceNavigation'=>$this->navigation->workspace($t),
        ],$extra);
        return new Response($this->renderer->render($r,$view,$vars),$status,['Content-Type'=>'text/html; charset=UTF-8']);
    }
}

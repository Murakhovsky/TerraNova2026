<?php
declare(strict_types=1);

namespace App\Web\Sales;

use App\Security\SessionCsrfValidator;
use Domains\Sales\Application\Contract\SalesWriteServiceFactoryInterface;
use Domains\Sales\Application\Contract\SalesWriteServiceInterface;
use Domains\Sales\Application\DTO\ClientCaseCommandResult;
use Kernel\Observability\CorrelationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ClientCaseMutationController
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private SalesWriteServiceFactoryInterface $writes,
        private SessionCsrfValidator $csrf,
    ) {}

    public function create(Request $request): Response
    {
        $tenant=$this->mutationTenant($request); if($tenant instanceof Response)return $tenant;
        $result=$this->write($tenant)->createOpportunity($request->request->all(),(int)$tenant->userId()->value());
        $id=(int)($result->data['case_id']??0);
        return $this->redirect($id>0?'client-case/show/'.$id:'client-case',$result);
    }

    public function update(Request $request,string $id): Response
    {
        $tenant=$this->mutationTenant($request); if($tenant instanceof Response)return $tenant;
        return $this->redirect(
            'client-case/show/'.(int)$id,
            $this->write($tenant)->updateOpportunity((int)$id,$request->request->all(),(int)$tenant->userId()->value()),
        );
    }

    public function quickUpdate(Request $request,string $id): Response
    {
        $tenant=$this->mutationTenant($request); if($tenant instanceof Response)return $tenant;
        $result=$this->write($tenant)->quickUpdateOpportunity(
            (int)$id,$request->request->all(),(int)$tenant->userId()->value(),$this->correlation($request),
        );
        return $this->redirect($this->returnUrl($request,'client-case'),$result);
    }

    public function activity(Request $request,string $id): Response
    {
        $tenant=$this->mutationTenant($request); if($tenant instanceof Response)return $tenant;
        $result=$this->write($tenant)->addOpportunityActivity(
            (int)$id,$request->request->all(),(int)$tenant->userId()->value(),$this->correlation($request),
        );
        return $this->redirect('client-case/show/'.(int)$id,$result);
    }

    public function updateInboundRequest(Request $request,string $id): Response
    {
        $tenant=$this->mutationTenant($request); if($tenant instanceof Response)return $tenant;
        $result=$this->write($tenant)->updateLead(
            (int)$id,$request->request->all(),(int)$tenant->userId()->value(),$this->correlation($request),
        );
        return $this->redirect($this->returnUrl($request,'client-case/inbox'),$result);
    }

    public function createFromInboundRequest(Request $request,string $id): Response
    {
        $tenant=$this->mutationTenant($request); if($tenant instanceof Response)return $tenant;
        $input=$request->request->all();
        if((int)($input['assigned_user_id']??0)<=0)$input['assigned_user_id']=(int)$tenant->userId()->value();
        $result=$this->write($tenant)->convertLeadToOpportunity(
            (int)$id,$input,(int)$tenant->userId()->value(),$this->correlation($request),
        );
        $caseId=(int)($result->data['case_id']??0);
        return $this->redirect(
            $caseId>0?'client-case/show/'.$caseId:$this->returnUrl($request,'client-case/inbox'),
            $result,
        );
    }

    public function linkInboundRequest(Request $request): Response
    {
        $tenant=$this->mutationTenant($request); if($tenant instanceof Response)return $tenant;
        $caseId=(int)$request->request->get('case_id',0);
        $leadId=(int)$request->request->get('request_id',0);
        $result=$this->write($tenant)->attachInboundRequest($caseId,$leadId,(int)$tenant->userId()->value());
        return $this->redirect(
            $this->returnUrl($request,$caseId>0?'client-case/show/'.$caseId:'client-case/inbox'),
            $result,
        );
    }

    public function updatePropertyMatch(Request $request,string $id): Response
    {
        $tenant=$this->mutationTenant($request); if($tenant instanceof Response)return $tenant;
        $result=$this->write($tenant)->updateOpportunityPropertyMatch(
            (int)$id,$request->request->all(),(int)$tenant->userId()->value(),
        );
        $caseId=(int)($result->data['case_id']??0);
        return $this->redirect($caseId>0?'client-case/show/'.$caseId:'client-case',$result);
    }

    private function write(TenantContext $tenant): SalesWriteServiceInterface
    {
        return $this->writes->forOrganization($tenant->organizationId()->value());
    }

    private function mutationTenant(Request $request): TenantContext|Response
    {
        $tenant=$this->tenants->current();
        if($tenant===null)return new RedirectResponse('/auth/login');
        if(!$tenant->isManager())return new Response('Forbidden',Response::HTTP_FORBIDDEN);
        if(!$this->csrf->isValid($request))return new Response('Invalid CSRF token.',Response::HTTP_FORBIDDEN);
        return $tenant;
    }

    private function returnUrl(Request $request,string $fallback): string
    {
        $value=ltrim(trim((string)$request->request->get('return_url','')),'/');
        return $value!==''&&preg_match('#^(client-case(?:/inbox)?(?:\?[^\s]*)?|client-case/show/[1-9][0-9]*)$#',$value)
            ?$value:$fallback;
    }

    private function redirect(string $path,ClientCaseCommandResult $result): RedirectResponse
    {
        $separator=str_contains($path,'?')?'&':'?';
        $message=$result->ok?'OK: '.$result->code:'Помилка: '.$result->code;
        return new RedirectResponse('/'.ltrim($path,'/').$separator.'status_message='.rawurlencode($message));
    }

    private function correlation(Request $request): string
    {
        $value=$request->attributes->get('_cos_correlation_id');
        return $value instanceof CorrelationId?$value->value():CorrelationId::generate()->value();
    }
}

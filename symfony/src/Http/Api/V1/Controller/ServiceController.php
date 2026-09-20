<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Application\Service\Command\AssignServiceTicketCommand;
use App\Application\Service\Command\CloseServiceTicketCommand;
use App\Application\Service\Command\CreateServiceRequestCommand;
use App\Application\Service\Command\CreateServiceTicketCommand;
use App\Application\Service\Command\EscalateServiceTicketCommand;
use App\Application\Service\Command\ResolveServiceTicketCommand;
use App\Application\Service\Command\SetServiceSlaCommand;
use App\Application\Service\Query\GetServiceRequestQuery;
use App\Application\Service\Query\GetServiceTicketQuery;
use App\Security\SessionCsrfValidator;
use DomainException;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Observability\CorrelationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Throwable;

final readonly class ServiceController
{
    public function __construct(
        private QueryBusInterface $queries,
        private CommandBusInterface $commands,
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
        private ActiveModuleResolver $modules,
    ) {}

    public function request(string $id):JsonResponse
    {
        $tenant=$this->context(null,false);
        if($tenant instanceof JsonResponse)return $tenant;
        try{
            $data=$this->queries->ask(new GetServiceRequestQuery($tenant->organizationId(),$id));
            return $data===null
                ?$this->error(404,'service_request_not_found','Service request was not found.')
                :$this->ok($data);
        }catch(Throwable $error){return $this->failure($error);}
    }

    public function ticket(string $id):JsonResponse
    {
        $tenant=$this->context(null,false);
        if($tenant instanceof JsonResponse)return $tenant;
        try{
            $data=$this->queries->ask(new GetServiceTicketQuery($tenant->organizationId(),$id));
            return $data===null
                ?$this->error(404,'service_ticket_not_found','Service ticket was not found.')
                :$this->ok($data);
        }catch(Throwable $error){return $this->failure($error);}
    }

    public function createRequest(Request $request):JsonResponse
    {
        $tenant=$this->context($request,true);
        if($tenant instanceof JsonResponse)return $tenant;
        $key=$this->idempotencyKey($request);
        if($key instanceof JsonResponse)return $key;
        try{
            return $this->ok($this->commands->dispatch(new CreateServiceRequestCommand(
                $tenant->organizationId(),(int)$tenant->userId()->value(),$this->correlationId($request),$key,$this->input($request),
            )),201);
        }catch(Throwable $error){return $this->failure($error);}
    }

    public function createTicket(Request $request,string $id):JsonResponse
    {
        $tenant=$this->context($request,true);
        if($tenant instanceof JsonResponse)return $tenant;
        $key=$this->idempotencyKey($request);
        if($key instanceof JsonResponse)return $key;
        try{
            return $this->ok($this->commands->dispatch(new CreateServiceTicketCommand(
                $tenant->organizationId(),(int)$tenant->userId()->value(),$this->correlationId($request),$id,$key,$this->input($request),
            )),201);
        }catch(Throwable $error){return $this->failure($error);}
    }

    public function assign(Request $request,string $id):JsonResponse
    {
        $tenant=$this->context($request,true);
        if($tenant instanceof JsonResponse)return $tenant;
        $key=$this->idempotencyKey($request);
        if($key instanceof JsonResponse)return $key;
        $input=$this->input($request);
        try{
            return $this->ok($this->commands->dispatch(new AssignServiceTicketCommand(
                $tenant->organizationId(),(int)$tenant->userId()->value(),$this->correlationId($request),$id,
                trim((string)($input['assignee_id']??'')),$key,
            )));
        }catch(Throwable $error){return $this->failure($error);}
    }

    public function setSla(Request $request,string $id):JsonResponse
    {
        $tenant=$this->context($request,true);
        if($tenant instanceof JsonResponse)return $tenant;
        $key=$this->idempotencyKey($request);
        if($key instanceof JsonResponse)return $key;
        try{
            return $this->ok($this->commands->dispatch(new SetServiceSlaCommand(
                $tenant->organizationId(),(int)$tenant->userId()->value(),$this->correlationId($request),$id,$key,$this->input($request),
            )));
        }catch(Throwable $error){return $this->failure($error);}
    }

    public function escalate(Request $request,string $id):JsonResponse
    {
        $tenant=$this->context($request,true);
        if($tenant instanceof JsonResponse)return $tenant;
        $key=$this->idempotencyKey($request);
        if($key instanceof JsonResponse)return $key;
        $input=$this->input($request);
        try{
            return $this->ok($this->commands->dispatch(new EscalateServiceTicketCommand(
                $tenant->organizationId(),(int)$tenant->userId()->value(),$this->correlationId($request),$id,
                trim((string)($input['reason']??'')),$key,
            )));
        }catch(Throwable $error){return $this->failure($error);}
    }

    public function resolve(Request $request,string $id):JsonResponse
    {
        $tenant=$this->context($request,true);
        if($tenant instanceof JsonResponse)return $tenant;
        $key=$this->idempotencyKey($request);
        if($key instanceof JsonResponse)return $key;
        $input=$this->input($request);
        try{
            return $this->ok($this->commands->dispatch(new ResolveServiceTicketCommand(
                $tenant->organizationId(),(int)$tenant->userId()->value(),$this->correlationId($request),$id,
                trim((string)($input['summary']??'')),$key,
            )));
        }catch(Throwable $error){return $this->failure($error);}
    }

    public function close(Request $request,string $id):JsonResponse
    {
        $tenant=$this->context($request,true);
        if($tenant instanceof JsonResponse)return $tenant;
        $key=$this->idempotencyKey($request);
        if($key instanceof JsonResponse)return $key;
        try{
            return $this->ok($this->commands->dispatch(new CloseServiceTicketCommand(
                $tenant->organizationId(),(int)$tenant->userId()->value(),$this->correlationId($request),$id,$key,
            )));
        }catch(Throwable $error){return $this->failure($error);}
    }

    private function context(?Request $request,bool $mutation):TenantContext|JsonResponse
    {
        $tenant=$this->tenants->current();
        if($tenant===null)return $this->error(403,'tenant_context_required','Tenant context required.');
        if(!$tenant->allows(TenantPermissions::ACCESS)){
            return $this->error(403,'service_access_denied','Service access denied.');
        }
        if(!$this->modules->isEnabled($tenant->organizationId()->value(),'service')){
            return $this->error(403,'service_module_disabled','Service module is disabled for this organization.');
        }
        if($mutation&&(!$tenant->isManager()||!$tenant->allows(TenantPermissions::MANAGE))){
            return $this->error(403,'service_manage_required','Service manager permission required.');
        }
        if($mutation&&($request===null||!$this->csrf->isValid($request))){
            return $this->error(400,'invalid_csrf_token','Invalid CSRF token.');
        }
        $actor=$tenant->userId()->value();
        if(!ctype_digit($actor)||(int)$actor<=0)return $this->error(403,'invalid_actor','Authenticated actor is invalid.');
        return $tenant;
    }

    private function idempotencyKey(Request $request):string|JsonResponse
    {
        $key=trim((string)$request->headers->get('X-Idempotency-Key',''));
        return $key===''||mb_strlen($key)>191
            ?$this->error(422,'idempotency_key_required','A valid X-Idempotency-Key is required.')
            :$key;
    }

    /** @return array<string,mixed> */
    private function input(Request $request):array
    {
        $decoded=json_decode((string)$request->getContent(),true);
        return is_array($decoded)&&!array_is_list($decoded)?$decoded:$request->request->all();
    }

    private function correlationId(Request $request):string
    {
        $value=$request->attributes->get('_cos_correlation_id');
        return $value instanceof CorrelationId?$value->value():CorrelationId::generate()->value();
    }

    private function failure(Throwable $error):JsonResponse
    {
        $root=$error instanceof HandlerFailedException&&$error->getPrevious() instanceof Throwable
            ?$error->getPrevious():$error;
        $message=$root->getMessage();
        $normalized=strtolower($message);
        $status=match(true){
            str_contains($normalized,'not found')=>404,
            str_contains($normalized,'idempotency'),
            str_contains($normalized,'cannot'),
            str_contains($normalized,'must be resolved'),
            str_contains($normalized,'closed service request')=>409,
            $root instanceof DomainException,
            $root instanceof \InvalidArgumentException,
            $root instanceof \ValueError=>422,
            default=>500,
        };
        return $this->error($status,'service_operation_failed',$message);
    }

    private function ok(mixed $data,int $status=200):JsonResponse{return new JsonResponse(['ok'=>true,'data'=>$data],$status);}
    private function error(int $status,string $code,string $message):JsonResponse{return new JsonResponse(['ok'=>false,'error'=>$code,'message'=>$message],$status);}
}

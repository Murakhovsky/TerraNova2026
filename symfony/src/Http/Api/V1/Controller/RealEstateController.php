<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Application\RealEstate\Command\CreatePropertyOfferCommand;
use App\Application\RealEstate\Command\MatchPropertyCommand;
use App\Application\RealEstate\Command\ReserveMatchedPropertyCommand;
use App\Application\RealEstate\Command\SchedulePropertyViewingCommand;
use App\Application\RealEstate\Query\GetRealEstateCaseQuery;
use App\Security\LegacySessionCsrfValidator;
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

final readonly class RealEstateController
{
    public function __construct(
        private QueryBusInterface $queries,
        private CommandBusInterface $commands,
        private TenantContextProviderInterface $tenants,
        private LegacySessionCsrfValidator $csrf,
        private ActiveModuleResolver $modules,
    ) {}

    public function view(string $id): JsonResponse
    {
        $tenant=$this->context(null,false);
        if($tenant instanceof JsonResponse)return $tenant;

        try{
            $data=$this->queries->ask(new GetRealEstateCaseQuery($tenant->organizationId(),$id));
            return $data===null
                ?$this->error(404,'case_not_found','RealEstate brokerage case was not found.')
                :$this->ok($data);
        }catch(Throwable $error){
            return $this->failure($error);
        }
    }

    public function match(Request $request,string $id): JsonResponse
    {
        $tenant=$this->context($request,true);
        if($tenant instanceof JsonResponse)return $tenant;
        $key=$this->idempotencyKey($request);
        if($key instanceof JsonResponse)return $key;

        try{
            return $this->ok($this->commands->dispatch(new MatchPropertyCommand(
                $tenant->organizationId(),
                (int)$tenant->userId()->value(),
                $this->correlationId($request),
                (int)$id,
                $key,
                $this->input($request),
            )),201);
        }catch(Throwable $error){
            return $this->failure($error);
        }
    }

    public function offer(Request $request,string $id): JsonResponse
    {
        $tenant=$this->context($request,true);
        if($tenant instanceof JsonResponse)return $tenant;
        $key=$this->idempotencyKey($request);
        if($key instanceof JsonResponse)return $key;

        try{
            return $this->ok($this->commands->dispatch(new CreatePropertyOfferCommand(
                $tenant->organizationId(),
                (int)$tenant->userId()->value(),
                $this->correlationId($request),
                $id,
                $key,
                $this->input($request),
            )),201);
        }catch(Throwable $error){
            return $this->failure($error);
        }
    }

    public function viewing(Request $request,string $id): JsonResponse
    {
        $tenant=$this->context($request,true);
        if($tenant instanceof JsonResponse)return $tenant;
        $key=$this->idempotencyKey($request);
        if($key instanceof JsonResponse)return $key;

        try{
            return $this->ok($this->commands->dispatch(new SchedulePropertyViewingCommand(
                $tenant->organizationId(),
                (int)$tenant->userId()->value(),
                $this->correlationId($request),
                $id,
                $key,
                $this->input($request),
            )),201);
        }catch(Throwable $error){
            return $this->failure($error);
        }
    }

    public function reserve(Request $request,string $id): JsonResponse
    {
        $tenant=$this->context($request,true);
        if($tenant instanceof JsonResponse)return $tenant;
        $key=$this->idempotencyKey($request);
        if($key instanceof JsonResponse)return $key;

        try{
            return $this->ok($this->commands->dispatch(new ReserveMatchedPropertyCommand(
                $tenant->organizationId(),
                (int)$tenant->userId()->value(),
                $this->correlationId($request),
                $id,
                $key,
                $this->input($request),
            )),201);
        }catch(Throwable $error){
            return $this->failure($error);
        }
    }

    private function context(?Request $request,bool $mutation): TenantContext|JsonResponse
    {
        $tenant=$this->tenants->current();
        if($tenant===null)return $this->error(403,'tenant_context_required','Tenant context required.');
        if(!$tenant->allows(TenantPermissions::ACCESS)||!$tenant->isManager()){
            return $this->error(403,'manager_required','RealEstate manager authorization required.');
        }
        foreach(['sales','property','real_estate'] as $module){
            if(!$this->modules->isEnabled($tenant->organizationId()->value(),$module)){
                return $this->error(
                    403,
                    $module.'_module_disabled',
                    ucfirst(str_replace('_',' ',$module)).' module is disabled for this organization.',
                );
            }
        }
        if($mutation&&($request===null||!$this->csrf->isValid($request))){
            return $this->error(400,'invalid_csrf_token','Invalid CSRF token.');
        }

        $actor=$tenant->userId()->value();
        if(!ctype_digit($actor)||(int)$actor<=0)return $this->error(403,'invalid_actor','Authenticated actor is invalid.');
        return $tenant;
    }

    private function idempotencyKey(Request $request): string|JsonResponse
    {
        $key=trim((string)$request->headers->get('X-Idempotency-Key',''));
        return $key===''||mb_strlen($key)>191
            ?$this->error(422,'idempotency_key_required','A valid X-Idempotency-Key is required.')
            :$key;
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $decoded=json_decode((string)$request->getContent(),true);
        return is_array($decoded)&&!array_is_list($decoded)?$decoded:$request->request->all();
    }

    private function correlationId(Request $request): string
    {
        $value=$request->attributes->get('_cos_correlation_id');
        return $value instanceof CorrelationId?$value->value():CorrelationId::generate()->value();
    }

    private function failure(Throwable $error): JsonResponse
    {
        $root=$error instanceof HandlerFailedException&&$error->getPrevious() instanceof Throwable
            ?$error->getPrevious():$error;
        $message=$root->getMessage();
        $normalized=strtolower($message);
        $status=match(true){
            str_contains($normalized,'not found')=>404,
            str_contains($normalized,'already'),
            str_contains($normalized,'idempotency'),
            str_contains($normalized,'not reservable'),
            str_contains($normalized,'not allowed')=>409,
            $root instanceof DomainException,
            $root instanceof \InvalidArgumentException,
            $root instanceof \ValueError=>422,
            default=>500,
        };
        return $this->error($status,'real_estate_operation_failed',$message);
    }

    private function ok(mixed $data,int $status=200): JsonResponse{return new JsonResponse(['ok'=>true,'data'=>$data],$status);}
    private function error(int $status,string $code,string $message): JsonResponse{return new JsonResponse(['ok'=>false,'error'=>$code,'message'=>$message],$status);}
}

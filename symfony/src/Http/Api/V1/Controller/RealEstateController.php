<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Application\RealEstate\Command\RealEstateMutationCommand;
use App\Application\RealEstate\Query\GetRealEstateCaseQuery;
use App\Security\LegacySessionCsrfValidator;
use InvalidArgumentException;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Observability\CorrelationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

final readonly class RealEstateController
{
    public function __construct(
        private QueryBusInterface $queries,
        private CommandBusInterface $commands,
        private TenantContextProviderInterface $tenants,
        private LegacySessionCsrfValidator $csrf,
    ) {}

    public function view(string $id): JsonResponse
    {
        $tenant=$this->tenants->current();
        if($tenant===null)return $this->error(403,'tenant_context_required','Tenant context required.');
        $data=$this->queries->ask(new GetRealEstateCaseQuery($tenant->organizationId(),$id));
        return $data===null?$this->error(404,'case_not_found','RealEstate brokerage case was not found.'):$this->ok($data);
    }

    public function match(Request $request,string $id): JsonResponse
    {
        return $this->mutate($request,RealEstateMutationCommand::MATCH,(int)$id,201);
    }

    public function offer(Request $request,string $id): JsonResponse
    {
        return $this->mutate($request,RealEstateMutationCommand::OFFER,$id,201);
    }

    public function viewing(Request $request,string $id): JsonResponse
    {
        return $this->mutate($request,RealEstateMutationCommand::VIEWING,$id,201);
    }

    public function reserve(Request $request,string $id): JsonResponse
    {
        return $this->mutate($request,RealEstateMutationCommand::RESERVE,$id,201);
    }

    private function mutate(Request $request,string $operation,string|int $subjectId,int $status): JsonResponse
    {
        $context=$this->context($request);
        if($context instanceof JsonResponse)return $context;
        $key=trim((string)$request->headers->get('X-Idempotency-Key',''));
        if($key==='')return $this->error(422,'idempotency_key_required','X-Idempotency-Key is required.');

        $input=$this->input($request);
        if($operation===RealEstateMutationCommand::OFFER){
            $input['offer_id']='OFR-'.strtoupper(substr(hash('sha256',$context['organization_id']->value().':'.$key),0,20));
        }elseif($operation===RealEstateMutationCommand::VIEWING){
            $input['showing_id']='SHW-'.strtoupper(substr(hash('sha256',$context['organization_id']->value().':'.$key),0,20));
        }elseif($operation===RealEstateMutationCommand::RESERVE){
            $input['reservation_id']='RSV-'.strtoupper(substr(hash('sha256',$context['organization_id']->value().':'.$key),0,20));
        }

        try{
            return $this->ok($this->commands->dispatch(new RealEstateMutationCommand(
                $context['organization_id'],$context['actor_id'],$context['correlation_id'],$operation,$subjectId,$input,
            )),$status);
        }catch(InvalidArgumentException|\ValueError $e){
            $notFound=str_contains(strtolower($e->getMessage()),'not found');
            return $this->error($notFound?404:422,$notFound?'not_found':'validation_error',$e->getMessage());
        }catch(Throwable $e){
            return $this->error(500,'real_estate_mutation_failed',$e->getMessage());
        }
    }

    /** @return array{organization_id:\Kernel\Shared\Domain\OrganizationId,actor_id:int,correlation_id:string}|JsonResponse */
    private function context(Request $request): array|JsonResponse
    {
        $tenant=$this->tenants->current();
        if($tenant===null)return $this->error(403,'tenant_context_required','Tenant context required.');
        if(!$this->csrf->isValid($request))return $this->error(400,'invalid_csrf_token','Invalid CSRF token.');
        $actor=$tenant->userId()->value();
        if(!ctype_digit($actor)||(int)$actor<=0)return $this->error(403,'invalid_actor','Authenticated actor is invalid.');
        $correlation=$request->attributes->get('_cos_correlation_id');
        return [
            'organization_id'=>$tenant->organizationId(),
            'actor_id'=>(int)$actor,
            'correlation_id'=>$correlation instanceof CorrelationId?$correlation->value():CorrelationId::generate()->value(),
        ];
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $decoded=json_decode((string)$request->getContent(),true);
        return is_array($decoded)&&!array_is_list($decoded)?$decoded:$request->request->all();
    }
    private function ok(mixed $data,int $status=200): JsonResponse{return new JsonResponse(['ok'=>true,'data'=>$data],$status);}
    private function error(int $status,string $code,string $message): JsonResponse{return new JsonResponse(['ok'=>false,'error'=>$code,'message'=>$message],$status);}
}

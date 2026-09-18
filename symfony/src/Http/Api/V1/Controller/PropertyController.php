<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Application\Property\Command\PropertyMutationCommand;
use App\Application\Property\Query\GetPropertyQuery;
use App\Application\Property\Query\SearchPropertiesQuery;
use App\Security\LegacySessionCsrfValidator;
use InvalidArgumentException;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Observability\CorrelationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

final readonly class PropertyController
{
    public function __construct(
        private QueryBusInterface $queries,
        private CommandBusInterface $commands,
        private TenantContextProviderInterface $tenants,
        private LegacySessionCsrfValidator $csrf,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $tenant=$this->tenants->current();
        if($tenant===null)return $this->error(403,'tenant_context_required','Tenant context required.');
        return $this->ok($this->queries->ask(new SearchPropertiesQuery($tenant->organizationId(),$request->query->all())));
    }

    public function view(string $id): JsonResponse
    {
        $tenant=$this->tenants->current();
        if($tenant===null)return $this->error(403,'tenant_context_required','Tenant context required.');
        $data=$this->queries->ask(new GetPropertyQuery($tenant->organizationId(),$id));
        return $data===null?$this->error(404,'property_not_found','Property was not found.'):$this->ok($data);
    }

    public function presentation(string $id): JsonResponse
    {
        return $this->view($id);
    }

    public function create(Request $request): JsonResponse
    {
        return $this->mutate($request,PropertyMutationCommand::CREATE,null,true,201);
    }

    public function update(Request $request,string $id): JsonResponse
    {
        return $this->mutate($request,PropertyMutationCommand::UPDATE,$id,false,200);
    }

    public function createInventory(Request $request,string $id): JsonResponse
    {
        return $this->mutate($request,PropertyMutationCommand::CREATE_INVENTORY,$id,true,201);
    }

    public function changeInventoryStatus(Request $request,string $id): JsonResponse
    {
        return $this->mutate($request,PropertyMutationCommand::CHANGE_INVENTORY_STATUS,$id,false,200);
    }

    public function reserveInventory(Request $request,string $id): JsonResponse
    {
        return $this->mutate($request,PropertyMutationCommand::RESERVE_INVENTORY,$id,true,201);
    }

    private function mutate(Request $request,string $operation,?string $reference,bool $idempotent,int $status): JsonResponse
    {
        $context=$this->context($request);
        if($context instanceof JsonResponse)return $context;

        $key=trim((string)$request->headers->get('X-Idempotency-Key',''));
        if($idempotent&&$key==='')return $this->error(422,'idempotency_key_required','X-Idempotency-Key is required.');

        try{
            $data=$this->commands->dispatch(new PropertyMutationCommand(
                $context['organization_id'],$context['actor_id'],$context['correlation_id'],
                $operation,$reference,$this->input($request),$key!==''?$key:null,
            ));
            return $this->ok($data,$status);
        }catch(InvalidArgumentException|\ValueError $e){
            $code=str_contains(strtolower($e->getMessage()),'not found')?'not_found':'validation_error';
            return $this->error($code==='not_found'?404:422,$code,$e->getMessage());
        }catch(Throwable $e){
            return $this->error(500,'property_mutation_failed',$e->getMessage());
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

    private function ok(mixed $data,int $status=200): JsonResponse
    {
        return new JsonResponse(['ok'=>true,'data'=>$data],$status);
    }
    private function error(int $status,string $code,string $message): JsonResponse
    {
        return new JsonResponse(['ok'=>false,'error'=>$code,'message'=>$message],$status);
    }
}

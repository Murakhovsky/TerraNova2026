<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use DomainException;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Application\Feature\CapitalMarketsFeatureFlag;
use Domains\CapitalMarkets\Application\Feature\CapitalMarketsFeatureGate;
use Domains\CapitalMarkets\Application\Service\MarketDataAdministrationService;
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use App\Security\SessionCsrfValidator;
use InvalidArgumentException;
use JsonException;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use PDOException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;
use ValueError;

final readonly class CapitalMarketsMarketDataController
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private ActiveModuleResolver $modules,
        private CapitalMarketsAccessControlInterface $access,
        private CapitalMarketsFeatureGate $features,
        private MarketDataAdministrationService $marketData,
        private SessionCsrfValidator $csrf,
    ){}

    public function dashboard():JsonResponse
    {
        $context=$this->context(CapitalMarketsCapability::MarketDataView);
        if($context instanceof JsonResponse)return $context;
        [$tenant]=$context;
        return $this->respond(fn():array=>$this->marketData->dashboard($tenant->organizationId()->value()));
    }

    public function createSource(Request $request):JsonResponse
    {
        return $this->mutation(
            $request,
            CapitalMarketsCapability::MarketDataSourceManage,
            fn(TenantContext $tenant,int $actor,array $payload,string $correlation):array=>
                $this->marketData->createSource(
                    $tenant->organizationId()->value(),$actor,$correlation,$payload
                ),
            201,
        );
    }

    public function enableSource(Request $request,string $id):JsonResponse
    {
        return $this->mutation(
            $request,
            CapitalMarketsCapability::MarketDataSourceManage,
            fn(TenantContext $tenant,int $actor,array $payload,string $correlation):array=>
                $this->marketData->setSourceEnabled(
                    $tenant->organizationId()->value(),$actor,$correlation,$id,true
                ),
        );
    }

    public function disableSource(Request $request,string $id):JsonResponse
    {
        return $this->mutation(
            $request,
            CapitalMarketsCapability::MarketDataSourceManage,
            fn(TenantContext $tenant,int $actor,array $payload,string $correlation):array=>
                $this->marketData->setSourceEnabled(
                    $tenant->organizationId()->value(),$actor,$correlation,$id,false
                ),
        );
    }

    public function createSubscription(Request $request,string $id):JsonResponse
    {
        return $this->mutation(
            $request,
            CapitalMarketsCapability::MarketDataManage,
            fn(TenantContext $tenant,int $actor,array $payload,string $correlation):array=>
                $this->marketData->createSubscription(
                    $tenant->organizationId()->value(),$actor,$correlation,$id,$payload
                ),
            201,
        );
    }

    public function poll(Request $request,string $id):JsonResponse
    {
        return $this->mutation(
            $request,
            CapitalMarketsCapability::MarketDataManage,
            fn(TenantContext $tenant,int $actor,array $payload,string $correlation):array=>
                $this->marketData->poll(
                    $tenant->organizationId()->value(),$actor,$correlation,$id,
                    isset($payload['limit'])?(int)$payload['limit']:100
                ),
        );
    }

    /**
     * @param callable(TenantContext,int,array<string,mixed>,string):array<string,mixed> $operation
     */
    private function mutation(
        Request $request,
        CapitalMarketsCapability $capability,
        callable $operation,
        int $success=200,
    ):JsonResponse{
        $context=$this->context($capability);
        if($context instanceof JsonResponse)return $context;
        [$tenant,$actor]=$context;
        if(!$this->csrf->isValid($request))return $this->error('Invalid CSRF token.',403);

        try{
            return $this->ok(
                $operation($tenant,$actor,$this->payload($request),$this->correlation($request)),
                $success
            );
        }catch(PDOException $error){
            return $this->error('Capital Markets persistence conflict.',409);
        }catch(InvalidArgumentException|DomainException|ValueError $error){
            return $this->error($error->getMessage(),422);
        }catch(Throwable $error){
            error_log('capital_markets.market_data.api.failure '.$error->getMessage());
            return $this->error('Capital Markets market-data operation failed.',500);
        }
    }

    /** @return array{0:TenantContext,1:int}|JsonResponse */
    private function context(CapitalMarketsCapability $capability):array|JsonResponse
    {
        $tenant=$this->tenants->current();
        if($tenant===null)return $this->error('Tenant context required.',403);
        $organizationId=$tenant->organizationId()->value();
        if(!$this->modules->isEnabled($organizationId,'capital_markets'))return $this->error('Capital Markets module is disabled.',403);
        $actor=$tenant->userId()->value();
        if(!ctype_digit($actor))return $this->error('Canonical numeric actor required.',403);
        $actorId=(int)$actor;
        if(!$this->allowed($organizationId,$actorId,$capability))return $this->error('Capital Markets capability required.',403);
        if(!$this->features->enabled(CapitalMarketsFeatureFlag::DomainEnabled,$organizationId,$actor)
            ||!$this->features->enabled(CapitalMarketsFeatureFlag::MarketData,$organizationId,$actor)){
            return $this->error('Capital Markets Market Intelligence is disabled.',403);
        }
        return [$tenant,$actorId];
    }

    private function allowed(string $organizationId,int $actorId,CapitalMarketsCapability $capability):bool
    {
        if($this->access->hasCapability($organizationId,$actorId,$capability->value))return true;
        $broad=str_ends_with($capability->value,'.manage')?CapitalMarketsCapability::Manage:CapitalMarketsCapability::View;
        return $this->access->hasCapability($organizationId,$actorId,$broad->value);
    }

    /** @return array<string,mixed> */
    private function payload(Request $request):array
    {
        if(str_contains(strtolower((string)$request->headers->get('Content-Type','')),'application/json')){
            try{$data=json_decode((string)$request->getContent(),true,flags:JSON_THROW_ON_ERROR);}
            catch(JsonException){throw new InvalidArgumentException('JSON body is malformed.');}
            if(!is_array($data)||array_is_list($data))throw new InvalidArgumentException('JSON body must be an object.');
            unset($data['csrf_token']);
            return $data;
        }
        $data=$request->request->all();
        unset($data['csrf_token']);
        return $data;
    }

    private function correlation(Request $request):string
    {
        $value=trim((string)$request->headers->get('X-Correlation-Id',''));
        return $value!==''?substr($value,0,190):'CM-MD-'.strtoupper(bin2hex(random_bytes(8)));
    }

    /** @param callable():mixed $reader */
    private function respond(callable $reader):JsonResponse
    {
        try{return $this->ok($reader());}
        catch(InvalidArgumentException|DomainException|ValueError $error){return $this->error($error->getMessage(),422);}
        catch(Throwable $error){
            error_log('capital_markets.market_data.api.read_failure '.$error->getMessage());
            return $this->error('Capital Markets market-data read failed.',500);
        }
    }

    private function ok(mixed $data,int $status=200):JsonResponse
    {
        return new JsonResponse(['ok'=>true,'data'=>$data],$status);
    }

    private function error(string $message,int $status):JsonResponse
    {
        return new JsonResponse(['ok'=>false,'error'=>$message],$status);
    }
}

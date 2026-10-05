<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use DomainException;
use Domains\CapitalMarkets\Application\Command\AddInstrumentIdentifier;
use Domains\CapitalMarkets\Application\Command\CreateInstrument;
use Domains\CapitalMarkets\Application\Command\CreateRelationship;
use Domains\CapitalMarkets\Application\Command\CreateVenue;
use Domains\CapitalMarkets\Application\Command\RegisterVenueInstrument;
use Domains\CapitalMarkets\Application\Command\UpdateInstrument;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsFoundationBoundary;
use Domains\CapitalMarkets\Application\Feature\CapitalMarketsFeatureFlag;
use Domains\CapitalMarkets\Application\Feature\CapitalMarketsFeatureGate;
use Domains\CapitalMarkets\Application\Query\GetInstrument;
use Domains\CapitalMarkets\Application\Query\GetRelatedInstruments;
use Domains\CapitalMarkets\Application\Query\GetVenueInstruments;
use Domains\CapitalMarkets\Application\Query\ListInstruments;
use Domains\CapitalMarkets\Application\Query\ListRelationships;
use Domains\CapitalMarkets\Application\Query\ListVenues;
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use App\Security\SessionCsrfValidator;
use InvalidArgumentException;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use PDOException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;
use ValueError;

final readonly class CapitalMarketsController
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private ActiveModuleResolver $modules,
        private CapitalMarketsAccessControlInterface $access,
        private CapitalMarketsFeatureGate $features,
        private CapitalMarketsFoundationBoundary $capitalMarkets,
        private SessionCsrfValidator $csrf,
    ){}

    public function instruments(Request $request):JsonResponse
    {
        $context=$this->context(CapitalMarketsCapability::InstrumentView,CapitalMarketsFeatureFlag::InstrumentRegistry);
        if($context instanceof JsonResponse)return $context;
        [$tenant]=$context;
        return $this->ok($this->capitalMarkets->listInstruments(new ListInstruments(
            $tenant->organizationId()->value(),
            array_filter([
                'q'=>trim((string)$request->query->get('q','')),
                'family'=>trim((string)$request->query->get('family','')),
                'status'=>trim((string)$request->query->get('status','')),
            ],static fn(string $value):bool=>$value!==''),
            min(500,max(1,(int)$request->query->get('limit',100))),
        )));
    }

    public function instrument(string $id):JsonResponse
    {
        $context=$this->context(CapitalMarketsCapability::InstrumentView,CapitalMarketsFeatureFlag::InstrumentRegistry);
        if($context instanceof JsonResponse)return $context;
        [$tenant]=$context;
        $data=$this->capitalMarkets->getInstrument(new GetInstrument($tenant->organizationId()->value(),$id));
        return $data===null?$this->error('Instrument not found.',404):$this->ok($data);
    }

    public function createInstrument(Request $request):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::InstrumentManage,CapitalMarketsFeatureFlag::InstrumentRegistry,
            fn(TenantContext $tenant,int $actor,array $payload,string $correlation):array=>
                $this->capitalMarkets->createInstrument(new CreateInstrument(
                    $tenant->organizationId()->value(),$actor,$correlation,$payload
                )),201);
    }

    public function updateInstrument(Request $request,string $id):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::InstrumentManage,CapitalMarketsFeatureFlag::InstrumentRegistry,
            fn(TenantContext $tenant,int $actor,array $payload,string $correlation):array=>
                $this->capitalMarkets->updateInstrument(new UpdateInstrument(
                    $tenant->organizationId()->value(),$actor,$correlation,$id,$payload
                )));
    }

    public function addInstrumentIdentifier(Request $request,string $id):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::InstrumentManage,CapitalMarketsFeatureFlag::InstrumentRegistry,
            fn(TenantContext $tenant,int $actor,array $payload,string $correlation):array=>
                $this->capitalMarkets->addInstrumentIdentifier(new AddInstrumentIdentifier(
                    $tenant->organizationId()->value(),$actor,$correlation,$id,$payload
                )),201);
    }

    public function instrumentRelationships(string $id):JsonResponse
    {
        $context=$this->context(CapitalMarketsCapability::RelationshipView,CapitalMarketsFeatureFlag::Relationships);
        if($context instanceof JsonResponse)return $context;
        [$tenant]=$context;
        return $this->respond(fn():array=>$this->capitalMarkets->getRelatedInstruments(
            new GetRelatedInstruments($tenant->organizationId()->value(),$id)
        ));
    }

    public function relationships():JsonResponse
    {
        $context=$this->context(CapitalMarketsCapability::RelationshipView,CapitalMarketsFeatureFlag::Relationships);
        if($context instanceof JsonResponse)return $context;
        [$tenant]=$context;
        return $this->ok($this->capitalMarkets->listRelationships(
            new ListRelationships($tenant->organizationId()->value(),200)
        ));
    }

    public function createRelationship(Request $request):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::RelationshipManage,CapitalMarketsFeatureFlag::Relationships,
            fn(TenantContext $tenant,int $actor,array $payload,string $correlation):array=>
                $this->capitalMarkets->createRelationship(new CreateRelationship(
                    $tenant->organizationId()->value(),$actor,$correlation,$payload
                )),201);
    }

    public function venues():JsonResponse
    {
        $context=$this->context(CapitalMarketsCapability::VenueView,CapitalMarketsFeatureFlag::Venues);
        if($context instanceof JsonResponse)return $context;
        [$tenant]=$context;
        return $this->ok($this->capitalMarkets->listVenues(
            new ListVenues($tenant->organizationId()->value(),200)
        ));
    }

    public function createVenue(Request $request):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::VenueManage,CapitalMarketsFeatureFlag::Venues,
            fn(TenantContext $tenant,int $actor,array $payload,string $correlation):array=>
                $this->capitalMarkets->createVenue(new CreateVenue(
                    $tenant->organizationId()->value(),$actor,$correlation,$payload
                )),201);
    }

    public function venueInstruments(string $id):JsonResponse
    {
        $context=$this->context(CapitalMarketsCapability::VenueView,CapitalMarketsFeatureFlag::Venues);
        if($context instanceof JsonResponse)return $context;
        [$tenant]=$context;
        return $this->respond(fn():array=>$this->capitalMarkets->getVenueInstruments(
            new GetVenueInstruments($tenant->organizationId()->value(),$id)
        ));
    }

    public function registerVenueInstrument(Request $request,string $id):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::VenueManage,CapitalMarketsFeatureFlag::Venues,
            fn(TenantContext $tenant,int $actor,array $payload,string $correlation):array=>
                $this->capitalMarkets->registerVenueInstrument(new RegisterVenueInstrument(
                    $tenant->organizationId()->value(),$actor,$correlation,$id,$payload
                )),201);
    }

    /**
     * @param callable(TenantContext,int,array<string,mixed>,string):array<string,mixed> $operation
     */
    private function mutation(
        Request $request,
        CapitalMarketsCapability $capability,
        CapitalMarketsFeatureFlag $flag,
        callable $operation,
        int $success=200,
    ):JsonResponse{
        $context=$this->context($capability,$flag);
        if($context instanceof JsonResponse)return $context;
        [$tenant,$actor]=$context;
        if(!$this->csrf->isValid($request))return $this->error('Invalid CSRF token.',403);

        try{
            $payload=$this->payload($request);
            $correlation=$this->correlation($request);
            return $this->ok($operation($tenant,$actor,$payload,$correlation),$success);
        }catch(PDOException $error){
            return $this->error('Capital Markets persistence conflict.',409,['detail'=>$this->safeDatabaseMessage($error)]);
        }catch(InvalidArgumentException|DomainException|ValueError $error){
            return $this->error($error->getMessage(),422);
        }catch(Throwable $error){
            error_log('capital_markets.api.failure '.$error->getMessage());
            return $this->error('Capital Markets operation failed.',500);
        }
    }

    /** @return array{0:TenantContext,1:int}|JsonResponse */
    private function context(CapitalMarketsCapability $capability,CapitalMarketsFeatureFlag $flag):array|JsonResponse
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
            || !$this->features->enabled($flag,$organizationId,$actor)){
            return $this->error('Capital Markets feature is disabled.',403);
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
            $data=json_decode((string)$request->getContent(),true,flags:JSON_THROW_ON_ERROR);
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
        return $value!==''?substr($value,0,190):'CM-'.strtoupper(bin2hex(random_bytes(8)));
    }

    /** @param callable():mixed $reader */
    private function respond(callable $reader):JsonResponse
    {
        try{return $this->ok($reader());}
        catch(InvalidArgumentException|DomainException|ValueError $error){return $this->error($error->getMessage(),422);}
        catch(Throwable $error){error_log('capital_markets.api.read_failure '.$error->getMessage());return $this->error('Capital Markets read failed.',500);}
    }

    private function ok(mixed $data,int $status=200):JsonResponse{return new JsonResponse(['ok'=>true,'data'=>$data],$status);}

    /** @param array<string,mixed> $extra */
    private function error(string $message,int $status,array $extra=[]):JsonResponse
    {
        return new JsonResponse(array_replace(['ok'=>false,'error'=>$message],$extra),$status);
    }

    private function safeDatabaseMessage(PDOException $error):string
    {
        $state=(string)($error->errorInfo[0]??$error->getCode());
        return $state==='23000'?'Unique or foreign-key constraint rejected the request.':'Database rejected the request.';
    }
}

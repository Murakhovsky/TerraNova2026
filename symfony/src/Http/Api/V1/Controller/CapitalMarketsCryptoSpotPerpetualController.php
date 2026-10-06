<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use DomainException;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsTradingRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\RelativeValueResearchRepositoryInterface;
use Domains\CapitalMarkets\Application\Feature\CapitalMarketsFeatureFlag;
use Domains\CapitalMarkets\Application\Feature\CapitalMarketsFeatureGate;
use Domains\CapitalMarkets\Application\Service\CryptoSpotPerpetualVerticalSliceService;
use Domains\CapitalMarkets\Application\Service\RelativeValuePaperExecutionService;
use Domains\CapitalMarkets\Application\Service\RelativeValuePositionLifecycleService;
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

final readonly class CapitalMarketsCryptoSpotPerpetualController
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private ActiveModuleResolver $modules,
        private CapitalMarketsAccessControlInterface $access,
        private CapitalMarketsFeatureGate $features,
        private CryptoSpotPerpetualVerticalSliceService $verticalSlice,
        private RelativeValuePaperExecutionService $paper,
        private RelativeValuePositionLifecycleService $lifecycle,
        private CapitalMarketsTradingRepositoryInterface $trading,
        private RelativeValueResearchRepositoryInterface $research,
        private SessionCsrfValidator $csrf,
    ){}

    public function dashboard(Request $request):JsonResponse
    {
        $context=$this->context(CapitalMarketsCapability::OpportunityView);
        if($context instanceof JsonResponse)return $context;
        [$tenant]=$context;
        $org=$tenant->organizationId()->value();
        $limit=min(1000,max(1,(int)$request->query->get('limit',200)));
        return $this->respond(fn():array=>[
            'trading'=>$this->trading->dashboard($org),
            'opportunities'=>array_values(array_filter(
                $this->trading->listOpportunities($org,$limit),
                static fn(array $row):bool=>in_array((string)($row['hypothesis']??''),['H4','H5','H6'],true)
            )),
            'executions'=>array_values(array_filter(
                $this->trading->listExecutions($org,$limit),
                static fn(array $row):bool=>in_array((string)($row['hypothesis']??''),['H4','H5','H6'],true)
            )),
            'positions'=>array_values(array_filter(
                $this->trading->listPositions($org,$limit),
                static fn(array $row):bool=>str_contains((string)($row['strategy_id']??''),'Funding')
                    ||str_contains((string)($row['strategy_id']??''),'SpotPerp')
            )),
            'funding_observations'=>$this->research->listFundingObservations($org,null,null,$limit),
            'funding_settlements'=>$this->research->listFundingSettlements($org,null,$limit),
        ]);
    }

    public function scanSpotPerp(Request $request):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::OpportunityView,
            fn(TenantContext $tenant,array $p):array=>$this->verticalSlice->scanSpotPerp(
                $tenant->organizationId()->value(),
                $this->required($p,'market_pair_id'),
                $this->required($p,'spot_venue_id'),
                $this->required($p,'spot_instrument_id'),
                $this->required($p,'perp_venue_id'),
                $this->required($p,'perp_instrument_id'),
                $this->options($p),
            ));
    }

    public function scanCrossVenueFunding(Request $request):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::OpportunityView,
            fn(TenantContext $tenant,array $p):array=>$this->verticalSlice->scanCrossVenueFunding(
                $tenant->organizationId()->value(),
                $this->required($p,'market_pair_id'),
                $this->required($p,'venue_a_id'),
                $this->required($p,'instrument_a_id'),
                $this->required($p,'venue_b_id'),
                $this->required($p,'instrument_b_id'),
                $this->options($p),
            ));
    }

    public function executePaper(Request $request,string $id):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::PaperExecute,
            fn(TenantContext $tenant,array $p):array=>$this->paper->execute(
                $tenant->organizationId()->value(),$id,$this->options($p)
            ),201);
    }

    public function closeExecution(Request $request,string $id):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::PaperExecute,
            fn(TenantContext $tenant,array $p):array=>$this->lifecycle->close(
                $tenant->organizationId()->value(),$id,
                trim((string)($p['reason']??'MANUAL_EXIT'))?:'MANUAL_EXIT',
                $this->options($p),
            ));
    }

    public function funding(Request $request):JsonResponse
    {
        $context=$this->context(CapitalMarketsCapability::OpportunityView);
        if($context instanceof JsonResponse)return $context;
        [$tenant]=$context;
        $venue=trim((string)$request->query->get('venue_id',''));
        $instrument=trim((string)$request->query->get('instrument_id',''));
        $limit=min(5000,max(1,(int)$request->query->get('limit',500)));
        return $this->respond(fn():array=>$this->research->listFundingObservations(
            $tenant->organizationId()->value(),$venue===''?null:$venue,$instrument===''?null:$instrument,$limit
        ));
    }

    public function fundingSettlements(Request $request):JsonResponse
    {
        $context=$this->context(CapitalMarketsCapability::OpportunityView);
        if($context instanceof JsonResponse)return $context;
        [$tenant]=$context;
        $position=trim((string)$request->query->get('position_reference',''));
        $limit=min(5000,max(1,(int)$request->query->get('limit',500)));
        return $this->respond(fn():array=>$this->research->listFundingSettlements(
            $tenant->organizationId()->value(),$position===''?null:$position,$limit
        ));
    }

    public function basis(Request $request):JsonResponse
    {
        $context=$this->context(CapitalMarketsCapability::OpportunityView);
        if($context instanceof JsonResponse)return $context;
        [$tenant]=$context;
        $pair=trim((string)$request->query->get('market_pair_id',''));
        if($pair==='')return $this->error('market_pair_id is required.',422);
        $limit=min(5000,max(1,(int)$request->query->get('limit',500)));
        return $this->respond(fn():array=>$this->research->listBasisObservations(
            $tenant->organizationId()->value(),$pair,$limit
        ));
    }

    public function hedge(string $id):JsonResponse
    {
        $context=$this->context(CapitalMarketsCapability::OpportunityView);
        if($context instanceof JsonResponse)return $context;
        [$tenant]=$context;
        return $this->respond(fn():array=>[
            'hedge'=>$this->research->getHedgeGroup($tenant->organizationId()->value(),$id)
        ]);
    }

    /**
     * @param callable(TenantContext,array<string,mixed>):array<string,mixed> $operation
     */
    private function mutation(Request $request,CapitalMarketsCapability $capability,callable $operation,int $success=200):JsonResponse
    {
        $context=$this->context($capability);
        if($context instanceof JsonResponse)return $context;
        [$tenant]=$context;
        if(!$this->csrf->isValid($request))return $this->error('Invalid CSRF token.',403);
        try{return $this->ok($operation($tenant,$this->payload($request)),$success);}
        catch(PDOException $error){return $this->error('Capital Markets persistence conflict.',409);}
        catch(InvalidArgumentException|DomainException|ValueError $error){return $this->error($error->getMessage(),422);}
        catch(Throwable $error){
            error_log('capital_markets.crypto_spot_perpetual.api.failure '.$error->getMessage());
            return $this->error('Crypto Spot/Perpetual operation failed.',500);
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
        foreach([
            CapitalMarketsFeatureFlag::DomainEnabled,
            CapitalMarketsFeatureFlag::MarketData,
            CapitalMarketsFeatureFlag::CryptoSpotPerpetual,
        ] as $flag){
            if(!$this->features->enabled($flag,$organizationId,$actor))return $this->error('Crypto Spot/Perpetual vertical slice is disabled.',403);
        }
        if($capability===CapitalMarketsCapability::PaperExecute
            &&!$this->features->enabled(CapitalMarketsFeatureFlag::PaperTrading,$organizationId,$actor)){
            return $this->error('Paper trading is disabled.',403);
        }
        return [$tenant,$actorId];
    }

    private function allowed(string $organizationId,int $actorId,CapitalMarketsCapability $capability):bool
    {
        if($this->access->hasCapability($organizationId,$actorId,$capability->value))return true;
        $broad=$capability===CapitalMarketsCapability::PaperExecute?CapitalMarketsCapability::Manage:CapitalMarketsCapability::View;
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
        $data=$request->request->all();unset($data['csrf_token']);return $data;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function options(array $payload):array
    {
        $options=$payload['options']??$payload;
        if(!is_array($options)||array_is_list($options))throw new InvalidArgumentException('options must be an object.');
        return $options;
    }

    /** @param array<string,mixed> $payload */
    private function required(array $payload,string $key):string
    {
        $value=trim((string)($payload[$key]??''));
        if($value==='')throw new InvalidArgumentException($key.' is required.');
        return $value;
    }

    private function respond(callable $reader):JsonResponse
    {
        try{return $this->ok($reader());}
        catch(InvalidArgumentException|DomainException|ValueError $error){return $this->error($error->getMessage(),422);}
        catch(Throwable $error){
            error_log('capital_markets.crypto_spot_perpetual.api.read_failure '.$error->getMessage());
            return $this->error('Crypto Spot/Perpetual read failed.',500);
        }
    }

    private function ok(mixed $data,int $status=200):JsonResponse{return new JsonResponse(['ok'=>true,'data'=>$data],$status);}
    private function error(string $message,int $status):JsonResponse{return new JsonResponse(['ok'=>false,'error'=>$message],$status);}
}

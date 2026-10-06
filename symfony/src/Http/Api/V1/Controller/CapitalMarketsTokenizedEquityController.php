<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use DomainException;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Application\Feature\CapitalMarketsFeatureFlag;
use Domains\CapitalMarkets\Application\Feature\CapitalMarketsFeatureGate;
use Domains\CapitalMarkets\Application\Service\TokenizedEquityPaperExecutionService;
use Domains\CapitalMarkets\Application\Service\TokenizedEquityVerticalSliceService;
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

final readonly class CapitalMarketsTokenizedEquityController
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private ActiveModuleResolver $modules,
        private CapitalMarketsAccessControlInterface $access,
        private CapitalMarketsFeatureGate $features,
        private TokenizedEquityVerticalSliceService $verticalSlice,
        private TokenizedEquityPaperExecutionService $paper,
        private SessionCsrfValidator $csrf,
    ){}

    public function dashboard():JsonResponse
    {
        $context=$this->context(CapitalMarketsCapability::OpportunityView);
        if($context instanceof JsonResponse)return $context;
        [$tenant]=$context;
        return $this->respond(fn():array=>[
            'research'=>$this->verticalSlice->dashboard($tenant->organizationId()->value()),
            'paper_portfolio'=>$this->paper->portfolio($tenant->organizationId()->value()),
        ]);
    }

    public function opportunities(Request $request):JsonResponse
    {
        $context=$this->context(CapitalMarketsCapability::OpportunityView);
        if($context instanceof JsonResponse)return $context;
        [$tenant]=$context;
        return $this->respond(fn():array=>$this->verticalSlice->opportunities(
            $tenant->organizationId()->value(),min(500,max(1,(int)$request->query->get('limit',200)))
        ));
    }

    public function scanH1(Request $request):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::OpportunityView,
            function(TenantContext $tenant,array $p):array{
                return $this->verticalSlice->scanReference(
                    $tenant->organizationId()->value(),
                    $this->required($p,'market_pair_id'),
                    $this->required($p,'reference_source_id'),
                    $this->required($p,'underlying_instrument_id'),
                    $this->required($p,'token_venue_id'),
                    $this->required($p,'token_instrument_id'),
                    $this->options($p),
                );
            });
    }

    public function scanH2(Request $request):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::OpportunityView,
            function(TenantContext $tenant,array $p):array{
                return $this->verticalSlice->scanCrossVenue(
                    $tenant->organizationId()->value(),
                    $this->required($p,'market_pair_id'),
                    $this->required($p,'venue_a_id'),
                    $this->required($p,'instrument_a_id'),
                    $this->required($p,'venue_b_id'),
                    $this->required($p,'instrument_b_id'),
                    $this->options($p),
                );
            });
    }

    public function initializePaperPortfolio(Request $request):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::PaperExecute,
            fn(TenantContext $tenant,array $p):array=>$this->paper->initializePortfolio(
                $tenant->organizationId()->value(),
                strtoupper($this->required($p,'currency')),
                $this->required($p,'initial_capital'),
            ),201);
    }

    public function executePaper(Request $request,string $id):JsonResponse
    {
        return $this->mutation($request,CapitalMarketsCapability::PaperExecute,
            fn(TenantContext $tenant,array $p):array=>$this->paper->execute($tenant->organizationId()->value(),$id),201);
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
            error_log('capital_markets.tokenized_equity.api.failure '.$error->getMessage());
            return $this->error('Tokenized Equity operation failed.',500);
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
        foreach([CapitalMarketsFeatureFlag::DomainEnabled,CapitalMarketsFeatureFlag::MarketData,CapitalMarketsFeatureFlag::TokenizedEquity] as $flag){
            if(!$this->features->enabled($flag,$organizationId,$actor))return $this->error('Tokenized Equity vertical slice is disabled.',403);
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
            error_log('capital_markets.tokenized_equity.api.read_failure '.$error->getMessage());
            return $this->error('Tokenized Equity read failed.',500);
        }
    }

    private function ok(mixed $data,int $status=200):JsonResponse{return new JsonResponse(['ok'=>true,'data'=>$data],$status);}
    private function error(string $message,int $status):JsonResponse{return new JsonResponse(['ok'=>false,'error'=>$message],$status);}
}

<?php
declare(strict_types=1);

namespace App\Web\CapitalMarkets;

use App\Security\SessionCsrfValidator;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use DomainException;
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
use Domains\CapitalMarkets\Application\Query\ListInstruments;
use Domains\CapitalMarkets\Application\Query\ListRelationships;
use Domains\CapitalMarkets\Application\Query\ListVenues;
use Domains\CapitalMarkets\Application\Service\MarketDataAdministrationService;
use Domains\CapitalMarkets\Application\Service\TokenizedEquityPaperExecutionService;
use Domains\CapitalMarkets\Application\Service\TokenizedEquityResearchService;
use Domains\CapitalMarkets\Application\Service\TokenizedEquityVerticalSliceService;
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use InvalidArgumentException;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Operations\Service\OperationsSectionReader;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use PDOException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;
use ValueError;

final readonly class CapitalMarketsPageController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private ActiveModuleResolver $modules,
        private WorkspaceShellFactory $shells,
        private SessionCsrfValidator $csrf,
        private CapitalMarketsAccessControlInterface $access,
        private CapitalMarketsFeatureGate $features,
        private CapitalMarketsFoundationBoundary $capitalMarkets,
        private MarketDataAdministrationService $marketData,
        private TokenizedEquityVerticalSliceService $tokenizedEquity,
        private TokenizedEquityResearchService $tokenizedEquityResearch,
        private TokenizedEquityPaperExecutionService $paperExecution,
        private OperationsSectionReader $operations,
    ){}

    public function overview(Request $request):Response
    {
        return $this->page(
            $request,'Capital Markets','capital-markets-overview','overview',
            CapitalMarketsCapability::View,CapitalMarketsFeatureFlag::DomainEnabled,
            function(TenantContext $tenant):array{
                $org=$tenant->organizationId()->value();
                $instruments=$this->capitalMarkets->listInstruments(new ListInstruments($org,[],500));
                $relationships=$this->capitalMarkets->listRelationships(new ListRelationships($org,500));
                $venues=$this->capitalMarkets->listVenues(new ListVenues($org,500));
                return ['workspace'=>[
                    'instrument_count'=>count($instruments),
                    'relationship_count'=>count($relationships),
                    'venue_count'=>count($venues),
                    'recent_instruments'=>array_slice($instruments,0,8),
                ]];
            }
        );
    }

    public function instruments(Request $request):Response
    {
        return $this->page(
            $request,'Capital Markets Instruments','capital-markets-instruments','instruments',
            CapitalMarketsCapability::InstrumentView,CapitalMarketsFeatureFlag::InstrumentRegistry,
            function(TenantContext $tenant)use($request):array{
                $filters=array_filter([
                    'q'=>trim((string)$request->query->get('q','')),
                    'family'=>trim((string)$request->query->get('family','')),
                    'status'=>trim((string)$request->query->get('status','')),
                ],static fn(string $v):bool=>$v!=='');
                return ['workspace'=>[
                    'instruments'=>$this->capitalMarkets->listInstruments(new ListInstruments(
                        $tenant->organizationId()->value(),$filters,250
                    )),
                ]];
            }
        );
    }

    public function instrument(Request $request,string $id):Response
    {
        return $this->page(
            $request,'Capital Markets Instrument','capital-markets-instruments','instrument',
            CapitalMarketsCapability::InstrumentView,CapitalMarketsFeatureFlag::InstrumentRegistry,
            function(TenantContext $tenant)use($id):array{
                $org=$tenant->organizationId()->value();
                $instrument=$this->capitalMarkets->getInstrument(new GetInstrument($org,$id));
                if($instrument===null)return ['notFound'=>true];
                return ['workspace'=>[
                    'instrument'=>$instrument,
                    'audit'=>$this->auditFor($org,'capital_markets.instrument',$id),
                ]];
            }
        );
    }

    public function relationships(Request $request):Response
    {
        return $this->page(
            $request,'Capital Markets Relationships','capital-markets-relationships','relationships',
            CapitalMarketsCapability::RelationshipView,CapitalMarketsFeatureFlag::Relationships,
            function(TenantContext $tenant):array{
                $org=$tenant->organizationId()->value();
                return ['workspace'=>[
                    'relationships'=>$this->capitalMarkets->listRelationships(new ListRelationships($org,250)),
                    'instruments'=>$this->capitalMarkets->listInstruments(new ListInstruments($org,['status'=>'ACTIVE'],500)),
                ]];
            }
        );
    }

    public function venues(Request $request):Response
    {
        return $this->page(
            $request,'Capital Markets Venues','capital-markets-venues','venues',
            CapitalMarketsCapability::VenueView,CapitalMarketsFeatureFlag::Venues,
            function(TenantContext $tenant):array{
                $org=$tenant->organizationId()->value();
                return ['workspace'=>[
                    'venues'=>$this->capitalMarkets->listVenues(new ListVenues($org,250)),
                    'instruments'=>$this->capitalMarkets->listInstruments(new ListInstruments($org,['status'=>'ACTIVE'],500)),
                ]];
            }
        );
    }

    public function tokenizedEquities(Request $request):Response
    {
        return $this->page(
            $request,'Tokenized Equity','capital-markets-tokenized-equity','tokenized_equity',
            CapitalMarketsCapability::OpportunityView,CapitalMarketsFeatureFlag::TokenizedEquity,
            function(TenantContext $tenant):array{
                $org=$tenant->organizationId()->value();
                return ['workspace'=>[
                    'research'=>$this->tokenizedEquity->dashboard($org),
                    'research_report'=>$this->tokenizedEquityResearch->report($org),
                    'opportunities'=>$this->tokenizedEquity->opportunities($org,200),
                    'paper_portfolio'=>$this->paperExecution->portfolio($org),
                ]];
            }
        );
    }

    public function marketData(Request $request):Response
    {
        return $this->page(
            $request,'Capital Markets Market Data','capital-markets-market-data','market_data',
            CapitalMarketsCapability::MarketDataView,CapitalMarketsFeatureFlag::MarketData,
            function(TenantContext $tenant):array{
                $org=$tenant->organizationId()->value();
                return ['workspace'=>array_replace(
                    $this->marketData->dashboard($org),
                    [
                        'instruments'=>$this->capitalMarkets->listInstruments(new ListInstruments($org,['status'=>'ACTIVE'],500)),
                        'venues'=>$this->capitalMarkets->listVenues(new ListVenues($org,250)),
                    ]
                )];
            }
        );
    }

    public function createMarketDataSource(Request $request):Response
    {
        return $this->mutate(
            $request,CapitalMarketsCapability::MarketDataSourceManage,CapitalMarketsFeatureFlag::MarketData,
            function(TenantContext $tenant,int $actor,array $input,string $correlation):string{
                $this->marketData->createSource(
                    $tenant->organizationId()->value(),$actor,$correlation,$input
                );
                return '/capital-markets/market-data?saved=1';
            },'/capital-markets/market-data'
        );
    }

    public function enableMarketDataSource(Request $request,string $id):Response
    {
        return $this->mutate(
            $request,CapitalMarketsCapability::MarketDataSourceManage,CapitalMarketsFeatureFlag::MarketData,
            function(TenantContext $tenant,int $actor,array $input,string $correlation)use($id):string{
                $this->marketData->setSourceEnabled(
                    $tenant->organizationId()->value(),$actor,$correlation,$id,true
                );
                return '/capital-markets/market-data?saved=1';
            },'/capital-markets/market-data'
        );
    }

    public function disableMarketDataSource(Request $request,string $id):Response
    {
        return $this->mutate(
            $request,CapitalMarketsCapability::MarketDataSourceManage,CapitalMarketsFeatureFlag::MarketData,
            function(TenantContext $tenant,int $actor,array $input,string $correlation)use($id):string{
                $this->marketData->setSourceEnabled(
                    $tenant->organizationId()->value(),$actor,$correlation,$id,false
                );
                return '/capital-markets/market-data?saved=1';
            },'/capital-markets/market-data'
        );
    }

    public function createMarketDataSubscription(Request $request,string $id):Response
    {
        return $this->mutate(
            $request,CapitalMarketsCapability::MarketDataManage,CapitalMarketsFeatureFlag::MarketData,
            function(TenantContext $tenant,int $actor,array $input,string $correlation)use($id):string{
                $this->marketData->createSubscription(
                    $tenant->organizationId()->value(),$actor,$correlation,$id,$input
                );
                return '/capital-markets/market-data?saved=1';
            },'/capital-markets/market-data'
        );
    }

    public function pollMarketDataSource(Request $request,string $id):Response
    {
        return $this->mutate(
            $request,CapitalMarketsCapability::MarketDataManage,CapitalMarketsFeatureFlag::MarketData,
            function(TenantContext $tenant,int $actor,array $input,string $correlation)use($id):string{
                $result=$this->marketData->poll(
                    $tenant->organizationId()->value(),$actor,$correlation,$id,
                    isset($input['limit'])?(int)$input['limit']:100
                );
                if(($result['status']??null)==='FAILED'){
                    return '/capital-markets/market-data?error=poll_failed';
                }
                return '/capital-markets/market-data?saved=1';
            },'/capital-markets/market-data'
        );
    }

    public function createInstrument(Request $request):Response
    {
        return $this->mutate(
            $request,CapitalMarketsCapability::InstrumentManage,CapitalMarketsFeatureFlag::InstrumentRegistry,
            function(TenantContext $tenant,int $actor,array $input,string $correlation):string{
                $created=$this->capitalMarkets->createInstrument(new CreateInstrument(
                    $tenant->organizationId()->value(),$actor,$correlation,$input
                ));
                return '/capital-markets/instruments/'.rawurlencode((string)$created['id']).'?saved=1';
            },'/capital-markets/instruments'
        );
    }

    public function updateInstrument(Request $request,string $id):Response
    {
        return $this->mutate(
            $request,CapitalMarketsCapability::InstrumentManage,CapitalMarketsFeatureFlag::InstrumentRegistry,
            function(TenantContext $tenant,int $actor,array $input,string $correlation)use($id):string{
                $this->capitalMarkets->updateInstrument(new UpdateInstrument(
                    $tenant->organizationId()->value(),$actor,$correlation,$id,$input
                ));
                return '/capital-markets/instruments/'.rawurlencode($id).'?saved=1';
            },'/capital-markets/instruments/'.rawurlencode($id)
        );
    }

    public function createRelationship(Request $request):Response
    {
        return $this->mutate(
            $request,CapitalMarketsCapability::RelationshipManage,CapitalMarketsFeatureFlag::Relationships,
            function(TenantContext $tenant,int $actor,array $input,string $correlation):string{
                $this->capitalMarkets->createRelationship(new CreateRelationship(
                    $tenant->organizationId()->value(),$actor,$correlation,$input
                ));
                return '/capital-markets/relationships?saved=1';
            },'/capital-markets/relationships'
        );
    }

    public function createVenue(Request $request):Response
    {
        return $this->mutate(
            $request,CapitalMarketsCapability::VenueManage,CapitalMarketsFeatureFlag::Venues,
            function(TenantContext $tenant,int $actor,array $input,string $correlation):string{
                $this->capitalMarkets->createVenue(new CreateVenue(
                    $tenant->organizationId()->value(),$actor,$correlation,$input
                ));
                return '/capital-markets/venues?saved=1';
            },'/capital-markets/venues'
        );
    }

    public function registerVenueInstrument(Request $request,string $id):Response
    {
        return $this->mutate(
            $request,CapitalMarketsCapability::VenueManage,CapitalMarketsFeatureFlag::Venues,
            function(TenantContext $tenant,int $actor,array $input,string $correlation)use($id):string{
                $this->capitalMarkets->registerVenueInstrument(new RegisterVenueInstrument(
                    $tenant->organizationId()->value(),$actor,$correlation,$id,$input
                ));
                return '/capital-markets/venues?saved=1';
            },'/capital-markets/venues'
        );
    }

    /**
     * @param callable(TenantContext):array<string,mixed> $reader
     */
    private function page(
        Request $request,
        string $title,
        string $active,
        string $view,
        CapitalMarketsCapability $capability,
        CapitalMarketsFeatureFlag $flag,
        callable $reader,
    ):Response{
        $tenant=$this->authorized($capability,$flag);
        if($tenant instanceof Response)return $tenant;
        try{
            $extra=$reader($tenant);
            if(!empty($extra['notFound'])){
                return $this->render($request,$tenant,'Instrument not found',$active,'failure',[
                    'failureCode'=>404,
                    'failureTitle'=>'Capital Markets entity not found',
                    'failureMessage'=>'The requested entity does not exist in this organization.',
                ],404);
            }
            return $this->render($request,$tenant,$title,$active,$view,$extra);
        }catch(Throwable $error){
            error_log('capital_markets.workspace.read_failed ['.$view.'] '.$error->getMessage());
            return $this->render($request,$tenant,'Capital Markets unavailable',$active,'failure',[
                'failureCode'=>503,
                'failureTitle'=>'Capital Markets temporarily unavailable',
                'failureMessage'=>'The Foundation read model could not be loaded.',
            ],503);
        }
    }

    /**
     * @param callable(TenantContext,int,array<string,mixed>,string):string $operation
     */
    private function mutate(
        Request $request,
        CapitalMarketsCapability $capability,
        CapitalMarketsFeatureFlag $flag,
        callable $operation,
        string $fallback,
    ):Response{
        $tenant=$this->authorized($capability,$flag);
        if($tenant instanceof Response)return $tenant;
        if(!$this->csrf->isValid($request))return new Response('Invalid CSRF token.',403);

        $actor=$tenant->userId()->value();
        if(!ctype_digit($actor))return new Response('Canonical numeric actor required.',403);

        try{
            $input=$request->request->all();
            unset($input['csrf_token']);
            $target=$operation($tenant,(int)$actor,$input,$this->correlation($request));
            return new RedirectResponse($target,303);
        }catch(PDOException $error){
            error_log('capital_markets.workspace.persistence_conflict '.$error->getMessage());
            return new RedirectResponse($fallback.'?error=conflict',303);
        }catch(InvalidArgumentException|DomainException|ValueError $error){
            return new RedirectResponse($fallback.'?error='.rawurlencode($error->getMessage()),303);
        }catch(Throwable $error){
            error_log('capital_markets.workspace.mutation_failed '.$error->getMessage());
            return new RedirectResponse($fallback.'?error=operation_failed',303);
        }
    }

    private function authorized(CapitalMarketsCapability $capability,CapitalMarketsFeatureFlag $flag):TenantContext|Response
    {
        $tenant=$this->tenants->current();
        if($tenant===null)return new RedirectResponse('/auth/login');
        $org=$tenant->organizationId()->value();
        if(!$this->modules->isEnabled($org,'capital_markets'))return new Response('Capital Markets module is disabled.',403);
        $actor=$tenant->userId()->value();
        if(!ctype_digit($actor))return new Response('Forbidden',403);
        if(!$this->allowed($org,(int)$actor,$capability))return new Response('Forbidden',403);
        if(!$this->features->enabled(CapitalMarketsFeatureFlag::DomainEnabled,$org,$actor)
            ||!$this->features->enabled($flag,$org,$actor)){
            return new Response('Capital Markets feature is disabled.',403);
        }
        return $tenant;
    }

    private function allowed(string $organizationId,int $actorId,CapitalMarketsCapability $capability):bool
    {
        if($this->access->hasCapability($organizationId,$actorId,$capability->value))return true;
        $broad=str_ends_with($capability->value,'.manage')?CapitalMarketsCapability::Manage:CapitalMarketsCapability::View;
        return $this->access->hasCapability($organizationId,$actorId,$broad->value);
    }

    /** @param array<string,mixed> $extra */
    private function render(
        Request $request,TenantContext $tenant,string $title,string $active,string $view,array $extra=[],int $status=200
    ):Response{
        $org=$tenant->organizationId()->value();
        $actor=(int)$tenant->userId()->value();
        $variables=array_replace([
            'pageTitle'=>$title,
            'cmView'=>$view,
            'csrfToken'=>$this->csrf->token($request),
            'query'=>$request->query->all(),
            'canManageInstruments'=>$this->allowed($org,$actor,CapitalMarketsCapability::InstrumentManage),
            'canManageRelationships'=>$this->allowed($org,$actor,CapitalMarketsCapability::RelationshipManage),
            'canManageVenues'=>$this->allowed($org,$actor,CapitalMarketsCapability::VenueManage),
            'canManageMarketData'=>$this->allowed($org,$actor,CapitalMarketsCapability::MarketDataManage),
            'canManageMarketDataSources'=>$this->allowed($org,$actor,CapitalMarketsCapability::MarketDataSourceManage),
            'canPaperExecute'=>$this->allowed($org,$actor,CapitalMarketsCapability::PaperExecute),
        ],$extra);

        $context=new WebExtensionContext($org,$tenant->role()->value(),'workspace','capital-markets',$active);
        $shell=$this->shells->create($tenant,$context,$title,[
            new ShellBreadcrumb('Workspace','/admin'),
            new ShellBreadcrumb('Capital Markets','/capital-markets'),
            new ShellBreadcrumb($title),
        ]);
        return new Response(
            $this->twig->render('experience/capital_markets/workspace.html.twig',array_replace($variables,['shell'=>$shell])),
            $status,
            ['Content-Type'=>'text/html; charset=UTF-8','Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow'],
        );
    }

    /** @return list<array<string,mixed>> */
    private function auditFor(string $organizationId,string $subjectType,string $subjectId):array
    {
        return array_values(array_filter(
            $this->operations->section($organizationId,'audit',200),
            static fn(array $row):bool=>($row['subject_type']??null)===$subjectType && (string)($row['subject_id']??'')===$subjectId
        ));
    }

    private function correlation(Request $request):string
    {
        $value=trim((string)$request->headers->get('X-Correlation-Id',''));
        return $value!==''?substr($value,0,190):'CM-WEB-'.strtoupper(bin2hex(random_bytes(6)));
    }
}

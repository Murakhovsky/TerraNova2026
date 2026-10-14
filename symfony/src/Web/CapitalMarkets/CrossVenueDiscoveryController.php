<?php
declare(strict_types=1);

namespace App\Web\CapitalMarkets;

use App\Security\SessionCsrfValidator;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use Domains\CapitalMarkets\Application\Service\CrossVenueCatalogDiscovery;
use Domains\CapitalMarkets\Application\Service\CrossVenueActiveUniverse;
use Domains\CapitalMarkets\Application\Service\BStocksSeedFileReader;
use Domains\CapitalMarkets\Application\Service\BStocksCanonicalDraftService;
use Domains\CapitalMarkets\Application\Service\BStocksReferenceBboPlanner;
use Domains\CapitalMarkets\Application\Service\CrossVenueSubscriptionPlanner;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\BStocksSeedImportRepository;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\MysqlBoundedBboSweepGate;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Domains\CapitalMarkets\Application\Service\CrossVenueQuoteSampler;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\CrossVenueQuoteSnapshotRepository;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\CrossVenueDiscoverySnapshotRepository;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\CrossVenuePoolCandidateRepository;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\CrossVenueListingReviewRepository;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

/**
 * Manual, read-only catalog scan. Never creates canonical relationships,
 * never approves venues, never creates an executable opportunity.
 */
final readonly class CrossVenueDiscoveryController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private ActiveModuleResolver $modules,
        private CapitalMarketsAccessControlInterface $access,
        private SessionCsrfValidator $csrf,
        private WorkspaceShellFactory $shells,
        private CrossVenueActiveUniverse $activeUniverse,
        private BStocksSeedFileReader $seedReader,
        private BStocksCanonicalDraftService $canonicalDrafts,
        private BStocksReferenceBboPlanner $referenceBbo,
        private CrossVenueSubscriptionPlanner $tokenBbo,
        private BStocksSeedImportRepository $seedImports,
        private MysqlBoundedBboSweepGate $bboPollGate,
        private CrossVenueCatalogDiscovery $scanner,
        private CrossVenueDiscoverySnapshotRepository $snapshots,
        private CrossVenuePoolCandidateRepository $poolCandidates,
        private CrossVenueListingReviewRepository $listingReviews,
        private CrossVenueQuoteSampler $sampler,
        private CrossVenueQuoteSnapshotRepository $quotes,
        #[Autowire('%kernel.project_dir%/../resources/capital-markets/universes/binance-bstocks-2026-09-27.csv')]
        private string $universeFile,
    ) {}

    public function index(Request $request): Response
    {
        $tenant=$this->authorized(CapitalMarketsCapability::MarketDataView);
        if($tenant instanceof Response)return $tenant;
        $org=$tenant->organizationId()->value();
        try {
            $universe=$this->activeUniverse->load($org,$this->universeFile);
            $watchlist=$universe['rows'];
            $snapshot=$this->snapshots->latest($org);
            $poolCandidateCounts=$this->poolCandidates->counts($org);
            $listingReviewRows=$this->listingReviews->pending($org);
            $canonicalCoverage=$this->seedImports->canonicalCoverage($org);
            $bboPollHealth=$this->bboPollGate->recent($org);
            $quoteSnapshot=$this->quotes->latest($org);
        } catch(Throwable $e) {
            error_log('capital_markets.cross_venue_discovery.read_failed '.get_class($e).' '.$e->getMessage());
            return new Response('Cross-venue discovery data unavailable.',503);
        }
        $actor=(int)$tenant->userId()->value();
        $canScan=$this->allowed($org,$actor,CapitalMarketsCapability::MarketDataManage);
        $canSeedDrafts=$this->allowed($org,$actor,CapitalMarketsCapability::InstrumentManage);
        $context=new WebExtensionContext($org,$tenant->role()->value(),'workspace','capital-markets','capital-markets-discovery');
        $shell=$this->shells->create($tenant,$context,'Cross-Venue Discovery',[
            new ShellBreadcrumb('Workspace','/admin'),
            new ShellBreadcrumb('Capital Markets','/capital-markets'),
            new ShellBreadcrumb('Cross-Venue Discovery'),
        ]);
        return new Response(
            $this->twig->render('experience/capital_markets/discovery.html.twig',[
                'shell'=>$shell,'pageTitle'=>'Cross-Venue Discovery',
                'watchlist'=>$watchlist,'snapshot'=>$snapshot,'quoteSnapshot'=>$quoteSnapshot,
                'universeOrigin'=>$universe['origin'],'universeAsOf'=>$universe['source_as_of'],
                'poolCandidateCounts'=>$poolCandidateCounts,
                'listingReviewRows'=>$listingReviewRows,
                'canReviewListings'=>$this->allowed($org,$actor,CapitalMarketsCapability::InstrumentManage)
                    && $this->allowed($org,$actor,CapitalMarketsCapability::MarketDataManage),
                'canonicalCoverage'=>$canonicalCoverage,'bboPollHealth'=>$bboPollHealth,
                'canScan'=>$canScan,'canSeedDrafts'=>$canSeedDrafts,
                'canPlanBbo'=>$canScan,
                'csrfToken'=>$this->csrf->token($request),
                'scanned'=>$request->query->getBoolean('scanned'),
                'cooldown'=>$request->query->getBoolean('cooldown'),
                'scanError'=>$request->query->getBoolean('error'),
                'quoted'=>$request->query->getBoolean('quoted'),
                'quoteError'=>$request->query->getBoolean('quoteError'),
                'quoteCooldown'=>$request->query->getBoolean('quoteCooldown'),
            ]),
            200,['Content-Type'=>'text/html; charset=UTF-8','Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow'],
        );
    }

    public function scan(Request $request): Response
    {
        $tenant=$this->authorized(CapitalMarketsCapability::MarketDataManage);
        if($tenant instanceof Response)return $tenant;
        if(!$this->csrf->isValid($request))return new Response('Invalid CSRF token.',403);
        $org=$tenant->organizationId()->value();
        $actor=(int)$tenant->userId()->value();
        $limit=(string)$request->request->get('limit','10');
        if(!in_array($limit,['10','all'],true))return new Response('Invalid scan scope.',400);
        if(!$this->snapshots->reserveScan($org,$actor))return new RedirectResponse('/capital-markets/discovery?cooldown=1',303);

        try {
            $universe=$this->activeUniverse->load($org,$this->universeFile);
            $candidates=$limit==='10'?array_slice($universe['rows'],0,10):$universe['rows'];
            $snapshot=$this->scanner->scan($org,$candidates,$universe['source_as_of'],$universe['origin']);
            $this->snapshots->record($org,$actor,$snapshot);
            $this->poolCandidates->reconcile($org,$snapshot);
        } catch(Throwable $e) {
            error_log('capital_markets.cross_venue_discovery.scan_failed '.get_class($e).' '.$e->getMessage());
            return new RedirectResponse('/capital-markets/discovery?error=1',303);
        }
        return new RedirectResponse('/capital-markets/discovery?scanned=1',303);
    }

    public function planPoolBbo(Request $request):Response
    {
        $tenant=$this->authorized(CapitalMarketsCapability::MarketDataManage);
        if ($tenant instanceof Response) return $tenant;
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.',403);
        $org=$tenant->organizationId()->value();
        $actor=(int)$tenant->userId()->value();
        $kind=trim((string)$request->request->get('kind',''));
        $action=trim((string)$request->request->get('action',''));
        $source=trim((string)$request->request->get('source_id',''));
        if (!in_array($kind,['TOKEN_BBO','REFERENCE_BBO'],true)
            || !in_array($action,['preview','apply'],true)
            || $source==='' || strlen($source)>190) {
            return new Response('Invalid subscription planning request.',400);
        }
        if ($kind==='REFERENCE_BBO'
            && !$this->allowed($org,$actor,CapitalMarketsCapability::InstrumentManage)) {
            return new Response('Instrument Manage permission required for reference subscriptions.',403);
        }
        try {
            $planner=$kind==='TOKEN_BBO'?$this->tokenBbo:$this->referenceBbo;
            $result=$action==='preview' ? ['plan'=>$planner->plan($org,$source,250),'created'=>0]
                :$planner->apply($org,$actor,$source,250);
            $plan=$result['plan'];
            $summary=['kind'=>$kind,'mode'=>$action,'source'=>$source,'created'=>$result['created'],
                'total'=>$plan['total'],'eligible_new'=>$plan['create'],
                'already_active'=>$plan['existing'],'blocked'=>$plan['blocked']];
            $request->getSession()->getFlashBag()->add('pool_bbo',json_encode($summary,JSON_THROW_ON_ERROR));
            return new RedirectResponse('/capital-markets/discovery?bboPlan=1',303);
        } catch (Throwable $e) {
            error_log('capital_markets.bbo_plan.failed '.get_class($e).' '.$e->getMessage());
            return new RedirectResponse('/capital-markets/discovery?bboPlanError=1',303);
        }
    }

    public function reviewListing(Request $request): Response
    {
        $tenant=$this->authorized(CapitalMarketsCapability::MarketDataManage);
        if ($tenant instanceof Response) return $tenant;
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.',403);
        $org=$tenant->organizationId()->value();
        $actor=(int)$tenant->userId()->value();
        if (!$this->allowed($org,$actor,CapitalMarketsCapability::InstrumentManage)) {
            return new Response('Instrument review permission required.',403);
        }
        try {
            $decision=trim((string)$request->request->get('decision',''));
            $result=$decision==='REVOKE_LISTING' ?
                $this->listingReviews->revoke(
                    $org,$actor,
                    trim((string)$request->request->get('seed_token','')),
                    trim((string)$request->request->get('venue','')),
                    trim((string)$request->request->get('revoke_reason',''))
                ) : $this->listingReviews->decide(
                $org,$actor,
                trim((string)$request->request->get('seed_token','')),
                trim((string)$request->request->get('venue','')),
                trim((string)$request->request->get('source_as_of','')),
                trim((string)$request->request->get('decision','')),
                trim((string)$request->request->get('evidence_url','')),
                trim((string)$request->request->get('evidence_sha256','')),
            );
            $request->getSession()->getFlashBag()->add('listing_review',
                $result['seed_token'].' / '.$result['venue'].' → '.$result['status']
                .(isset($result['disabled_subscriptions'])
                    ?' · disabled BBO: '.$result['disabled_subscriptions'] :''));
            return new RedirectResponse('/capital-markets/discovery?listingReviewed=1',303);
        } catch (Throwable $e) {
            error_log('capital_markets.listing_review.failed '.get_class($e).' '.$e->getMessage());
            return new RedirectResponse('/capital-markets/discovery?listingReviewError=1',303);
        }
    }

    public function seedCanonicalDrafts(Request $request): Response
    {
        $tenant=$this->authorized(CapitalMarketsCapability::InstrumentManage);
        if ($tenant instanceof Response) return $tenant;
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.',403);
        $mode=(string)$request->request->get('draft_mode','');
        if (!in_array($mode,['preview','apply','underlying-preview','underlying-apply'],true)) return new Response('Invalid draft action.',400);
        $org=$tenant->organizationId()->value();
        $actor=(int)$tenant->userId()->value();
        try {
            $result=match($mode) {
                'preview'=>['status'=>'DRY_RUN','plan'=>$this->canonicalDrafts->plan($org)],
                'apply'=>$this->canonicalDrafts->apply($org,$actor,100),
                'underlying-preview'=>['status'=>'DRY_RUN','plan'=>$this->canonicalDrafts->planUnderlying($org)],
                'underlying-apply'=>$this->canonicalDrafts->applyUnderlyings($org,$actor,100),
            };
            $request->getSession()->getFlashBag()->add('canonical_drafts',json_encode($result,JSON_THROW_ON_ERROR));
            return new RedirectResponse('/capital-markets/discovery?draftsProcessed=1',303);
        } catch (Throwable $e) {
            error_log('capital_markets.canonical_drafts.failed '.get_class($e).' '.$e->getMessage());
            return new RedirectResponse('/capital-markets/discovery?draftError=1',303);
        }
    }

    public function importSeed(Request $request): Response
    {
        $tenant=$this->authorized(CapitalMarketsCapability::MarketDataManage);
        if ($tenant instanceof Response) return $tenant;
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.',403);
        $uploaded=$request->files->get('seed_file');
        $mode=(string)$request->request->get('seed_mode','');
        if (!$uploaded instanceof UploadedFile || !$uploaded->isValid()
            || $uploaded->getSize() === null || $uploaded->getSize() > 5242880
            || !in_array($mode,['preview','apply'],true)) {
            return new Response('Invalid seed import request.',400);
        }
        $extension=strtolower($uploaded->getClientOriginalExtension());
        if (!in_array($extension,['xlsm','xlsx','csv'],true)) {
            return new Response('Unsupported seed file extension.',400);
        }
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($uploaded->getPathname());
        if (!in_array($mime,[
            'application/vnd.ms-excel.sheet.macroEnabled.12',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip','application/octet-stream','text/plain',
            'text/csv','application/csv','application/vnd.ms-excel',
        ],true)) {
            return new Response('Unsupported seed content type.',400);
        }
        $org=$tenant->organizationId()->value();
        $actor=(int)$tenant->userId()->value();
        $name='cos_cm_seed_'.bin2hex(random_bytes(12)).'.'.$extension;
        $path=sys_get_temp_dir().DIRECTORY_SEPARATOR.$name;
        try {
            $uploaded->move(sys_get_temp_dir(),$name);
            $seed=$this->seedReader->read($path);
            $stats=$mode==='preview'
                ?$this->seedImports->preview($org,$seed)
                :$this->seedImports->apply($org,$actor,$seed);
            $request->getSession()->getFlashBag()->add('seed_import',json_encode([
                'mode'=>$mode,'file_sha256'=>$seed['sha256'],
                'source_as_of'=>$seed['source_as_of'],'stats'=>$stats,
            ],JSON_THROW_ON_ERROR));
            return new RedirectResponse('/capital-markets/discovery?seedImported=1',303);
        } catch (Throwable $e) {
            error_log('capital_markets.seed_import.failed '.get_class($e).' '.$e->getMessage());
            return new RedirectResponse('/capital-markets/discovery?seedError=1',303);
        } finally {
            if (is_file($path)) @unlink($path);
        }
    }

    public function observeQuotes(Request $request):Response
    {
        $tenant=$this->authorized(CapitalMarketsCapability::MarketDataManage);
        if($tenant instanceof Response)return $tenant;
        if(!$this->csrf->isValid($request))return new Response('Invalid CSRF token.',403);
        $org=$tenant->organizationId()->value();
        $actor=(int)$tenant->userId()->value();
        try {
            $discovery=$this->snapshots->latest($org);
            if($discovery===null)return new RedirectResponse('/capital-markets/discovery?quoteError=1',303);
            if(!$this->quotes->reserve($org,$actor))
                return new RedirectResponse('/capital-markets/discovery?quoteCooldown=1',303);
            $result=$this->sampler->observe($org,$discovery);
            $this->quotes->record($org,$actor,$result);
        }catch(Throwable $error){
            error_log('capital_markets.cross_venue_quotes.failed '.get_class($error).' '.$error->getMessage());
            return new RedirectResponse('/capital-markets/discovery?quoteError=1',303);
        }
        return new RedirectResponse('/capital-markets/discovery?quoted=1',303);
    }

    private function authorized(CapitalMarketsCapability $capability):TenantContext|Response
    {
        $tenant=$this->tenants->current();
        if($tenant===null)return new RedirectResponse('/auth/login');
        $org=$tenant->organizationId()->value();
        if(!$this->modules->isEnabled($org,'capital_markets'))return new Response('Capital Markets disabled.',404);
        $actor=(int)$tenant->userId()->value();
        return $this->allowed($org,$actor,$capability)?$tenant:new Response('Forbidden.',403);
    }

    private function allowed(string $org,int $actor,CapitalMarketsCapability $capability):bool
    {
        return $actor>0 && ($this->access->hasCapability($org,$actor,$capability->value)
            || $this->access->hasCapability($org,$actor,CapitalMarketsCapability::Manage->value));
    }
}

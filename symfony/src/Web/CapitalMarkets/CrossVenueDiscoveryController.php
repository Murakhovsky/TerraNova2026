<?php
declare(strict_types=1);

namespace App\Web\CapitalMarkets;

use App\Security\SessionCsrfValidator;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use Domains\CapitalMarkets\Application\Service\CrossVenueCatalogDiscovery;
use Domains\CapitalMarkets\Application\Service\CrossVenueUniverse;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\CrossVenueDiscoverySnapshotRepository;
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
        private CrossVenueUniverse $universe,
        private CrossVenueCatalogDiscovery $scanner,
        private CrossVenueDiscoverySnapshotRepository $snapshots,
        #[Autowire('%kernel.project_dir%/../resources/capital-markets/universes/binance-bstocks-2026-09-27.csv')]
        private string $universeFile,
    ) {}

    public function index(Request $request): Response
    {
        $tenant=$this->authorized(CapitalMarketsCapability::MarketDataView);
        if($tenant instanceof Response)return $tenant;
        $org=$tenant->organizationId()->value();
        try {
            $watchlist=$this->universe->load($this->universeFile);
            $snapshot=$this->snapshots->latest($org);
        } catch(Throwable $e) {
            error_log('capital_markets.cross_venue_discovery.read_failed '.get_class($e).' '.$e->getMessage());
            return new Response('Cross-venue discovery data unavailable.',503);
        }
        $actor=(int)$tenant->userId()->value();
        $canScan=$this->allowed($org,$actor,CapitalMarketsCapability::MarketDataManage);
        $context=new WebExtensionContext($org,$tenant->role()->value(),'workspace','capital-markets','capital-markets-discovery');
        $shell=$this->shells->create($tenant,$context,'Cross-Venue Discovery',[
            new ShellBreadcrumb('Workspace','/admin'),
            new ShellBreadcrumb('Capital Markets','/capital-markets'),
            new ShellBreadcrumb('Cross-Venue Discovery'),
        ]);
        return new Response(
            $this->twig->render('experience/capital_markets/discovery.html.twig',[
                'shell'=>$shell,'pageTitle'=>'Cross-Venue Discovery',
                'watchlist'=>$watchlist,'snapshot'=>$snapshot,
                'canScan'=>$canScan,'csrfToken'=>$this->csrf->token($request),
                'scanned'=>$request->query->getBoolean('scanned'),
                'cooldown'=>$request->query->getBoolean('cooldown'),
                'scanError'=>$request->query->getBoolean('error'),
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
        if(!$this->snapshots->mayScan($org))return new RedirectResponse('/capital-markets/discovery?cooldown=1',303);

        $limit=(string)$request->request->get('limit','10');
        if(!in_array($limit,['10','80'],true))return new Response('Invalid scan scope.',400);

        try {
            $candidates=$this->universe->load($this->universeFile);
            $snapshot=$this->scanner->scan($org,array_slice($candidates,0,(int)$limit));
            $this->snapshots->record($org,$actor,$snapshot);
        } catch(Throwable $e) {
            error_log('capital_markets.cross_venue_discovery.scan_failed '.get_class($e).' '.$e->getMessage());
            return new RedirectResponse('/capital-markets/discovery?error=1',303);
        }
        return new RedirectResponse('/capital-markets/discovery?scanned=1',303);
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

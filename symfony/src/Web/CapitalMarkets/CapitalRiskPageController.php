<?php
declare(strict_types=1);

namespace App\Web\CapitalMarkets;

use App\Security\SessionCsrfValidator;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Application\Service\CapitalRiskService;
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class CapitalRiskPageController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private ActiveModuleResolver $modules,
        private CapitalMarketsAccessControlInterface $access,
        private CapitalRiskService $service,
        private SessionCsrfValidator $csrf,
    ){}

    public function portfolio(Request $request):Response
    {
        return $this->page($request,'Portfolio Command Center','portfolio',CapitalMarketsCapability::PortfolioView);
    }

    public function risk(Request $request):Response
    {
        return $this->page($request,'Portfolio Risk','risk',CapitalMarketsCapability::RiskView);
    }

    public function allocation(Request $request):Response
    {
        return $this->page($request,'Capital Allocation','allocation',CapitalMarketsCapability::AllocationView);
    }

    public function opportunity(Request $request,string $id):Response
    {
        $tenant=$this->authorized(CapitalMarketsCapability::PortfolioView);
        if($tenant instanceof Response)return $tenant;

        return new Response($this->twig->render('experience/capital_markets/capital_risk.html.twig',[
            'pageTitle'=>'Opportunity · '.$id,
            'cmView'=>'opportunity',
            'workspace'=>$this->service->opportunityDetail($tenant->organizationId()->value(),$id),
            'csrfToken'=>$this->csrf->token($request),
        ]));
    }

    private function page(Request $request,string $title,string $view,CapitalMarketsCapability $capability):Response
    {
        $tenant=$this->authorized($capability);
        if($tenant instanceof Response)return $tenant;

        return new Response($this->twig->render('experience/capital_markets/capital_risk.html.twig',[
            'pageTitle'=>$title,
            'cmView'=>$view,
            'workspace'=>$this->service->workspace($tenant->organizationId()->value()),
            'csrfToken'=>$this->csrf->token($request),
        ]));
    }

    private function authorized(CapitalMarketsCapability $capability):TenantContext|Response
    {
        $tenant=$this->tenants->requireTenant();
        $organizationId=$tenant->organizationId()->value();

        if(!$this->modules->isEnabled($organizationId,'capital_markets')){
            return new Response('Capital Markets module is disabled.',404);
        }

        $actorId=$tenant->userId()->value();
        if(
            !$this->access->hasCapability($organizationId,$actorId,$capability->value)
            && !$this->access->hasCapability($organizationId,$actorId,CapitalMarketsCapability::Manage->value)
        ){
            return new Response('Forbidden.',403);
        }

        return $tenant;
    }
}

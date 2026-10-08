<?php
declare(strict_types=1);

namespace App\Web\CapitalMarkets;

use App\Security\SessionCsrfValidator;
use Domains\CapitalMarkets\Application\Service\CapitalRiskService;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class CapitalRiskPageController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private CapitalRiskService $service,
        private SessionCsrfValidator $csrf,
    ){}

    public function portfolio(Request $request):Response{return $this->page($request,'Portfolio Command Center','portfolio');}
    public function risk(Request $request):Response{return $this->page($request,'Portfolio Risk','risk');}
    public function allocation(Request $request):Response{return $this->page($request,'Capital Allocation','allocation');}

    public function opportunity(Request $request,string $id):Response
    {
        $tenant=$this->tenants->requireTenant();
        return new Response($this->twig->render('experience/capital_markets/capital_risk.html.twig',[
            'pageTitle'=>'Opportunity · '.$id,
            'cmView'=>'opportunity',
            'workspace'=>$this->service->opportunityDetail($tenant->organizationId()->value(),$id),
            'csrfToken'=>$this->csrf->token($request),
        ]));
    }

    private function page(Request $request,string $title,string $view):Response
    {
        $tenant=$this->tenants->requireTenant();
        return new Response($this->twig->render('experience/capital_markets/capital_risk.html.twig',[
            'pageTitle'=>$title,
            'cmView'=>$view,
            'workspace'=>$this->service->workspace($tenant->organizationId()->value()),
            'csrfToken'=>$this->csrf->token($request),
        ]));
    }
}

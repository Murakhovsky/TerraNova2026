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
 public function __construct(private Environment $twig,private TenantContextProviderInterface $tenants,private CapitalRiskService $service,private SessionCsrfValidator $csrf){}
 public function portfolio(Request $r):Response{return $this->page($r,'Portfolio Command Center','portfolio');}
 public function risk(Request $r):Response{return $this->page($r,'Portfolio Risk','risk');}
 public function allocation(Request $r):Response{return $this->page($r,'Capital Allocation','allocation');}
 private function page(Request $r,string $title,string $view):Response{$t=$this->tenants->requireTenant();return new Response($this->twig->render('experience/capital_markets/capital_risk.html.twig',['pageTitle'=>$title,'cmView'=>$view,'workspace'=>$this->service->workspace($t->organizationId()->value()),'csrfToken'=>$this->csrf->token($r)]));}
}

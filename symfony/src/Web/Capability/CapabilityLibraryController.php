<?php
declare(strict_types=1);
namespace App\Web\Capability;
use App\Application\Capability\CanonicalCapabilityGateway;
use DomainException;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
final readonly class CapabilityLibraryController {
    public function __construct(private CanonicalCapabilityGateway $capabilities,private TenantContextProviderInterface $tenants,private Environment $twig) {}
    public function index():Response {
        $actor=$this->tenants->current();
        if ($actor===null) return new RedirectResponse('/auth/login');
        try { $inventory=$this->capabilities->inventory($actor); }
        catch (DomainException) { return new Response('Forbidden',403); }
        return new Response($this->twig->render('experience/capability/library.html.twig',['capabilities'=>$inventory]),200,
            ['Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow']);
    }
}

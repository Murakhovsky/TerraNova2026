<?php
declare(strict_types=1);
namespace App\Web\Experience\Unified;

use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\WebExtensionCatalog;
use App\Web\Experience\Search\GlobalSearchService;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use App\Web\Experience\Shell\ShellConnectionState;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Twig\Environment;

/** Read-only and opt-in: compose existing tenant-enabled Web providers, never a second bus. */
final readonly class UnifiedWorkspaceController {
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private WebExtensionCatalog $extensions,
        private GlobalSearchService $search,
        private WorkspaceShellFactory $shells,
        private Environment $twig,
    ) {}
    public function index(Request $request): Response {
        $tenant = $this->tenants->current();
        if ($tenant === null) return new RedirectResponse('/auth/login');
        if (!$tenant->isManager() || !$tenant->allows(TenantPermissions::ACCESS)
            || !$tenant->allows(TenantPermissions::MANAGE)) return new Response('Forbidden',403);
        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'workspace', activeSection: 'workspaces', activeItem: 'unified',
        );
        $query = trim((string)$request->query->get('q',''));
        if (mb_strlen($query)>120) $query=mb_substr($query,0,120);
        $workspaces=$this->extensions->forContext($context)->workspaces();
        $matches=$query==='' ? [] : $this->search->search($context,$query,24)->items;
        $shell=$this->shells->create($tenant,$context,'Мої робочі простори',
            [new ShellBreadcrumb('Робочі простори')],
            aiAvailable: false,
            connectionState: ShellConnectionState::Stale,
        );
        return new Response($this->twig->render('experience/unified/home.html.twig',[
            'shell'=>$shell,'workspaces'=>$workspaces,'query'=>$query,'matches'=>$matches,
        ]),200,[
            'Content-Type'=>'text/html; charset=UTF-8',
            'Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow',
        ]);
    }
}

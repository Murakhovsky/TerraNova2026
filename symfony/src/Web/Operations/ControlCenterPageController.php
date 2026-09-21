<?php
declare(strict_types=1);

namespace App\Web\Operations;

use App\Web\Navigation\NavigationBuilder;
use App\Web\Phtml\PhtmlRenderer;
use Kernel\Operations\Contract\OperationsReadModelInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class ControlCenterPageController
{
    public function __construct(
        private PhtmlRenderer $renderer,
        private TenantContextProviderInterface $tenants,
        private NavigationBuilder $navigation,
        private OperationsReadModelInterface $operations,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;
        $status = Response::HTTP_OK;
        $overview = [];
        $pageStatus = null;

        try {
            $overview = $this->operations->overview(
                $tenant->organizationId()->value(),
                max(1, min(100, (int) $request->query->get('limit', 30))),
            );
        } catch (Throwable $error) {
            error_log('cos.control_center.read_failed ' . $error->getMessage());
            $status = Response::HTTP_SERVICE_UNAVAILABLE;
            $pageStatus = 'COS Control Center тимчасово недоступний.';
        }

        $role = $tenant->role()->value();
        return new Response($this->renderer->render($request, 'cos/index', [
            'title'=>'COS Control Center',
            'metaTitle'=>'COS Control Center | Terra Nova COS',
            'metaRobots'=>'noindex,nofollow',
            'interfaceSurface'=>'workspace',
            'workspaceSection'=>'cos',
            'workspaceActive'=>'cos',
            'workspaceActiveSection'=>$this->navigation->activeSection('cos'),
            'pageAssetEntries'=>['cos-control-center'],
            'csrfToken'=>$request->hasSession() ? (string)$request->getSession()->get('cos_csrf_token','') : '',
            'actionStatus'=>(string)$request->query->get('status_message',''),
            'pageStatus'=>$pageStatus,
            'overview'=>$overview,
            'currentUser'=>['id'=>(int)$tenant->userId()->value(),'role'=>$role],
            'role'=>$role,
            'isTeam'=>true,
            'isAdmin'=>$tenant->isAdmin(),
            'workspaceNavigation'=>$this->navigation->workspace($tenant),
        ]), $status, ['Content-Type'=>'text/html; charset=UTF-8']);
    }

    private function manager(): TenantContext|Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) return new RedirectResponse('/auth/login');
        if (!$tenant->isManager()) return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        return $tenant;
    }
}

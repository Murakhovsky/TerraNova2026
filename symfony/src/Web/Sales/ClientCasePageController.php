<?php
declare(strict_types=1);

namespace App\Web\Sales;

use App\Web\Navigation\NavigationBuilder;
use App\Web\Phtml\PhtmlRenderer;
use Domains\Property\Application\Contract\PropertyCatalogInterface;
use Domains\Sales\Application\Contract\ClientCaseReadModelFactoryInterface;
use Domains\Sales\Application\Contract\SalesWorkspaceOperationalReadModelInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class ClientCasePageController
{
    public function __construct(
        private PhtmlRenderer $renderer,
        private TenantContextProviderInterface $tenants,
        private NavigationBuilder $navigation,
        private ClientCaseReadModelFactoryInterface $cases,
        private SalesWorkspaceOperationalReadModelInterface $sales,
        private PropertyCatalogInterface $catalog,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;
        $read = $this->cases->forOrganization($tenant->organizationId()->value());
        $filters = $read->filters($request->query->all());

        try {
            $pipelines = $this->sales->pipelines($tenant->organizationId()->value());
            return $this->render($request, $tenant, 'Клієнтські кейси', 'cases', 'client_case/index', [
                'filters' => $filters,
                'cases' => $read->cases($filters),
                'stats' => $read->stats(),
                'unlinkedInboundRequests' => $read->unlinkedInboundRequests(),
                'openCaseOptions' => $read->openCaseOptions(),
                'managerOptions' => $read->managerOptions(),
                'propertyTypes' => $this->catalog->propertyTypes(),
                'locations' => $this->catalog->locations(),
                'pipelineStages' => $pipelines[0]['stages'] ?? [],
                'pageStatus' => null,
                'actionStatus' => (string) $request->query->get('status_message', ''),
            ]);
        } catch (Throwable $error) {
            error_log('client-case.index.read_failed ' . $error->getMessage());
            return $this->render($request, $tenant, 'Клієнтські кейси', 'cases', 'client_case/index', [
                'filters' => $filters, 'cases' => [], 'stats' => [], 'unlinkedInboundRequests' => [],
                'openCaseOptions' => [], 'managerOptions' => [], 'propertyTypes' => [], 'locations' => [],
                'pipelineStages' => [], 'pageStatus' => 'CRM кейсів тимчасово недоступна.', 'actionStatus' => '',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    public function inbox(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;
        $read = $this->cases->forOrganization($tenant->organizationId()->value());
        $filters = $read->inboundFilters($request->query->all());

        try {
            return $this->render($request, $tenant, 'Вхідні заявки', 'inbox', 'client_case/inbox', [
                'filters' => $filters,
                'inboundRequests' => $read->inboundInbox($filters),
                'inboundStats' => $read->inboundInboxStats(),
                'openCaseOptions' => $read->openCaseOptions(),
                'managerOptions' => $read->managerOptions(),
                'pipelineStages' => [],
                'pageStatus' => null,
                'actionStatus' => (string) $request->query->get('status_message', ''),
            ]);
        } catch (Throwable $error) {
            error_log('client-case.inbox.read_failed ' . $error->getMessage());
            return $this->render($request, $tenant, 'Вхідні заявки', 'inbox', 'client_case/inbox', [
                'filters' => $filters, 'inboundRequests' => [], 'inboundStats' => [], 'openCaseOptions' => [],
                'managerOptions' => [], 'pipelineStages' => [], 'pageStatus' => 'CRM заявки тимчасово недоступні.', 'actionStatus' => '',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function manager(): TenantContext|Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) return new RedirectResponse('/auth/login');
        if (!$tenant->isManager()) return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        return $tenant;
    }

    /** @param array<string,mixed> $extra */
    private function render(Request $request, TenantContext $tenant, string $title, string $active, string $view, array $extra, int $status = 200): Response
    {
        $role = $tenant->role()->value();
        $variables = array_replace([
            'title' => $title,
            'metaTitle' => $title . ' | Terra Nova COS',
            'metaRobots' => 'noindex,nofollow',
            'interfaceSurface' => 'workspace',
            'workspaceSection' => 'clients',
            'workspaceActive' => $active,
            'workspaceActiveSection' => $this->navigation->activeSection($active),
            'pageAssetEntries' => ['clients-workspace'],
            'currentUser' => ['id' => (int) $tenant->userId()->value(), 'role' => $role],
            'role' => $role,
            'isTeam' => true,
            'isAdmin' => $tenant->isAdmin(),
            'workspaceNavigation' => $this->navigation->workspace($tenant),
        ], $extra);

        return new Response($this->renderer->render($request, $view, $variables), $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}

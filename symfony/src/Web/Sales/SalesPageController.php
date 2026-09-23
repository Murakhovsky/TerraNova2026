<?php
declare(strict_types=1);

namespace App\Web\Sales;

use App\Web\Navigation\NavigationBuilder;
use App\Web\Phtml\PhtmlRenderer;
use DateTimeImmutable;
use Domains\Sales\Application\Service\SalesDirectorCockpitService;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class SalesPageController
{
    public function __construct(
        private PhtmlRenderer $renderer,
        private TenantContextProviderInterface $tenants,
        private NavigationBuilder $navigation,
        private SalesDirectorCockpitService $director,
    ) {
    }

    public function director(Request $request): Response
    {
        return $this->managerPage($request, 'Sales Director', 'director', 'sales/director',
            function(TenantContext $tenant) use ($request): array {
                $historyDays = max(1, min(366, (int) $request->query->get('history_days', 30)));
                $forecastDays = max(1, min(366, (int) $request->query->get('forecast_days', 30)));
                $pipelineId = trim((string) $request->query->get('pipeline_id', '')) ?: null;
                return $this->director->overview(
                    $tenant->organizationId()->value(),
                    new DateTimeImmutable(),
                    $historyDays,
                    $forecastDays,
                    $pipelineId,
                );
            });
    }

    /** @param callable(TenantContext):array<string,mixed> $reader */
    private function managerPage(Request $request, string $title, string $active, string $view, callable $reader): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        try {
            return $this->render($request, $tenant, $title, $active, $view, ['workspace' => $reader($tenant)]);
        } catch (Throwable $error) {
            error_log(sprintf('sales.page.read_failed [%s] %s', $view, $error->getMessage()));
            return $this->failure($request, $tenant, 503, 'Сервіс тимчасово недоступний', 'Sales workspace тимчасово недоступний.');
        }
    }

    private function manager(): TenantContext|Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return new RedirectResponse('/auth/login');
        }
        if (!$tenant->isManager()) {
            return new Response('Forbidden', 403);
        }

        return $tenant;
    }

    /** @param array<string,mixed> $extra */
    private function render(
        Request $request,
        TenantContext $tenant,
        string $title,
        string $active,
        string $view,
        array $extra = [],
        int $status = 200,
    ): Response {
        $role = $tenant->role()->value();
        $variables = array_replace([
            'title' => $title,
            'metaTitle' => $title . ' | Terra Nova COS',
            'workspaceSection' => 'sales',
            'workspaceActive' => $active,
            'workspaceActiveSection' => $this->navigation->activeSection($active),
            'pageAssetEntries' => ['sales-workspace'],
            'csrfToken' => $this->csrf($request),
            'pageStatus' => null,
            'currentUser' => [
                'id' => (int) $tenant->userId()->value(),
                'role' => $role,
            ],
            'role' => $role,
            'isTeam' => true,
            'isAdmin' => $tenant->isAdmin(),
            'workspaceNavigation' => $this->navigation->workspace($tenant),
        ], $extra);

        return new Response(
            $this->renderer->render($request, $view, $variables),
            $status,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    private function failure(Request $request, TenantContext $tenant, int $status, string $title, string $message): Response
    {
        return $this->render($request, $tenant, $title, 'sales', 'error/failure', [
            'metaTitle' => $title . ' | Terra Nova',
            'metaRobots' => 'noindex,nofollow',
            'interfaceSurface' => 'workspace',
            'pageAssetEntries' => [],
            'workspaceSection' => null,
            'workspaceActive' => null,
            'failureCode' => $status,
            'failureTitle' => $title,
            'failureMessage' => $message,
            'failureRequestId' => 'TN-' . strtoupper(bin2hex(random_bytes(5))),
            'failureActionUrl' => '/sales',
            'failureActionLabel' => 'До Sales',
        ], $status);
    }

    private function csrf(Request $request): string
    {        return $request->hasSession()
            ? (string) $request->getSession()->get('cos_csrf_token', '')
            : '';
    }



}

<?php

declare(strict_types=1);

namespace App\Web\Operations;

use App\Application\Operations\Command\OperationsMutationCommand;
use App\Application\Operations\Query\GetControlCenterOverviewQuery;
use App\Security\SessionCsrfValidator;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Observability\CorrelationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class ControlCenterPageController
{
    public function __construct(
        private Environment $twig,
        private QueryBusInterface $queries,
        private CommandBusInterface $commands,
        private TenantContextProviderInterface $tenants,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private ControlCenterPresenter $presenter,
        private SessionCsrfValidator $csrf,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'system',
            activeSection: 'cos',
            activeItem: 'cos-overview',
        );
        $shell = $this->shells->create($tenant, $context, 'COS Control Center', [
            new ShellBreadcrumb('Workspace', '/admin'),
            new ShellBreadcrumb('COS'),
            new ShellBreadcrumb('Control Center'),
        ]);

        $actionStatus = trim((string) $request->query->get('status_message', ''));
        $limit = max(1, min(100, (int) $request->query->get('limit', 30)));

        try {
            $overview = $this->queries->ask(
                new GetControlCenterOverviewQuery($tenant->organizationId(), $limit),
            );
            $control = $this->presenter->present(
                is_array($overview) ? $overview : [],
                $actionStatus,
            );

            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::SystemControlSurface,
                    $this->patterns(),
                    $control->state(),
                ),
                'control' => $control,
                'csrfToken' => $this->csrf->token($request),
            ]);
        } catch (Throwable $error) {
            error_log('cos.control_center.read_failed ' . $error->getMessage());
            $control = $this->presenter->present(
                [],
                $actionStatus,
                'COS Control Center тимчасово недоступний. Деталі записано в лог.',
            );

            return $this->render([
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::SystemControlSurface,
                    $this->patterns(),
                    'error',
                ),
                'control' => $control,
                'csrfToken' => $this->csrf->token($request),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    public function execute(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, OperationsMutationCommand::EXECUTE_ACTION);
    }

    public function approve(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, OperationsMutationCommand::APPROVE);
    }

    public function reject(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, OperationsMutationCommand::REJECT);
    }

    private function mutate(Request $request, string $id, string $operation): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        if (!$this->csrf->isValid($request)) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        try {
            $result = $this->commands->dispatch(new OperationsMutationCommand(
                $tenant->organizationId()->value(),
                (int) $tenant->userId()->value(),
                $operation,
                $id,
                $request->request->all(),
                $this->correlation($request),
            ));
            $message = is_array($result) ? (string) ($result['status'] ?? 'OK') : 'OK';
        } catch (Throwable $error) {
            $message = 'Помилка: ' . $error->getMessage();
        }

        $return = ltrim(trim((string) $request->request->get('return_url', '')), '/');
        if ($return !== 'cos/control-center' && !preg_match('#^client-case/show/[1-9][0-9]*$#', $return)) {
            $return = 'cos/control-center';
        }

        return new RedirectResponse('/' . $return . '?status_message=' . rawurlencode($message));
    }

    private function correlation(Request $request): string
    {
        $value = $request->attributes->get('_cos_correlation_id');

        return $value instanceof CorrelationId
            ? $value->value()
            : CorrelationId::generate()->value();
    }

    private function manager(): TenantContext|Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return new RedirectResponse('/auth/login');
        }
        if (!$tenant->isManager()) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        return $tenant;
    }

    /** @return list<string> */
    private function patterns(): array
    {
        return [
            'PageHeader',
            'Toolbar',
            'KpiStrip',
            'EntityList',
            'ActionBar',
            'EmptyState',
            'ErrorState',
        ];
    }

    /** @param array<string,mixed> $variables */
    private function render(array $variables, int $status = Response::HTTP_OK): Response
    {
        return new Response(
            $this->twig->render('experience/operations/control_center.html.twig', $variables),
            $status,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }
}

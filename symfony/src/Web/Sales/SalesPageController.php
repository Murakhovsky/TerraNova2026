<?php
declare(strict_types=1);

namespace App\Web\Sales;

use App\Web\Navigation\NavigationBuilder;
use App\Web\Phtml\PhtmlRenderer;
use DateTimeImmutable;
use Domains\Sales\Application\Contract\SalesTeamAdministrationInterface;
use Domains\Sales\Application\Contract\SalesWorkspaceOperationalReadModelInterface;
use Domains\Sales\Application\Service\SalesDirectorCockpitService;
use Kernel\Operations\Contract\OperationsReadModelInterface;
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
        private SalesWorkspaceOperationalReadModelInterface $workspace,
        private SalesTeamAdministrationInterface $teams,
        private OperationsReadModelInterface $operations,
        private SalesDirectorCockpitService $director,
    ) {
    }

    public function dashboard(Request $request): Response
    {
        return $this->managerPage($request, 'Sales Overview', 'sales', 'sales/dashboard',
            fn(TenantContext $tenant): array => $this->workspace->dashboard(
                $tenant->organizationId()->value(),
                (int) $tenant->userId()->value(),
            ));
    }

    public function pipeline(Request $request): Response
    {
        return $this->managerPage($request, 'Sales Pipeline', 'pipeline', 'sales/pipeline',
            fn(TenantContext $tenant): array => [
                'pipelines' => $this->workspace->pipelines($tenant->organizationId()->value()),
                'deals' => $this->workspace->deals($tenant->organizationId()->value(), $request->query->all()),
                'owners' => $this->owners($tenant),
            ]);
    }

    public function today(Request $request): Response
    {
        return $this->managerPage($request, 'Sales Today', 'today', 'sales/today',
            function(TenantContext $tenant) use ($request): array {
                $scope = strtolower(trim((string) $request->query->get('scope', 'mine')));
                $ownerId = $scope === 'team' ? 0 : (int) $tenant->userId()->value();
                return [
                    'scope' => $scope === 'team' ? 'team' : 'mine',
                    'sections' => $this->workspace->today($tenant->organizationId()->value(), $ownerId),
                ];
            });
    }

    public function leads(Request $request): Response
    {
        return $this->managerPage($request, 'Sales Leads', 'leads', 'sales/leads',
            fn(TenantContext $tenant): array => [
                'leads' => $this->workspace->leads($tenant->organizationId()->value(), $request->query->all()),
                'owners' => $this->owners($tenant),
            ]);
    }

    public function deals(Request $request): Response
    {
        return $this->managerPage($request, 'Sales Deals', 'deals', 'sales/deals',
            fn(TenantContext $tenant): array => [
                'pipelines' => $this->workspace->pipelines($tenant->organizationId()->value()),
                'deals' => $this->workspace->deals($tenant->organizationId()->value(), $request->query->all()),
                'owners' => $this->owners($tenant),
            ]);
    }

    public function deal(Request $request, string $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        $dealId = (int) $id;
        $organizationId = $tenant->organizationId()->value();
        $deal = $this->workspace->deal($organizationId, $dealId);
        if ($deal === null) {
            return $this->failure($request, $tenant, 404, 'Сторінку не знайдено', 'Угоду не знайдено.');
        }

        $intelligence = $this->normalizeDealIntelligence([]);
        try {
            $intelligence = $this->normalizeDealIntelligence($this->operations->dealIntelligence($organizationId, $dealId));
        } catch (Throwable) {
        }

        return $this->render($request, $tenant, 'Deal Workspace', 'deals', 'sales/deal', [
            'workspace' => [
                'deal' => $deal,
                'timeline' => $this->workspace->timeline($organizationId, $dealId, 100),
                'communications' => $this->workspace->communications($organizationId, $dealId, 50),
                'approvals' => $this->workspace->approvals($organizationId, $dealId, null, 50),
                'pipelines' => $this->workspace->pipelines($organizationId),
                'owners' => $this->owners($tenant),
                'intelligence' => $intelligence,
            ],
        ]);
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

    /** @return list<array<string,mixed>> */
    private function owners(TenantContext $tenant): array
    {
        $users = $this->teams->users($tenant->organizationId()->value());
        return array_values(array_filter($users, static fn(array $user): bool =>
            strtolower((string) ($user['status'] ?? '')) === 'active'
            && in_array(strtolower((string) ($user['organization_role'] ?? $user['role'] ?? '')), ['manager', 'admin'], true)
        ));
    }

    /** @return array<string,mixed> */
    private function normalizeDealIntelligence(array $raw): array
    {
        $analysis = is_array($raw['analysis'] ?? null) ? $raw['analysis'] : [];
        $decision = is_array($raw['decision'] ?? null) ? $raw['decision'] : [];
        $signals = is_array($raw['signals'] ?? null) ? $raw['signals'] : [];
        $actions = is_array($raw['actions'] ?? null) ? $raw['actions'] : [];
        $firstAction = [];
        foreach ($actions as $candidate) {
            if (is_array($candidate)) {
                $firstAction = $candidate;
                break;
            }
        }
        $pick = static function(mixed ...$values): mixed {
            foreach ($values as $value) {
                if ($value !== null && $value !== '' && $value !== []) {
                    return $value;
                }
            }
            return null;
        };

        return array_replace($raw, [
            'contract_version' => 'sales.intelligence.v1',
            'deal_health' => $pick($raw['deal_health'] ?? null, $analysis['deal_health'] ?? null, $decision['deal_health'] ?? null, $raw['health'] ?? null, $decision['risk_level'] ?? null),
            'customer_intent' => $pick($raw['customer_intent'] ?? null, $analysis['customer_intent'] ?? null, $signals['customer_intent'] ?? null, $decision['customer_intent'] ?? null),
            'objections' => $pick($raw['objections'] ?? null, $analysis['objections'] ?? null, $signals['objections'] ?? null),
            'missing_information' => $pick($raw['missing_information'] ?? null, $analysis['missing_information'] ?? null, $signals['missing_information'] ?? null, $raw['missing_info'] ?? null),
            'next_best_action' => $pick($raw['next_best_action'] ?? null, $analysis['next_best_action'] ?? null, $decision['next_best_action'] ?? null, $firstAction['reason'] ?? null, $firstAction['description'] ?? null, $firstAction['type'] ?? null),
            'recommended_timing' => $pick($raw['recommended_timing'] ?? null, $analysis['recommended_timing'] ?? null, $decision['recommended_timing'] ?? null, $firstAction['recommended_at'] ?? null, $firstAction['execute_at'] ?? null),
            'confidence' => $pick($raw['confidence'] ?? null, $analysis['confidence'] ?? null, $decision['confidence'] ?? null),
        ]);
    }
}

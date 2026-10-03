<?php
declare(strict_types=1);

namespace App\Web\Engineering;

use App\Engineering\Application\Service\EngineeringStatusService;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class EngineeringFeatureController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private EngineeringStatusService $engineering,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
    ) {}

    public function show(Request $request, string $id): Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) return new RedirectResponse('/auth/login');
        if (!$tenant->isManager() || !$tenant->allows(TenantPermissions::MANAGE)) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        try {
            $featureId = EngineeringId::assert($id);
            $data = $this->engineering->status($featureId);
            if (($data['feature']['organization_id'] ?? null) !== $tenant->organizationId()->value()) {
                return new Response('Not found', Response::HTTP_NOT_FOUND);
            }
        } catch (Throwable) {
            return new Response('Not found', Response::HTTP_NOT_FOUND);
        }

        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'workspace',
            activeSection: 'administration',
            activeItem: 'engineering',
        );
        $shell = $this->shells->create($tenant, $context, 'Engineering', [
            new ShellBreadcrumb('Workspace', '/admin'),
            new ShellBreadcrumb('Engineering'),
            new ShellBreadcrumb((string) ($data['feature']['title'] ?? $featureId)),
        ]);

        $summary = $this->summary($data);

        return new Response(
            $this->twig->render('experience/engineering/feature.html.twig', [
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::SystemControlSurface,
                    ['PageHeader', 'KpiStrip', 'EntityList', 'EmptyState', 'ErrorState'],
                    'ready',
                ),
                'engineering' => $data,
                'summary' => $summary,
            ]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }

    private function summary(array $data): array
    {
        $tokens = 0;
        $cost = 0.0;
        foreach ($data['agent_runs'] ?? [] as $run) {
            if (!is_array($run)) continue;
            $tokens += (int) ($run['tokens_input'] ?? 0) + (int) ($run['tokens_output'] ?? 0);
            $cost += (float) ($run['cost'] ?? 0);
        }

        $openFindings = array_values(array_filter(
            is_array($data['findings'] ?? null) ? $data['findings'] : [],
            static fn (mixed $finding): bool => is_array($finding) && ($finding['status'] ?? null) === 'OPEN',
        ));

        $finalReport = $data['artifacts']['FINAL_REPORT']['content'] ?? null;
        $development = $data['artifacts']['DEVELOPMENT_RESULT']['content'] ?? null;
        $pullRequest = is_array($finalReport['pull_request'] ?? null)
            ? $finalReport['pull_request']
            : [
                'number' => is_array($development) ? ($development['pull_request'] ?? null) : null,
                'url' => is_array($development) ? ($development['pull_request_url'] ?? null) : null,
            ];

        return [
            'state' => $data['workflow']['state'] ?? ($data['feature']['status'] ?? 'NEW'),
            'tasks_total' => count($data['tasks'] ?? []),
            'tasks_completed' => count(array_filter(
                $data['tasks'] ?? [],
                static fn (mixed $task): bool => is_array($task) && ($task['status'] ?? null) === 'COMPLETED',
            )),
            'agent_runs' => count($data['agent_runs'] ?? []),
            'tokens' => $tokens,
            'cost' => round($cost, 6),
            'open_findings' => $openFindings,
            'pull_request' => $pullRequest,
            'final_report' => is_array($finalReport) ? $finalReport : null,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Web\Experience\Dev;

use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use App\Web\Experience\DesignSystem\DesignSystemAuditService;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Golden\GoldenExperienceSet;
use App\Web\Experience\Golden\GoldenStructureAuditService;
use App\Web\Experience\Golden\GoldenHumanReviewPacketBuilder;
use App\Web\Experience\Migration\WorkspaceMigrationPlanner;
use App\Web\Experience\External\ExternalExperiencePlanner;
use App\Web\Experience\External\ExternalReferenceAuditService;
use App\Web\Experience\Registry\CompiledPageContractRegistry;
use App\Web\Experience\Registry\ExperienceRouteInventory;
use App\Web\Experience\Registry\PageExperienceStatus;
use App\Web\Experience\Registry\RouteExemptionRegistry;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class ExperienceDashboardController
{
    public function __construct(
        private Environment $twig,
        private PagePresentationFactory $pages,
        private ExperienceRouteInventory $inventory,
        private CompiledPageContractRegistry $registry,
        private RouteExemptionRegistry $exemptions,
        private DesignSystemAuditService $designSystem,
        private GoldenExperienceSet $golden,
        private GoldenStructureAuditService $goldenStructure,
        private GoldenHumanReviewPacketBuilder $goldenReview,
        private WorkspaceMigrationPlanner $workspaceMigration,
        private ExternalExperiencePlanner $externalExperience,
        private ExternalReferenceAuditService $externalReferences,
        private TenantContextProviderInterface $tenants,
        private WorkspaceShellFactory $shells,
    ) {
    }

    public function __invoke(): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'system',
            activeSection: 'admin',
            activeItem: 'experience',
        );
        $shell = $this->shells->create($tenant, $context, 'COS Experience V1', [
            new ShellBreadcrumb('Workspace', '/admin'),
            new ShellBreadcrumb('Experience'),
        ]);

        $routes = $this->inventory->productionHtmlRoutes();
        $contracts = $this->registry->all();
        $exemptions = $this->exemptions->all();

        $statusCounts = [];
        $domainCounts = [];
        $priorityCounts = [];
        $v1Ready = 0;
        $implemented = 0;
        $visualQa = 0;
        $functionalQa = 0;
        $responsiveQa = 0;
        $accessibilityQa = 0;
        $humanAccepted = 0;

        foreach ($contracts as $contract) {
            $statusCounts[$contract->status->value] = ($statusCounts[$contract->status->value] ?? 0) + 1;
            $priorityCounts[$contract->priority] = ($priorityCounts[$contract->priority] ?? 0) + 1;

            $domainCounts[$contract->domain] ??= [
                'total' => 0,
                'p0p1' => 0,
                'implemented' => 0,
                'v1Ready' => 0,
            ];
            ++$domainCounts[$contract->domain]['total'];

            if (in_array($contract->priority, ['P0', 'P1'], true)) {
                ++$domainCounts[$contract->domain]['p0p1'];
            }

            if (!in_array($contract->status, [
                PageExperienceStatus::Discovered,
                PageExperienceStatus::Inventoried,
                PageExperienceStatus::Contracted,
                PageExperienceStatus::UxApproved,
                PageExperienceStatus::Implementing,
            ], true)) {
                ++$implemented;
                ++$domainCounts[$contract->domain]['implemented'];
            }

            $visualQa += ($contract->qa['visual'] ?? false) === true ? 1 : 0;
            $functionalQa += ($contract->qa['functional'] ?? false) === true ? 1 : 0;
            $responsiveQa += ($contract->qa['responsive'] ?? false) === true ? 1 : 0;
            $accessibilityQa += ($contract->qa['accessibility'] ?? false) === true ? 1 : 0;
            $humanAccepted += ($contract->qa['human_acceptance'] ?? false) === true ? 1 : 0;

            if ($contract->status === PageExperienceStatus::V1Ready && $contract->quality->isV1Ready()) {
                ++$v1Ready;
                ++$domainCounts[$contract->domain]['v1Ready'];
            }
        }

        ksort($statusCounts);
        ksort($domainCounts);
        ksort($priorityCounts);

        $coverage = count($routes) > 0
            ? (int) round(((count($contracts) + count($exemptions)) / count($routes)) * 100)
            : 100;

        return new Response(
            $this->twig->render('experience/dev/experience_dashboard.html.twig', [
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::SystemControlSurface,
                    ['PageHeader', 'Toolbar', 'KpiStrip', 'DataGrid', 'EntityList'],
                    'normal',
                ),
                'experience' => [
                    'routes' => count($routes),
                    'contracts' => count($contracts),
                    'exemptions' => count($exemptions),
                    'coverage' => $coverage,
                    'implemented' => $implemented,
                    'visualQa' => $visualQa,
                    'functionalQa' => $functionalQa,
                    'responsiveQa' => $responsiveQa,
                    'accessibilityQa' => $accessibilityQa,
                    'humanAccepted' => $humanAccepted,
                    'v1Ready' => $v1Ready,
                    'statusCounts' => $statusCounts,
                    'priorityCounts' => $priorityCounts,
                    'domains' => $domainCounts,
                    'designSystem' => $this->designSystem->audit()->toArray(),
                    'golden' => $this->golden->report()->toArray(),
                    'goldenStructure' => $this->goldenStructure->audit()->toArray(),
                    'goldenReview' => $this->goldenReview->build(),
                    'workspaceMigration' => $this->workspaceMigration->plan()->toArray(),
                    'externalExperience' => $this->externalExperience->report()->toArray(),
                    'externalReferences' => $this->externalReferences->audit()->toArray(),
                    'pages' => array_values(array_map(
                        static fn ($contract): array => [
                            'id' => $contract->id->value,
                            'route' => $contract->routeName,
                            'path' => $contract->path,
                            'domain' => $contract->domain,
                            'priority' => $contract->priority,
                            'status' => $contract->status->value,
                            'archetype' => $contract->archetype,
                            'quality' => $contract->quality->toArray(),
                        ],
                        $contracts,
                    )),
                ],
            ]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
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
}

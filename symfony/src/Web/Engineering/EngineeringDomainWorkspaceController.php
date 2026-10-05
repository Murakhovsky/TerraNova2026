<?php
declare(strict_types=1);

namespace App\Web\Engineering;

use App\Engineering\Application\DomainDevelopment\EngineeringDomainRuntimeService;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainStatus;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Security\SessionCsrfValidator;
use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class EngineeringDomainWorkspaceController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private EngineeringDomainRuntimeService $runtime,
        private SessionCsrfValidator $csrf,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
    ) {}

    public function index(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        $domains = $this->runtime->list($tenant->organizationId()->value(), 100);
        $stats = [
            'total' => count($domains),
            'active' => 0,
            'attention' => 0,
            'release_ready' => 0,
            'completed' => 0,
        ];
        foreach ($domains as $domain) {
            $status = strtoupper((string) ($domain['status'] ?? ''));
            if (in_array($status, ['ANALYSIS','DECOMPOSITION','ARCHITECTURE','READY_FOR_IMPLEMENTATION','IMPLEMENTATION','INTEGRATION','DOMAIN_QA','HUMAN_APPROVAL'], true)) {
                ++$stats['active'];
            }
            if (in_array($status, ['BLOCKED','FAILED'], true)) ++$stats['attention'];
            if ($status === EngineeringDomainStatus::RELEASE_READY->value) ++$stats['release_ready'];
            if ($status === EngineeringDomainStatus::COMPLETED->value) ++$stats['completed'];
        }

        return new Response($this->twig->render('experience/engineering/domains/index.html.twig', [
            'shell' => $this->shell($tenant, 'Engineering Domains', [
                new ShellBreadcrumb('Workspace', '/admin'),
                new ShellBreadcrumb('Engineering', '/admin/engineering'),
                new ShellBreadcrumb('Domains'),
            ]),
            'page' => $this->pages->create(
                PageArchetype::SystemControlSurface,
                ['PageHeader','Toolbar','EntityList','EmptyState','ErrorState'],
                'normal',
            ),
            'domains' => $domains,
            'stats' => $stats,
            'csrfToken' => $this->csrf->token($request),
            'statusMessage' => trim((string) $request->query->get('status_message', '')),
        ]));
    }

    public function create(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.', Response::HTTP_BAD_REQUEST);

        $domainId = null;
        try {
            $domainId = $this->runtime->create(
                organizationId: $tenant->organizationId()->value(),
                domainKey: (string) $request->request->get('domain_key', ''),
                name: (string) $request->request->get('name', ''),
                masterSpecification: (string) $request->request->get('master_specification', ''),
                targetRepository: (string) $request->request->get('target_repository', ''),
                targetBranch: (string) $request->request->get('target_branch', ''),
                createdBy: 'user:'.$tenant->userId()->value(),
                maxParallelFeatures: max(0, min(20, (int) $request->request->get('max_parallel_features', 0))),
                maxParallelDevelopers: max(0, min(20, (int) $request->request->get('max_parallel_developers', 0))),
                maxParallelReviews: max(0, min(20, (int) $request->request->get('max_parallel_reviews', 0))),
                maxParallelQa: max(0, min(20, (int) $request->request->get('max_parallel_qa', 0))),
            );

            if ($request->request->getBoolean('start_now', true)) {
                $planned = $this->runtime->plan(
                    $domainId,
                    $tenant->organizationId()->value(),
                    $this->correlation('plan', $domainId),
                );
                if (($planned['domain']['status'] ?? null) === EngineeringDomainStatus::READY_FOR_IMPLEMENTATION->value) {
                    $this->runtime->tick(
                        $domainId,
                        $tenant->organizationId()->value(),
                        $this->correlation('tick', $domainId),
                    );
                }
            }

            return $this->redirectStatus('/admin/engineering/domains/'.$domainId, 'Domain Initiative створено та передано Runtime.');
        } catch (Throwable $error) {
            $target = $domainId !== null ? '/admin/engineering/domains/'.$domainId : '/admin/engineering/domains';
            return $this->redirectStatus($target, 'ERROR: '.$error->getMessage());
        }
    }

    public function show(Request $request, string $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        try {
            $domainId = EngineeringId::assert($id);
            $data = $this->runtime->view($domainId, $tenant->organizationId()->value());
            $domain = is_array($data['domain'] ?? null) ? $data['domain'] : [];
            $features = is_array($data['features'] ?? null) ? $data['features'] : [];
            $artifacts = is_array($data['artifacts'] ?? null) ? $data['artifacts'] : [];
            $artifactByType = [];
            foreach ($artifacts as $artifact) {
                if (!is_array($artifact)) continue;
                $type = (string) ($artifact['type'] ?? '');
                if ($type !== '') $artifactByType[$type] = $artifact;
            }

            $featureStats = [
                'total' => count($features),
                'completed' => 0,
                'running' => 0,
                'waiting' => 0,
                'blocked' => 0,
            ];
            foreach ($features as $feature) {
                $status = strtoupper((string) ($feature['status'] ?? ''));
                if ($status === 'COMPLETED') ++$featureStats['completed'];
                elseif ($status === 'RUNNING') ++$featureStats['running'];
                elseif (in_array($status, ['WAITING','READY','NOT_STARTED'], true)) ++$featureStats['waiting'];
                elseif (in_array($status, ['BLOCKED','FAILED','STALE','REVALIDATION_REQUIRED'], true)) ++$featureStats['blocked'];
            }

            $status = strtoupper((string) ($domain['status'] ?? ''));
            $actions = [
                'plan' => $status === EngineeringDomainStatus::DRAFT->value
                    || (in_array($status, [EngineeringDomainStatus::BLOCKED->value, EngineeringDomainStatus::FAILED->value], true) && $features === []),
                'tick' => in_array($status, [
                    EngineeringDomainStatus::READY_FOR_IMPLEMENTATION->value,
                    EngineeringDomainStatus::IMPLEMENTATION->value,
                    EngineeringDomainStatus::INTEGRATION->value,
                ], true),
                'verify' => in_array($status, [
                    EngineeringDomainStatus::INTEGRATION->value,
                    EngineeringDomainStatus::DOMAIN_QA->value,
                ], true),
                'approve' => $status === EngineeringDomainStatus::RELEASE_READY->value,
            ];

            return new Response($this->twig->render('experience/engineering/domains/show.html.twig', [
                'shell' => $this->shell($tenant, (string) ($domain['name'] ?? 'Engineering Domain'), [
                    new ShellBreadcrumb('Workspace', '/admin'),
                    new ShellBreadcrumb('Engineering', '/admin/engineering'),
                    new ShellBreadcrumb('Domains', '/admin/engineering/domains'),
                    new ShellBreadcrumb((string) ($domain['name'] ?? 'Domain')),
                ]),
                'page' => $this->pages->create(
                    PageArchetype::EntityWorkspace,
                    ['WorkspaceHeader','EntityHeader','KpiStrip','Timeline','ActionBar','ContextPanel','ErrorState'],
                    'normal',
                ),
                'engineeringDomain' => $data,
                'domain' => $domain,
                'featureStats' => $featureStats,
                'artifactByType' => $artifactByType,
                'featureFlags' => is_array($artifactByType['DOMAIN_FEATURE_FLAGS']['content'] ?? null)
                    ? $artifactByType['DOMAIN_FEATURE_FLAGS']['content']
                    : ['DOMAIN_ENABLED' => false, 'FEATURE_ENABLED' => [], 'INTEGRATION_ENABLED' => false, 'PRODUCTION_EXECUTION_ENABLED' => false],
                'actions' => $actions,
                'csrfToken' => $this->csrf->token($request),
                'statusMessage' => trim((string) $request->query->get('status_message', '')),
            ]));
        } catch (Throwable $error) {
            return $this->redirectStatus('/admin/engineering/domains', 'ERROR: '.$error->getMessage());
        }
    }

    public function plan(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'plan');
    }

    public function tick(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'tick');
    }

    public function verify(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'verify');
    }

    public function approve(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'approve');
    }

    public function featureFlags(Request $request, string $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.', Response::HTTP_BAD_REQUEST);

        try {
            $domainId = EngineeringId::assert($id);
            $enabledFeatures = [];
            foreach ($request->request->all('feature_enabled') as $featureKey => $value) {
                $featureKey = trim((string) $featureKey);
                if ($featureKey !== '') $enabledFeatures[$featureKey] = true;
            }
            $this->runtime->updateFeatureFlags(
                $domainId,
                $tenant->organizationId()->value(),
                [
                    'DOMAIN_ENABLED' => $request->request->getBoolean('domain_enabled'),
                    'FEATURE_ENABLED' => $enabledFeatures,
                    'INTEGRATION_ENABLED' => $request->request->getBoolean('integration_enabled'),
                    'PRODUCTION_EXECUTION_ENABLED' => $request->request->getBoolean('production_execution_enabled'),
                ],
                'user:'.$tenant->userId()->value(),
            );
            return $this->redirectStatus('/admin/engineering/domains/'.$domainId, 'Domain feature flags оновлено.');
        } catch (Throwable $error) {
            return $this->redirectStatus('/admin/engineering/domains/'.rawurlencode($id), 'ERROR: '.$error->getMessage());
        }
    }

    private function mutate(Request $request, string $id, string $action): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.', Response::HTTP_BAD_REQUEST);

        try {
            $domainId = EngineeringId::assert($id);
            $organizationId = $tenant->organizationId()->value();
            $result = match ($action) {
                'plan' => $this->runtime->plan($domainId, $organizationId, $this->correlation('plan', $domainId)),
                'tick' => $this->runtime->tick($domainId, $organizationId, $this->correlation('tick', $domainId)),
                'verify' => $this->runtime->verify($domainId, $organizationId, $this->correlation('verify', $domainId)),
                'approve' => $this->runtime->approveRelease($domainId, $organizationId, 'user:'.$tenant->userId()->value()),
                default => throw new \InvalidArgumentException('Unknown Domain Runtime action.'),
            };

            $status = (string) ($result['domain']['status'] ?? $result['status'] ?? 'updated');
            return $this->redirectStatus('/admin/engineering/domains/'.$domainId, 'Domain Runtime: '.$action.' · '.$status.'.');
        } catch (Throwable $error) {
            return $this->redirectStatus('/admin/engineering/domains/'.rawurlencode($id), 'ERROR: '.$error->getMessage());
        }
    }

    private function manager(): TenantContext|Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) return new RedirectResponse('/auth/login');
        if (!$tenant->isManager() || !$tenant->allows(TenantPermissions::MANAGE)) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }
        return $tenant;
    }

    private function shell(TenantContext $tenant, string $title, array $breadcrumbs): mixed
    {
        return $this->shells->create(
            $tenant,
            new WebExtensionContext(
                organizationId: $tenant->organizationId()->value(),
                role: $tenant->role()->value(),
                surface: 'workspace',
                activeSection: 'administration',
                activeItem: 'engineering',
            ),
            $title,
            $breadcrumbs,
        );
    }

    private function correlation(string $action, string $domainId): string
    {
        return 'engineering-domain:web:'.$action.':'.$domainId.':'.EngineeringId::generate();
    }

    private function redirectStatus(string $target, string $message): RedirectResponse
    {
        return new RedirectResponse(
            $target.(str_contains($target, '?') ? '&' : '?').'status_message='.rawurlencode($message),
            Response::HTTP_SEE_OTHER,
        );
    }
}

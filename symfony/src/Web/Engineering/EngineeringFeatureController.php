<?php
declare(strict_types=1);

namespace App\Web\Engineering;

use App\Engineering\Application\DTO\EngineeringRequest;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Service\EngineeringContinueService;
use App\Engineering\Application\Service\EngineeringHumanDecisionService;
use App\Engineering\Application\Service\EngineeringOrchestrator;
use App\Engineering\Application\Service\EngineeringStatusService;
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

final readonly class EngineeringFeatureController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private EngineeringStatusService $engineering,
        private EngineeringFeatureStoreInterface $features,
        private EngineeringOrchestrator $orchestrator,
        private EngineeringContinueService $continue,
        private EngineeringHumanDecisionService $decisions,
        private SessionCsrfValidator $csrf,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
    ) {}

    public function index(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

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
        ]);

        return new Response(
            $this->twig->render('experience/engineering/index.html.twig', [
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::Collection,
                    ['PageHeader', 'Toolbar', 'EntityList', 'EmptyState', 'ErrorState'],
                    'normal',
                ),
                'features' => $this->features->recentForOrganization($tenant->organizationId()->value(), 50),
                'csrfToken' => $this->csrf->token($request),
                'statusMessage' => trim((string) $request->query->get('status_message', '')),
            ]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }

    public function create(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.', Response::HTTP_BAD_REQUEST);

        $description = trim((string) $request->request->get('description', ''));
        $title = trim((string) $request->request->get('title', ''));
        $priority = strtoupper(trim((string) $request->request->get('priority', 'P2')));
        if ($description === '') {
            return $this->redirectStatus('/admin/engineering', 'ERROR: Опис engineering request обов’язковий.');
        }
        if (!in_array($priority, ['P0', 'P1', 'P2', 'P3'], true)) $priority = 'P2';

        try {
            $featureId = $this->orchestrator->create(
                new EngineeringRequest(
                    requestId: EngineeringId::generate(),
                    description: $description,
                    title: $title !== '' ? $title : null,
                    sourceType: 'web',
                    sourceReference: null,
                    priority: $priority,
                ),
                $tenant->organizationId()->value(),
                'user:' . $tenant->userId()->value(),
            );

            $message = 'Engineering feature створено.';
            if ($request->request->getBoolean('start')) {
                $result = $this->orchestrator->start(
                    $featureId,
                    $tenant->organizationId()->value(),
                    $this->correlation('create', $featureId),
                );
                $message = 'Engineering workflow запущено · ' . $result->state . '.';
            }

            return $this->redirectStatus('/admin/engineering/' . $featureId, $message);
        } catch (Throwable $error) {
            return $this->redirectStatus('/admin/engineering', 'ERROR: ' . $error->getMessage());
        }
    }

    public function show(Request $request, string $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

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
            new ShellBreadcrumb('Engineering', '/admin/engineering'),
            new ShellBreadcrumb((string) ($data['feature']['title'] ?? $featureId)),
        ]);

        $summary = $this->summary($data);

        return new Response(
            $this->twig->render('experience/engineering/feature.html.twig', [
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::SystemControlSurface,
                    ['PageHeader', 'Toolbar', 'KpiStrip', 'EntityList', 'EmptyState', 'ErrorState'],
                    'normal',
                ),
                'engineering' => $data,
                'summary' => $summary,
                'csrfToken' => $this->csrf->token($request),
                'statusMessage' => trim((string) $request->query->get('status_message', '')),
            ]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }

    public function run(Request $request, string $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.', Response::HTTP_BAD_REQUEST);

        try {
            $featureId = EngineeringId::assert($id);
            $status = $this->ownedStatus($tenant, $featureId);
            $result = ($status['workflow'] ?? null) === null
                ? $this->orchestrator->start(
                    $featureId,
                    $tenant->organizationId()->value(),
                    $this->correlation('run', $featureId),
                )
                : $this->continue->continueFeature(
                    $featureId,
                    $tenant->organizationId()->value(),
                    $this->correlation('continue', $featureId),
                );

            return $this->redirectStatus('/admin/engineering/' . $featureId, 'Workflow · ' . $result->state . '.');
        } catch (Throwable $error) {
            return $this->redirectStatus('/admin/engineering/' . rawurlencode($id), 'ERROR: ' . $error->getMessage());
        }
    }

    public function humanDecision(Request $request, string $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.', Response::HTTP_BAD_REQUEST);

        try {
            $featureId = EngineeringId::assert($id);
            $status = $this->ownedStatus($tenant, $featureId);
            $requestId = EngineeringId::assert(trim((string) $request->request->get('request_id', '')));
            $option = trim((string) $request->request->get('option', ''));
            if ($option === '') throw new \InvalidArgumentException('Оберіть рішення.');

            $known = false;
            foreach ($status['open_human_decisions'] ?? [] as $decision) {
                if (($decision['id'] ?? null) === $requestId) {
                    $known = true;
                    break;
                }
            }
            if (!$known) throw new \LogicException('Human decision уже не є відкритим.');

            $result = $this->decisions->answerAndResume(
                requestId: $requestId,
                selectedOption: $option,
                comment: trim((string) $request->request->get('comment', '')) ?: null,
                decidedBy: 'user:' . $tenant->userId()->value(),
                organizationId: $tenant->organizationId()->value(),
                correlationId: $this->correlation('decision', $featureId),
            );

            return $this->redirectStatus('/admin/engineering/' . $featureId, 'Human decision прийнято · ' . $result->state . '.');
        } catch (Throwable $error) {
            return $this->redirectStatus('/admin/engineering/' . rawurlencode($id), 'ERROR: ' . $error->getMessage());
        }
    }

    /** @return array<string,mixed> */
    private function ownedStatus(TenantContext $tenant, string $featureId): array
    {
        $status = $this->engineering->status($featureId);
        if (($status['feature']['organization_id'] ?? null) !== $tenant->organizationId()->value()) {
            throw new \RuntimeException('Engineering feature does not belong to the current organization.');
        }
        return $status;
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

    private function correlation(string $action, string $featureId): string
    {
        return 'engineering:web:' . $action . ':' . $featureId . ':' . EngineeringId::generate();
    }

    private function redirectStatus(string $target, string $message): RedirectResponse
    {
        $separator = str_contains($target, '?') ? '&' : '?';
        return new RedirectResponse($target . $separator . 'status_message=' . rawurlencode($message), Response::HTTP_SEE_OTHER);
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

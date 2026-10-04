<?php
declare(strict_types=1);

namespace App\Web\Engineering;

use App\Application\Engineering\Command\ContinueEngineeringWorkflowsCommand;
use App\Application\Engineering\Command\RunEngineeringFeatureCommand;
use App\Engineering\Application\DTO\EngineeringRequest;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Application\Service\EngineeringCancelService;
use App\Engineering\Application\Service\EngineeringFeatureManagementService;
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
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;
use Twig\Environment;

final readonly class EngineeringFeatureController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private EngineeringStatusService $engineering,
        private EngineeringFeatureStoreInterface $features,
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringOrchestrator $orchestrator,
        private EngineeringCancelService $cancel,
        private EngineeringFeatureManagementService $featureManagement,
        private EngineeringHumanDecisionService $decisions,
        private SessionCsrfValidator $csrf,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private MessageBusInterface $commandBus,
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

        $features = $this->features->recentForOrganization($tenant->organizationId()->value(), 50);
        $queue = $this->workflows->queueForOrganization($tenant->organizationId()->value(), 100);
        foreach ($queue as $index => &$row) {
            $row['position'] = $index + 1;
        }
        unset($row);

        $active = $this->workflows->activeForOrganization($tenant->organizationId()->value(), 100);
        $activeByFeature = [];
        foreach ($active as $row) {
            $activeByFeature[$row['feature_id']] = $row;
        }

        $queuedFeatureIds = array_fill_keys(
            array_map(static fn (array $row): string => (string) $row['feature_id'], $queue),
            true,
        );
        $activeExecutions = array_values(array_filter(
            $active,
            static fn (array $row): bool =>
                ($row['workflow_type'] ?? null) === 'ENGINEERING_IMMEDIATE'
                || !isset($queuedFeatureIds[(string) ($row['feature_id'] ?? '')]),
        ));

        foreach ($features as &$feature) {
            $workflow = $activeByFeature[$feature['id']] ?? null;
            $feature['workflow_id'] = $workflow['workflow_id'] ?? null;
            $feature['workflow_state'] = $workflow['state'] ?? null;
            $feature['workflow_status'] = $workflow['workflow_status'] ?? null;
            $feature['workflow_type'] = $workflow['workflow_type'] ?? null;
            $feature['workflow_last_activity_at'] = $workflow['last_activity_at'] ?? null;
        }
        unset($feature);

        return new Response(
            $this->twig->render('experience/engineering/index.html.twig', [
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::Collection,
                    ['PageHeader', 'Toolbar', 'EntityList', 'EmptyState', 'ErrorState'],
                    'normal',
                ),
                'features' => $features,
                'queue' => $queue,
                'activeExecutions' => $activeExecutions,
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
        $executionMode = strtolower(trim((string) $request->request->get('execution_mode', '')));
        if ($executionMode === '') {
            $executionMode = $request->request->getBoolean('start') ? 'immediate' : 'draft';
        }
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

            $message = 'Engineering feature створено як draft.';
            if ($executionMode === 'queue') {
                $result = $this->orchestrator->queue(
                    $featureId,
                    $tenant->organizationId()->value(),
                    $this->correlation('queue', $featureId),
                );
                $this->commandBus->dispatch(new ContinueEngineeringWorkflowsCommand('web-queue'));
                $message = 'Engineering workflow додано в priority queue · ' . $result->state . '.';
            } elseif ($executionMode === 'immediate') {
                $result = $this->orchestrator->queueImmediate(
                    $featureId,
                    $tenant->organizationId()->value(),
                    $this->correlation('immediate', $featureId),
                );
                $this->commandBus->dispatch(new RunEngineeringFeatureCommand(
                    featureId: $featureId,
                    organizationId: $tenant->organizationId()->value(),
                    trigger: 'web-create-immediate',
                ));
                $message = 'Engineering workflow передано immediate worker · ' . $result->state . '.';
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

    public function queue(Request $request, string $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.', Response::HTTP_BAD_REQUEST);

        try {
            $featureId = EngineeringId::assert($id);
            $this->ownedStatus($tenant, $featureId);
            $result = $this->orchestrator->queue(
                $featureId,
                $tenant->organizationId()->value(),
                $this->correlation('queue', $featureId),
            );
            $this->commandBus->dispatch(new ContinueEngineeringWorkflowsCommand('web-queue'));

            return $this->redirectStatus('/admin/engineering', 'Workflow додано в priority queue · ' . $result->state . '.');
        } catch (Throwable $error) {
            return $this->redirectStatus('/admin/engineering', 'ERROR: ' . $error->getMessage());
        }
    }

    public function run(Request $request, string $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.', Response::HTTP_BAD_REQUEST);

        try {
            $featureId = EngineeringId::assert($id);
            $status = $this->ownedStatus($tenant, $featureId);
            foreach ($status['agent_runs'] ?? [] as $agentRun) {
                if (($agentRun['status'] ?? null) === 'RUNNING') {
                    throw new \LogicException('Engineering AgentRun already RUNNING; duplicate immediate execution is not allowed.');
                }
            }

            $activeWorkflowId = $this->workflows->activeIdForFeature($featureId);
            if ($activeWorkflowId === null) {
                $this->orchestrator->queueImmediate(
                    $featureId,
                    $tenant->organizationId()->value(),
                    $this->correlation('run', $featureId),
                );
            } else {
                $this->workflows->markImmediate($activeWorkflowId);
            }

            $this->commandBus->dispatch(new RunEngineeringFeatureCommand(
                featureId: $featureId,
                organizationId: $tenant->organizationId()->value(),
                trigger: 'web-run-now',
            ));

            return $this->redirectStatus('/admin/engineering/' . $featureId, 'Workflow передано immediate worker.');
        } catch (Throwable $error) {
            return $this->redirectStatus('/admin/engineering/' . rawurlencode($id), 'ERROR: ' . $error->getMessage());
        }
    }

    public function update(Request $request, string $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.', Response::HTTP_BAD_REQUEST);

        try {
            $featureId = EngineeringId::assert($id);
            $this->featureManagement->update(
                featureId: $featureId,
                organizationId: $tenant->organizationId()->value(),
                title: (string) $request->request->get('title', ''),
                description: (string) $request->request->get('description', ''),
                priority: (string) $request->request->get('priority', ''),
            );

            return $this->redirectStatus('/admin/engineering/' . $featureId, 'Engineering feature оновлено.');
        } catch (Throwable $error) {
            return $this->redirectStatus('/admin/engineering/' . rawurlencode($id), 'ERROR: ' . $error->getMessage());
        }
    }

    public function delete(Request $request, string $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.', Response::HTTP_BAD_REQUEST);

        try {
            $featureId = EngineeringId::assert($id);
            if (!$request->request->getBoolean('confirm_delete')) {
                throw new \InvalidArgumentException('Підтвердьте видалення Engineering feature.');
            }

            $this->featureManagement->delete(
                featureId: $featureId,
                organizationId: $tenant->organizationId()->value(),
            );

            return $this->redirectStatus('/admin/engineering', 'Engineering feature видалено.');
        } catch (Throwable $error) {
            return $this->redirectStatus('/admin/engineering/' . rawurlencode($id), 'ERROR: ' . $error->getMessage());
        }
    }

    public function cancel(Request $request, string $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.', Response::HTTP_BAD_REQUEST);

        try {
            $featureId = EngineeringId::assert($id);
            $this->ownedStatus($tenant, $featureId);
            $result = $this->cancel->cancel(
                featureId: $featureId,
                actorId: 'user:' . $tenant->userId()->value(),
                reason: trim((string) $request->request->get('reason', '')) ?: 'Cancelled from Engineering UI.',
            );

            return $this->redirectStatus('/admin/engineering/' . $featureId, 'Workflow скасовано · ' . $result['state'] . '.');
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

            $result = $this->decisions->answerAndPrepareResume(
                requestId: $requestId,
                selectedOption: $option,
                comment: trim((string) $request->request->get('comment', '')) ?: null,
                decidedBy: 'user:' . $tenant->userId()->value(),
                organizationId: $tenant->organizationId()->value(),
            );

            if ($result->next->agent !== null) {
                $this->workflows->markImmediate($result->workflowId);
                $this->commandBus->dispatch(new RunEngineeringFeatureCommand(
                    featureId: $featureId,
                    organizationId: $tenant->organizationId()->value(),
                    trigger: 'web-human-decision',
                ));
            }

            return $this->redirectStatus(
                '/admin/engineering/' . $featureId,
                $result->next->agent !== null
                    ? 'Human decision прийнято · resume передано immediate worker.'
                    : 'Human decision прийнято · ' . $result->state . '.',
            );
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

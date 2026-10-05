<?php
declare(strict_types=1);

namespace App\Web\Engineering;

use App\Application\Engineering\Command\ContinueEngineeringWorkflowsCommand;
use App\Application\Engineering\Command\RunEngineeringFeatureCommand;
use App\Engineering\Application\DTO\EngineeringRequest;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Application\Observability\EngineeringObservabilityReadModelInterface;
use App\Engineering\Application\Service\EngineeringCancelService;
use App\Engineering\Application\Service\EngineeringFeatureManagementService;
use App\Engineering\Application\Service\EngineeringFinalizeService;
use App\Engineering\Application\Service\EngineeringHumanDecisionService;
use App\Engineering\Application\Service\EngineeringOrchestrator;
use App\Engineering\Application\Service\EngineeringStatusService;
use App\Engineering\Application\Service\EngineeringUiActionResolver;
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
use Symfony\Component\HttpFoundation\JsonResponse;
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
        private EngineeringObservabilityReadModelInterface $observability,
        private EngineeringOrchestrator $orchestrator,
        private EngineeringCancelService $cancel,
        private EngineeringFeatureManagementService $featureManagement,
        private EngineeringFinalizeService $finalize,
        private EngineeringHumanDecisionService $decisions,
        private EngineeringUiActionResolver $uiActions,
        private SessionCsrfValidator $csrf,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private MessageBusInterface $commandBus,
    ) {}

    public function index(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        $this->workflows->refreshRuntimeHealthForOrganization($tenant->organizationId()->value());

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
        $queuePositionByFeature = [];
        foreach ($queue as $index => &$row) {
            $row['position'] = $index + 1;
            $queuePositionByFeature[(string) $row['feature_id']] = $index + 1;
        }
        unset($row);

        $active = $this->workflows->activeForOrganization($tenant->organizationId()->value(), 100);
        $activeByFeature = [];
        foreach ($active as $row) {
            $activeByFeature[(string) $row['feature_id']] = $row;
        }

        $featureIds = array_values(array_map(
            static fn (array $feature): string => (string) $feature['id'],
            $features,
        ));
        $facts = $this->observability->workspaceFactsForFeatures($featureIds);

        $workspaceRows = [];
        $stats = [
            'running' => 0,
            'queued' => count($queue),
            'attention' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'total' => count($features),
        ];

        foreach ($features as $feature) {
            $featureId = (string) $feature['id'];
            $workflow = $activeByFeature[$featureId] ?? null;
            if ($workflow === null) {
                $workflowId = $this->workflows->latestIdForFeature($featureId);
                $workflow = $workflowId !== null ? $this->workflows->view($workflowId) : null;
                if (is_array($workflow)) {
                    $workflow['workflow_id'] = $workflow['id'] ?? $workflowId;
                    $workflow['workflow_status'] = $workflow['status'] ?? null;
                }
            }

            $state = (string) ($workflow['state'] ?? $feature['status'] ?? 'NEW');
            $status = (string) ($workflow['workflow_status'] ?? $workflow['status'] ?? $feature['status'] ?? 'NEW');
            $lastActivity = $workflow['last_activity_at'] ?? $feature['updated_at'] ?? null;
            $persistedHealth = strtoupper((string) ($workflow['health_status'] ?? ''));
            $health = in_array($persistedHealth, ['HEALTHY','STALE','STALLED','WAITING','TERMINAL'], true)
                ? $persistedHealth
                : $this->runtimeHealth($status, $state, is_string($lastActivity) ? $lastActivity : null);
            $fact = $facts[$featureId] ?? [];

            if (isset($queuePositionByFeature[$featureId])) {
                $displayStatus = 'QUEUED';
            } elseif (in_array($status, ['COMPLETED'], true) || $state === 'DONE') {
                $displayStatus = 'COMPLETED';
                ++$stats['completed'];
            } elseif ($status === 'CANCELLED' || $state === 'CANCELLED') {
                $displayStatus = 'CANCELLED';
                ++$stats['cancelled'];
            } elseif ($status === 'FAILED' || $state === 'FAILED') {
                $displayStatus = 'FAILED';
                ++$stats['attention'];
            } elseif (in_array($health, ['STALE','STALLED'], true)) {
                $displayStatus = $health;
                ++$stats['attention'];
            } elseif (in_array($state, ['READY_FOR_HUMAN_APPROVAL','HUMAN_DECISION_REQUIRED','BLOCKED','ESCALATED'], true)) {
                $displayStatus = $state;
                ++$stats['attention'];
            } elseif ($workflow !== null) {
                $displayStatus = 'RUNNING';
                ++$stats['running'];
            } else {
                $displayStatus = (string) ($feature['status'] ?? 'NEW');
            }

            $openDecisionCount = 0;
            if (in_array($state, ['HUMAN_DECISION_REQUIRED','BLOCKED','ESCALATED'], true)) {
                $openDecisionCount = 1;
            }
            $rowActions = $this->uiActions->resolve($feature, is_array($workflow) ? $workflow : null, $health, $openDecisionCount);

            $workspaceRows[] = array_merge($feature, [
                'workflow_id' => $workflow['workflow_id'] ?? $workflow['id'] ?? null,
                'workflow_state' => $state,
                'workflow_status' => $status,
                'workflow_type' => $workflow['workflow_type'] ?? null,
                'workflow_last_activity_at' => $lastActivity,
                'display_status' => $displayStatus,
                'health' => $health,
                'runtime_reason' => $workflow['runtime_reason'] ?? null,
                'stalled_at' => $workflow['stalled_at'] ?? null,
                'heartbeat_at' => $workflow['heartbeat_at'] ?? null,
                'queue_position' => $queuePositionByFeature[$featureId] ?? null,
                'tasks_total' => (int) ($fact['tasks_total'] ?? 0),
                'tasks_completed' => (int) ($fact['tasks_completed'] ?? 0),
                'agent_runs' => (int) ($fact['agent_runs'] ?? 0),
                'latest_agent_role' => $fact['latest_agent_role'] ?? null,
                'latest_agent_status' => $fact['latest_agent_status'] ?? null,
                'actions' => $rowActions,
                'usage' => $fact['usage'] ?? [
                    'available' => false,
                    'total_tokens' => null,
                    'cost_amount' => null,
                    'cost_currency' => null,
                ],
            ]);
        }

        $view = strtolower(trim((string) $request->query->get('view', 'all')));
        if (!in_array($view, ['all','running','queued','attention','completed','cancelled'], true)) $view = 'all';
        $query = mb_strtolower(trim((string) $request->query->get('q', '')));

        if ($view !== 'all' || $query !== '') {
            $workspaceRows = array_values(array_filter(
                $workspaceRows,
                static function (array $row) use ($view, $query): bool {
                    $displayStatus = strtoupper((string) ($row['display_status'] ?? ''));
                    $health = strtoupper((string) ($row['health'] ?? ''));
                    $matchesView = match ($view) {
                        'running' => $displayStatus === 'RUNNING',
                        'queued' => $displayStatus === 'QUEUED',
                        'attention' => in_array($displayStatus, ['FAILED','STALE','STALLED','READY_FOR_HUMAN_APPROVAL','HUMAN_DECISION_REQUIRED','BLOCKED','ESCALATED'], true)
                            || in_array($health, ['STALE','STALLED'], true),
                        'completed' => $displayStatus === 'COMPLETED',
                        'cancelled' => $displayStatus === 'CANCELLED',
                        default => true,
                    };
                    if (!$matchesView) return false;
                    if ($query === '') return true;
                    $haystack = mb_strtolower(trim(
                        (string) ($row['title'] ?? '').' '.
                        (string) ($row['description'] ?? '').' '.
                        (string) ($row['workflow_state'] ?? '').' '.
                        (string) ($row['latest_agent_role'] ?? '')
                    ));
                    return str_contains($haystack, $query);
                },
            ));
        }

        return new Response(
            $this->twig->render('experience/engineering/index.html.twig', [
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::Collection,
                    ['PageHeader', 'Toolbar', 'EntityList', 'EmptyState', 'ErrorState'],
                    'normal',
                ),
                'features' => $features,
                'workspaceRows' => $workspaceRows,
                'workspaceStats' => $stats,
                'workspaceView' => $view,
                'workspaceQuery' => $query,
                'queue' => $queue,
                'activeExecutions' => $active,
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
            $this->workflows->refreshRuntimeHealthForOrganization($tenant->organizationId()->value());
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

    public function live(Request $request, string $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        try {
            $featureId = EngineeringId::assert($id);
            $this->workflows->refreshRuntimeHealthForOrganization($tenant->organizationId()->value());
            $data = $this->ownedStatus($tenant, $featureId);
            $summary = $this->summary($data);

            return new JsonResponse([
                'ok' => true,
                'summary' => [
                    'state' => $summary['state'],
                    'workflow_status' => $summary['workflow_status'],
                    'health' => $summary['health'],
                    'current_agent' => $summary['current_agent'],
                    'latest_agent' => $summary['latest_agent'],
                    'active_operation' => $summary['active_operation'],
                    'duration_label' => $summary['duration_label'],
                    'agent_runs' => $summary['agent_runs'],
                    'tasks_total' => $summary['tasks_total'],
                    'tasks_completed' => $summary['tasks_completed'],
                    'tokens' => $summary['tokens'],
                    'cost' => $summary['cost'],
                    'cost_currency' => $summary['usage']['cost_currency'] ?? null,
                    'quality_state' => $summary['quality_state'],
                    'stages' => $summary['stages'],
                    'terminal' => $summary['terminal'],
                ],
                'heartbeat_at' => $data['workflow']['heartbeat_at'] ?? null,
                'runtime_reason' => $data['workflow']['runtime_reason'] ?? null,
                'timeline' => array_slice(is_array($data['timeline'] ?? null) ? $data['timeline'] : [], 0, 80),
                'execution_event_count' => count(is_array($data['execution_events'] ?? null) ? $data['execution_events'] : []),
                'server_time' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ], Response::HTTP_OK, ['Cache-Control' => 'no-store, private']);
        } catch (Throwable $error) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'engineering_live_status_failed',
                'message' => $error->getMessage(),
            ], Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store, private']);
        }
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
            $runtimeHealth = strtoupper((string) ($status['workflow']['health_status'] ?? 'UNKNOWN'));
            foreach ($status['agent_runs'] ?? [] as $agentRun) {
                if (strtoupper((string) ($agentRun['status'] ?? '')) === 'RUNNING' && $runtimeHealth !== 'STALLED') {
                    throw new \LogicException('Engineering AgentRun already RUNNING; duplicate immediate execution is not allowed.');
                }
            }
            $activeOperation = $this->activeOperation(is_array($status['execution_events'] ?? null) ? $status['execution_events'] : []);
            if ($activeOperation !== null && !in_array($runtimeHealth, ['STALE','STALLED'], true)) {
                throw new \LogicException('Engineering operation already in progress: '.($activeOperation['summary'] ?? $activeOperation['action'] ?? 'runtime operation').'.');
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

    public function finalize(Request $request, string $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;
        if (!$this->csrf->isValid($request)) return new Response('Invalid CSRF token.', Response::HTTP_BAD_REQUEST);

        try {
            $featureId = EngineeringId::assert($id);
            $status = $this->ownedStatus($tenant, $featureId);
            if (($status['workflow']['state'] ?? null) !== 'READY_FOR_HUMAN_APPROVAL') {
                throw new \LogicException('Workflow ще не готовий до підтвердження human merge.');
            }

            $result = $this->finalize->finalize(
                $featureId,
                'user:' . $tenant->userId()->value(),
            );

            return $this->redirectStatus(
                '/admin/engineering/' . $featureId,
                'Human merge перевірено · workflow завершено · ' . ($result['pull_request']['merge_revision'] ?? 'DONE') . '.',
            );
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
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $openFindings = array_values(array_filter(
            is_array($data['findings'] ?? null) ? $data['findings'] : [],
            static fn (mixed $finding): bool => is_array($finding) && ($finding['status'] ?? null) === 'OPEN',
        ));

        $runs = is_array($data['agent_runs'] ?? null) ? $data['agent_runs'] : [];
        $roles = [];
        foreach ($runs as $run) {
            if (!is_array($run)) continue;
            $role = (string) ($run['role'] ?? 'UNKNOWN');
            $roles[$role] ??= ['runs' => 0, 'completed' => 0, 'failed' => 0, 'running' => 0, 'tokens' => 0, 'cost' => 0.0];
            ++$roles[$role]['runs'];
            $runStatus = strtoupper((string) ($run['status'] ?? 'UNKNOWN'));
            if (in_array($runStatus, ['COMPLETED','APPROVED','PASS','PASSED','SUCCESS'], true)) ++$roles[$role]['completed'];
            if (in_array($runStatus, ['FAILED','ERROR','TIMED_OUT'], true)) ++$roles[$role]['failed'];
            if ($runStatus === 'RUNNING') ++$roles[$role]['running'];
            $ledgerUsage = is_array($run['ledger_usage'] ?? null) ? $run['ledger_usage'] : null;
            if (($ledgerUsage['total_tokens'] ?? null) !== null) {
                $roles[$role]['tokens'] += (int) $ledgerUsage['total_tokens'];
            } elseif (($run['tokens_input'] ?? null) !== null || ($run['tokens_output'] ?? null) !== null) {
                $roles[$role]['tokens'] += (int) ($run['tokens_input'] ?? 0) + (int) ($run['tokens_output'] ?? 0);
            }
            if (($ledgerUsage['cost_amount'] ?? null) !== null) {
                $roles[$role]['cost'] += (float) $ledgerUsage['cost_amount'];
            } elseif (($run['cost'] ?? null) !== null) {
                $roles[$role]['cost'] += (float) $run['cost'];
            }
        }

        $currentAgent = null;
        $latestAgent = null;
        for ($i = count($runs) - 1; $i >= 0; --$i) {
            $candidate = $runs[$i] ?? null;
            if (!is_array($candidate)) continue;
            if ($latestAgent === null) {
                $latestAgent = [
                    'role' => (string) ($candidate['role'] ?? 'AGENT'),
                    'run_id' => (string) ($candidate['id'] ?? ''),
                    'task_id' => $candidate['task_id'] ?? null,
                    'status' => strtoupper((string) ($candidate['status'] ?? 'UNKNOWN')),
                    'started_at' => $candidate['started_at'] ?? null,
                    'finished_at' => $candidate['finished_at'] ?? null,
                    'error_type' => $candidate['error_type'] ?? null,
                    'error_message' => $candidate['error_message'] ?? null,
                ];
            }
            if (strtoupper((string) ($candidate['status'] ?? '')) !== 'RUNNING') continue;
            $currentAgent = [
                'role' => (string) ($candidate['role'] ?? 'AGENT'),
                'run_id' => (string) ($candidate['id'] ?? ''),
                'task_id' => $candidate['task_id'] ?? null,
                'status' => 'RUNNING',
                'started_at' => $candidate['started_at'] ?? null,
            ];
            break;
        }
        $activeOperation = $this->activeOperation(is_array($data['execution_events'] ?? null) ? $data['execution_events'] : []);

        $reviewReached = isset($roles['REVIEWER']);
        $qaReached = isset($roles['QA']);
        $qualityState = (!$reviewReached && !$qaReached)
            ? 'NOT_REACHED'
            : ($openFindings !== [] ? 'FINDINGS' : (($reviewReached && $qaReached) ? 'PASSED' : 'IN_PROGRESS'));

        $finalReport = $data['artifacts']['FINAL_REPORT']['content'] ?? null;
        $development = $data['artifacts']['DEVELOPMENT_RESULT']['content'] ?? null;
        $pullRequest = is_array($finalReport['pull_request'] ?? null)
            ? $finalReport['pull_request']
            : [
                'number' => is_array($development) ? ($development['pull_request'] ?? null) : null,
                'url' => is_array($development) ? ($development['pull_request_url'] ?? null) : null,
            ];

        $state = (string) ($data['workflow']['state'] ?? ($data['feature']['status'] ?? 'NEW'));
        $workflowStatus = (string) ($data['workflow']['status'] ?? ($data['feature']['status'] ?? 'NEW'));
        $terminal = in_array($state, ['DONE','CANCELLED','FAILED'], true)
            || in_array($workflowStatus, ['COMPLETED','CANCELLED','FAILED'], true);
        $waitsForHuman = in_array($state, ['READY_FOR_HUMAN_APPROVAL','HUMAN_DECISION_REQUIRED','BLOCKED','ESCALATED'], true);
        $resolvedHealth = in_array(strtoupper((string) ($data['workflow']['health_status'] ?? '')), ['HEALTHY','DEGRADED','STALE','STALLED','WAITING','TERMINAL'], true)
            ? strtoupper((string) $data['workflow']['health_status'])
            : $this->runtimeHealth(
                $workflowStatus,
                $state,
                is_string($data['workflow']['last_activity_at'] ?? null) ? $data['workflow']['last_activity_at'] : null,
            );
        $actions = $this->uiActions->resolve(
            is_array($data['feature'] ?? null) ? $data['feature'] : [],
            is_array($data['workflow'] ?? null) ? $data['workflow'] : null,
            $resolvedHealth,
            count($data['open_human_decisions'] ?? []),
        );
        $executionActive = ($currentAgent !== null || $activeOperation !== null)
            && !in_array($resolvedHealth, ['STALE','STALLED'], true);
        if ($executionActive) {
            $actions['run'] = false;
            $actions['continue'] = false;
            $actions['resume'] = false;
            $actions['retry'] = false;
        }
        $stages = $this->stagePipeline($data, $state, $workflowStatus);
        $durationSeconds = $this->durationSeconds(
            is_string($data['workflow']['started_at'] ?? null) ? $data['workflow']['started_at'] : null,
            is_string($data['workflow']['finished_at'] ?? null) ? $data['workflow']['finished_at'] : null,
        );

        return [
            'state' => $state,
            'workflow_status' => $workflowStatus,
            'health' => $resolvedHealth,
            'terminal' => $terminal,
            'waits_for_human' => $waitsForHuman,
            'actions' => $actions,
            'can_cancel' => $actions['cancel'],
            'can_run' => $actions['continue'] || $actions['resume'] || $actions['run'],
            'can_retry' => $actions['retry'],
            'tasks_total' => count($data['tasks'] ?? []),
            'tasks_completed' => count(array_filter(
                $data['tasks'] ?? [],
                static fn (mixed $task): bool => is_array($task) && ($task['status'] ?? null) === 'COMPLETED',
            )),
            'agent_runs' => count($runs),
            'roles' => $roles,
            'current_agent' => $currentAgent,
            'latest_agent' => $latestAgent,
            'active_operation' => $activeOperation,
            'execution_active' => $executionActive,
            'duration_seconds' => $durationSeconds,
            'duration_label' => $this->durationLabel($durationSeconds),
            'stages' => $stages,
            'usage' => $usage,
            'tokens' => $usage['total_tokens'] ?? null,
            'cost' => $usage['cost_amount'] ?? null,
            'usage_source' => $usage['source'] ?? 'UNAVAILABLE',
            'quality_state' => $qualityState,
            'review_reached' => $reviewReached,
            'qa_reached' => $qaReached,
            'open_findings' => $openFindings,
            'pull_request' => $pullRequest,
            'final_report' => is_array($finalReport) ? $finalReport : null,
        ];
    }

    /** @param list<array<string,mixed>> $events @return array<string,mixed>|null */
    private function activeOperation(array $events): ?array
    {
        $resolved = [];
        foreach ($events as $event) {
            if (!is_array($event)) continue;
            $status = strtoupper(trim((string) ($event['status'] ?? '')));
            $action = trim((string) ($event['action'] ?? ''));
            if ($action === '') continue;
            $correlation = trim((string) ($event['correlation_id'] ?? ''));
            $category = strtoupper(trim((string) ($event['category'] ?? 'RUNTIME')));
            $key = $category.'|'.$action.'|'.$correlation;
            if (in_array($status, ['COMPLETED','FAILED','ERROR','CANCELLED','TIMED_OUT'], true)) {
                $resolved[$key] = true;
                continue;
            }
            if ($status !== 'STARTED' || isset($resolved[$key])) continue;
            return [
                'category' => $category,
                'action' => $action,
                'summary' => (string) ($event['summary'] ?? $action),
                'status' => 'STARTED',
                'occurred_at' => $event['occurred_at'] ?? null,
                'correlation_id' => $correlation !== '' ? $correlation : null,
                'agent_run_id' => $event['agent_run_id'] ?? null,
            ];
        }
        return null;
    }

    /** @return list<array{key:string,label:string,status:string}> */
    private function stagePipeline(array $data, string $state, string $workflowStatus): array
    {
        $order = [
            'ANALYSIS' => 'Analysis',
            'QA_PLANNING' => 'QA Plan',
            'ARCHITECTURE_PENDING' => 'Architecture',
            'DEVELOPMENT_RUNNING' => 'Development',
            'REVIEW_PENDING' => 'Review',
            'QA_PENDING' => 'QA',
        ];
        $keys = array_keys($order);

        if ($state === 'DONE' || $workflowStatus === 'COMPLETED' || $state === 'READY_FOR_HUMAN_APPROVAL') {
            return array_map(
                static fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'status' => 'COMPLETED'],
                $keys,
                array_values($order),
            );
        }

        $effectiveState = $state;
        if (in_array($state, ['HUMAN_DECISION_REQUIRED','BLOCKED','ESCALATED'], true)) {
            $resume = trim((string) ($data['workflow']['resume_state'] ?? ''));
            if ($resume !== '') $effectiveState = $resume;
        }
        if (in_array($state, ['CANCELLED','FAILED'], true) || in_array($workflowStatus, ['CANCELLED','FAILED'], true)) {
            $transitions = is_array($data['transitions'] ?? null) ? $data['transitions'] : [];
            $last = $transitions !== [] ? $transitions[array_key_last($transitions)] : null;
            $from = is_array($last) ? trim((string) ($last['from'] ?? '')) : '';
            if (array_key_exists($from, $order)) $effectiveState = $from;
        }

        $currentIndex = array_search($effectiveState, $keys, true);
        if ($currentIndex === false) $currentIndex = -1;

        $result = [];
        foreach ($keys as $index => $key) {
            $status = 'NOT_REACHED';
            if ($currentIndex >= 0 && $index < $currentIndex) $status = 'COMPLETED';
            if ($index === $currentIndex) {
                $status = match (true) {
                    in_array($state, ['CANCELLED'], true) || $workflowStatus === 'CANCELLED' => 'CANCELLED',
                    in_array($state, ['FAILED'], true) || $workflowStatus === 'FAILED' => 'FAILED',
                    in_array($state, ['HUMAN_DECISION_REQUIRED','BLOCKED','ESCALATED'], true) => 'WAITING',
                    default => 'RUNNING',
                };
            }
            $result[] = ['key' => $key, 'label' => $order[$key], 'status' => $status];
        }
        return $result;
    }

    private function durationSeconds(?string $startedAt, ?string $finishedAt): ?int
    {
        if ($startedAt === null || trim($startedAt) === '') return null;
        try {
            $start = new \DateTimeImmutable($startedAt);
            $end = ($finishedAt !== null && trim($finishedAt) !== '')
                ? new \DateTimeImmutable($finishedAt)
                : new \DateTimeImmutable();
            return max(0, $end->getTimestamp() - $start->getTimestamp());
        } catch (\Throwable) {
            return null;
        }
    }

    private function durationLabel(?int $seconds): string
    {
        if ($seconds === null) return '—';
        if ($seconds < 60) return $seconds.'с';
        if ($seconds < 3600) return intdiv($seconds, 60).'хв '.($seconds % 60).'с';
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        if ($hours < 24) return $hours.'г '.str_pad((string) $minutes, 2, '0', STR_PAD_LEFT).'хв';
        $days = intdiv($hours, 24);
        return $days.'д '.($hours % 24).'г';
    }

    private function runtimeHealth(string $status, string $state, ?string $lastActivityAt): string
    {
        if (in_array($status, ['COMPLETED','CANCELLED','FAILED'], true) || in_array($state, ['DONE','CANCELLED','FAILED'], true)) {
            return 'TERMINAL';
        }
        if (in_array($state, ['HUMAN_DECISION_REQUIRED','BLOCKED','ESCALATED','READY_FOR_HUMAN_APPROVAL'], true)) {
            return 'WAITING';
        }
        if ($lastActivityAt === null || trim($lastActivityAt) === '') return 'UNKNOWN';

        try {
            $last = new \DateTimeImmutable($lastActivityAt);
            $age = time() - $last->getTimestamp();
            if ($age >= 1800) return 'STALLED';
            if ($age >= 600) return 'STALE';
            return 'HEALTHY';
        } catch (\Throwable) {
            return 'UNKNOWN';
        }
    }

}
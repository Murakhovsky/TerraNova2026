<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Engineering\Application\DTO\EngineeringRequest;
use App\Engineering\Application\Audit\EngineeringAuditQueryInterface;
use App\Engineering\Application\Metrics\EngineeringMetricsProviderInterface;
use App\Engineering\Application\Service\EngineeringCancelService;
use App\Engineering\Application\Service\EngineeringContinueService;
use App\Engineering\Application\Service\EngineeringHumanDecisionService;
use App\Engineering\Application\Service\EngineeringOrchestrator;
use App\Engineering\Application\Service\EngineeringStatusService;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Security\SessionCsrfValidator;
use Kernel\Observability\CorrelationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

final readonly class EngineeringController
{
    public function __construct(
        private EngineeringOrchestrator $engineering,
        private EngineeringContinueService $continue,
        private EngineeringCancelService $cancel,
        private EngineeringHumanDecisionService $decisions,
        private EngineeringStatusService $status,
        private EngineeringMetricsProviderInterface $metrics,
        private EngineeringAuditQueryInterface $audit,
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
    ) {}

    public function create(Request $request): JsonResponse
    {
        if (($denied = $this->authorize($request, true)) !== null) return $denied;
        $tenant = $this->tenants->current();
        $input = $this->input($request);
        $description = trim((string) ($input['description'] ?? ''));
        if ($description === '') return $this->error('description_required', 'Engineering request description is required.', 422);

        $priority = strtoupper(trim((string) ($input['priority'] ?? 'P2')));
        if (!in_array($priority, ['P0','P1','P2','P3'], true)) {
            return $this->error('invalid_priority', 'Priority must be P0, P1, P2 or P3.', 422);
        }

        try {
            $featureId = $this->engineering->create(
                new EngineeringRequest(
                    requestId: EngineeringId::generate(),
                    description: $description,
                    title: isset($input['title']) && trim((string) $input['title']) !== '' ? trim((string) $input['title']) : null,
                    sourceType: 'api',
                    sourceReference: null,
                    priority: $priority,
                    metadata: is_array($input['metadata'] ?? null) ? $input['metadata'] : [],
                    constraints: is_array($input['constraints'] ?? null) ? $input['constraints'] : [],
                    attachments: is_array($input['attachments'] ?? null) ? $input['attachments'] : [],
                    previousContext: [],
                ),
                $tenant->organizationId()->value(),
                'user:'.$tenant->userId()->value(),
            );

            return new JsonResponse(['ok' => true, 'data' => $this->status->status($featureId)], 201);
        } catch (Throwable $e) {
            return $this->exception($e);
        }
    }

    public function get(Request $request, string $id): JsonResponse
    {
        if (($denied = $this->authorize($request, false)) !== null) return $denied;
        try {
            $featureId = EngineeringId::assert($id);
            $this->assertTenantFeature($featureId);
            return new JsonResponse(['ok' => true, 'data' => $this->status->status($featureId)]);
        } catch (Throwable $e) {
            return $this->exception($e);
        }
    }

    public function live(Request $request, string $id): JsonResponse
    {
        if (($denied = $this->authorize($request, false)) !== null) return $denied;

        try {
            $featureId = EngineeringId::assert($id);
            $status = $this->status->status($featureId);
            $tenant = $this->tenants->current();
            if (($status['feature']['organization_id'] ?? null) !== $tenant?->organizationId()->value()) {
                throw new \RuntimeException('Engineering feature does not belong to the current organization.');
            }

            return new JsonResponse([
                'ok' => true,
                'data' => [
                    'server_time' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    'workflow' => $status['workflow'] ?? null,
                    'tasks' => $status['tasks'] ?? [],
                    'agent_runs' => $status['agent_runs'] ?? [],
                    'timeline_count' => count(is_array($status['timeline'] ?? null) ? $status['timeline'] : []),
                    'timeline' => array_slice(is_array($status['timeline'] ?? null) ? $status['timeline'] : [], 0, 150),
                    'execution_events' => array_slice(is_array($status['execution_events'] ?? null) ? $status['execution_events'] : [], 0, 150),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->exception($e);
        }
    }

    public function run(Request $request, string $id): JsonResponse
    {
        if (($denied = $this->authorize($request, true)) !== null) return $denied;
        $featureId = EngineeringId::assert($id);
        $this->assertTenantFeature($featureId);
        $tenant = $this->tenants->current();
        $correlationId = $this->correlationId($request, 'run', $featureId);

        try {
            $status = $this->status->status($featureId);
            $result = ($status['workflow'] ?? null) === null
                ? $this->engineering->start($featureId, $tenant->organizationId()->value(), $correlationId)
                : $this->continue->continueFeature($featureId, $tenant->organizationId()->value(), $correlationId);

            return new JsonResponse(['ok' => true, 'data' => [
                'feature_id' => $result->featureId,
                'workflow_id' => $result->workflowId,
                'state' => $result->state,
                'next' => $result->next->type->value,
                'agent' => $result->next->agent?->value,
                'reason' => $result->next->reason,
            ]], 202);
        } catch (Throwable $e) {
            return $this->exception($e);
        }
    }

    public function resume(Request $request, string $id): JsonResponse
    {
        if (($denied = $this->authorize($request, true)) !== null) return $denied;
        $featureId = EngineeringId::assert($id);
        $this->assertTenantFeature($featureId);
        $tenant = $this->tenants->current();

        try {
            $result = $this->continue->continueFeature(
                $featureId,
                $tenant->organizationId()->value(),
                $this->correlationId($request, 'resume', $featureId),
            );
            return new JsonResponse(['ok' => true, 'data' => [
                'feature_id' => $result->featureId,
                'workflow_id' => $result->workflowId,
                'state' => $result->state,
                'next' => $result->next->type->value,
                'agent' => $result->next->agent?->value,
                'reason' => $result->next->reason,
            ]], 202);
        } catch (Throwable $e) {
            return $this->exception($e);
        }
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        if (($denied = $this->authorize($request, true)) !== null) return $denied;
        $tenant = $this->tenants->current();
        $input = $this->input($request);
        $featureId = EngineeringId::assert($id);
        $this->assertTenantFeature($featureId);

        try {
            return new JsonResponse(['ok' => true, 'data' => $this->cancel->cancel(
                $featureId,
                'user:'.$tenant->userId()->value(),
                trim((string) ($input['reason'] ?? 'Cancelled through Engineering API.')),
            )]);
        } catch (Throwable $e) {
            return $this->exception($e);
        }
    }

    public function runs(Request $request, string $id): JsonResponse
    {
        if (($denied = $this->authorize($request, false)) !== null) return $denied;
        try {
            $featureId = EngineeringId::assert($id);
            $this->assertTenantFeature($featureId);
            $status = $this->status->status($featureId);
            return new JsonResponse(['ok' => true, 'data' => $status['agent_runs'] ?? []]);
        } catch (Throwable $e) {
            return $this->exception($e);
        }
    }

    public function artifacts(Request $request, string $id): JsonResponse
    {
        if (($denied = $this->authorize($request, false)) !== null) return $denied;
        try {
            $status = $this->status->status(EngineeringId::assert($id));
            return new JsonResponse(['ok' => true, 'data' => $status['artifacts'] ?? []]);
        } catch (Throwable $e) {
            return $this->exception($e);
        }
    }

    public function metrics(Request $request): JsonResponse
    {
        if (($denied = $this->authorize($request, false)) !== null) return $denied;
        try {
            $tenant = $this->tenants->current();
            return new JsonResponse(['ok' => true, 'data' => $this->metrics->summary($tenant->organizationId()->value())]);
        } catch (Throwable $e) {
            return $this->exception($e);
        }
    }

    public function audit(Request $request, string $id): JsonResponse
    {
        if (($denied = $this->authorize($request, false)) !== null) return $denied;
        try {
            $featureId = EngineeringId::assert($id);
            $this->assertTenantFeature($featureId);
            $this->status->status($featureId);
            return new JsonResponse(['ok' => true, 'data' => $this->audit->forFeature($featureId)]);
        } catch (Throwable $e) {
            return $this->exception($e);
        }
    }

    public function humanDecision(Request $request, string $id): JsonResponse
    {
        if (($denied = $this->authorize($request, true)) !== null) return $denied;
        $featureId = EngineeringId::assert($id);
        $this->assertTenantFeature($featureId);
        $input = $this->input($request);
        $requestId = trim((string) ($input['request_id'] ?? ''));
        $option = trim((string) ($input['option'] ?? ''));
        if ($requestId === '' || $option === '') {
            return $this->error('decision_required', 'request_id and option are required.', 422);
        }

        try {
            $status = $this->status->status($featureId);
            $known = false;
            foreach ($status['open_human_decisions'] ?? [] as $decision) {
                if (($decision['id'] ?? null) === $requestId) { $known = true; break; }
            }
            if (!$known) return $this->error('decision_not_open', 'Decision request is not open for this feature.', 409);

            $tenant = $this->tenants->current();
            $result = $this->decisions->answerAndResume(
                requestId: EngineeringId::assert($requestId),
                selectedOption: $option,
                comment: isset($input['comment']) ? (string) $input['comment'] : null,
                decidedBy: 'user:'.$tenant->userId()->value(),
                organizationId: $tenant->organizationId()->value(),
                correlationId: $this->correlationId($request, 'decision', $featureId),
            );

            return new JsonResponse(['ok' => true, 'data' => [
                'feature_id' => $result->featureId,
                'workflow_id' => $result->workflowId,
                'decision_id' => $result->decisionId,
                'state' => $result->state,
                'next' => $result->next->type->value,
                'agent' => $result->next->agent?->value,
            ]], 202);
        } catch (Throwable $e) {
            return $this->exception($e);
        }
    }

    private function assertTenantFeature(string $featureId): void
    {
        $tenant = $this->tenants->current();
        $feature = $this->status->status($featureId)['feature'] ?? [];
        if (($feature['organization_id'] ?? null) !== $tenant?->organizationId()->value()) {
            throw new \RuntimeException('Engineering feature does not belong to the current organization.');
        }
    }

    private function authorize(Request $request, bool $mutation): ?JsonResponse
    {
        $tenant = $this->tenants->current();
        if ($tenant === null || !$tenant->isManager() || !$tenant->allows(TenantPermissions::MANAGE)) {
            return $this->error('manager_required', 'Manager authorization required.', 403);
        }
        if ($mutation && !$this->csrf->isValid($request)) {
            return $this->error('invalid_csrf_token', 'Invalid CSRF token.', 400);
        }
        return null;
    }

    private function input(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);
        return is_array($decoded) && !array_is_list($decoded) ? $decoded : $request->request->all();
    }

    private function correlationId(Request $request, string $action, string $featureId): string
    {
        $existing = $request->attributes->get('_cos_correlation_id');
        if ($existing instanceof CorrelationId) return $existing->value();
        return 'engineering:'.$action.':'.$featureId.':'.EngineeringId::generate();
    }

    private function exception(Throwable $e): JsonResponse
    {
        $message = $e->getMessage();
        $lower = strtolower($message);
        $status = str_contains($lower, 'not found') || str_contains($lower, 'no active workflow') ? 404
            : (str_contains($lower, 'already') || str_contains($lower, 'not open') || str_contains($lower, 'waiting') || str_contains($lower, 'cannot') ? 409 : 422);
        return $this->error('engineering_request_failed', $message, $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $code, 'message' => $message], $status);
    }
}

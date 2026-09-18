<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Application\Sales\Command\AddSalesOpportunityActivityCommand;
use App\Application\Sales\Command\ChangeSalesOpportunityStageCommand;
use App\Application\Sales\Command\ConvertSalesLeadToOpportunityCommand;
use App\Application\Sales\Command\CreateSalesLeadCommand;
use App\Application\Sales\Command\ScheduleSalesNextActionCommand;
use App\Application\Sales\Command\UpdateSalesLeadCommand;
use App\Security\LegacySessionCsrfValidator;
use DateTimeImmutable;
use Domains\Sales\Application\DTO\ChangeDealStageResult;
use Domains\Sales\Application\DTO\ClientCaseCommandResult;
use Domains\Sales\Application\DTO\OperationResult;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Observability\CorrelationId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

final readonly class SalesWriteController
{
    public function __construct(
        private CommandBusInterface $commands,
        private TenantContextProviderInterface $tenants,
        private LegacySessionCsrfValidator $csrf,
    ) {
    }

    public function createLead(Request $request): JsonResponse
    {
        $context = $this->context($request);
        if ($context instanceof JsonResponse) return $context;

        $idempotencyKey = trim((string) $request->headers->get('X-Idempotency-Key', ''));
        if ($idempotencyKey === '') {
            return $this->error(422, 'idempotency_key_required', 'X-Idempotency-Key is required.');
        }

        /** @var ClientCaseCommandResult $result */
        $result = $this->commands->dispatch(new CreateSalesLeadCommand(
            $context['organization_id'],
            $context['actor_id'],
            $context['correlation_id'],
            $idempotencyKey,
            $this->input($request),
        ));

        return $this->clientCaseResult($result, $result->code === 'created' ? 201 : 200);
    }

    public function updateLead(Request $request, string $id): JsonResponse
    {
        $context = $this->context($request);
        if ($context instanceof JsonResponse) return $context;

        /** @var ClientCaseCommandResult $result */
        $result = $this->commands->dispatch(new UpdateSalesLeadCommand(
            $context['organization_id'],
            $context['actor_id'],
            (int) $id,
            $this->input($request),
        ));

        return $this->clientCaseResult($result, 200);
    }

    public function convertLead(Request $request, string $id): JsonResponse
    {
        $context = $this->context($request);
        if ($context instanceof JsonResponse) return $context;

        /** @var ClientCaseCommandResult $result */
        $result = $this->commands->dispatch(new ConvertSalesLeadToOpportunityCommand(
            $context['organization_id'],
            $context['actor_id'],
            (int) $id,
            $this->input($request),
        ));

        return $this->clientCaseResult($result, $result->code === 'created' ? 201 : 200);
    }

    public function addActivity(Request $request, string $id): JsonResponse
    {
        $context = $this->context($request);
        if ($context instanceof JsonResponse) return $context;

        /** @var ClientCaseCommandResult $result */
        $result = $this->commands->dispatch(new AddSalesOpportunityActivityCommand(
            $context['organization_id'],
            $context['actor_id'],
            (int) $id,
            $this->input($request),
        ));

        return $this->clientCaseResult($result, 201);
    }

    public function changeStage(Request $request, string $id): JsonResponse
    {
        $context = $this->context($request);
        if ($context instanceof JsonResponse) return $context;

        $input = $this->input($request);
        $stageId = trim((string) ($input['stage_id'] ?? ''));
        if ($stageId === '') {
            return $this->error(422, 'stage_id_required', 'stage_id is required.');
        }

        /** @var ChangeDealStageResult $result */
        $result = $this->commands->dispatch(new ChangeSalesOpportunityStageCommand(
            $context['organization_id'],
            $context['actor_id'],
            (int) $id,
            $stageId,
            $context['correlation_id'],
            ($reason = trim((string) ($input['lost_reason_id'] ?? ''))) !== '' ? $reason : null,
            ($note = trim((string) ($input['lost_reason_note'] ?? ''))) !== '' ? $note : null,
        ));

        if (!$result->successful) {
            $reason = $result->reason ?? 'stage_change_failed';
            if ($reason === 'concurrent_stage_change') {
                return $this->error(409, 'concurrent_stage_change', 'Opportunity stage changed concurrently. Reload and retry.');
            }
            if (str_contains(strtolower($reason), 'deal was not found')) {
                return $this->error(404, 'not_found', 'Opportunity not found.');
            }
            return $this->error(422, 'invalid_stage_transition', $reason);
        }

        return $this->ok([
            'changed' => $result->changed,
            'previous_stage_id' => $result->previousStageId,
            'stage_id' => $result->stageId,
            'requires_approval' => $result->requiresApproval,
        ]);
    }

    public function setNextAction(Request $request, string $id): JsonResponse
    {
        $context = $this->context($request);
        if ($context instanceof JsonResponse) return $context;

        $idempotencyKey = trim((string) $request->headers->get('X-Idempotency-Key', ''));
        if ($idempotencyKey === '') {
            return $this->error(422, 'idempotency_key_required', 'X-Idempotency-Key is required.');
        }

        $input = $this->input($request);
        $rawDueAt = trim((string) ($input['due_at'] ?? ''));
        if ($rawDueAt === '') {
            return $this->error(422, 'due_at_required', 'due_at is required.');
        }
        try {
            $dueAt = new DateTimeImmutable($rawDueAt);
        } catch (Throwable) {
            return $this->error(422, 'invalid_due_at', 'due_at must be a valid date-time.');
        }

        /** @var OperationResult $result */
        $result = $this->commands->dispatch(new ScheduleSalesNextActionCommand(
            $context['organization_id'],
            $context['actor_id'],
            (int) $id,
            trim((string) ($input['title'] ?? 'Follow-up')),
            ($body = trim((string) ($input['body'] ?? ''))) !== '' ? $body : null,
            $dueAt,
            $context['correlation_id'],
            $idempotencyKey,
        ));

        if (!$result->successful) {
            $error = $result->error ?? 'next_action_failed';
            if (str_contains(strtolower($error), 'deal was not found')) {
                return $this->error(404, 'not_found', 'Opportunity not found.');
            }
            return $this->error(422, $this->stableCode($error, 'next_action_failed'), $error);
        }

        return $this->ok([
            'activity_id' => $result->externalId,
            ...$result->data,
        ], ($result->data['duplicate'] ?? false) === true ? 200 : 201);
    }

    /** @return array{organization_id:\Kernel\Shared\Domain\OrganizationId,actor_id:int,correlation_id:string}|JsonResponse */
    private function context(Request $request): array|JsonResponse
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return $this->error(403, 'tenant_context_required', 'Tenant context required.');
        }
        if (!$this->csrf->isValid($request)) {
            return $this->error(400, 'invalid_csrf_token', 'Invalid CSRF token.');
        }

        $actor = $tenant->userId()->value();
        if (!ctype_digit($actor) || (int) $actor <= 0) {
            return $this->error(403, 'invalid_actor', 'Authenticated actor is invalid.');
        }

        $correlation = $request->attributes->get('_cos_correlation_id');
        $correlationId = $correlation instanceof CorrelationId
            ? $correlation->value()
            : CorrelationId::generate()->value();

        return [
            'organization_id' => $tenant->organizationId(),
            'actor_id' => (int) $actor,
            'correlation_id' => $correlationId,
        ];
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $contentType = strtolower((string) $request->headers->get('Content-Type', ''));
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode((string) $request->getContent(), true);
            return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
        }

        return $request->request->all();
    }

    private function clientCaseResult(ClientCaseCommandResult $result, int $successStatus): JsonResponse
    {
        if ($result->ok) {
            return $this->ok(['code' => $result->code, ...$result->data], $successStatus);
        }

        $status = match ($result->code) {
            'not_found', 'request_not_found', 'case_not_found' => 404,
            'idempotency_conflict' => 409,
            default => 422,
        };

        return $this->error($status, $result->code, $this->messageFor($result->code));
    }

    private function ok(mixed $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['ok' => true, 'data' => $data], $status);
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse([
            'ok' => false,
            'error' => $code,
            'message' => $message,
        ], $status);
    }

    private function messageFor(string $code): string
    {
        return match ($code) {
            'contact_required' => 'Lead requires a name and phone or email.',
            'invalid_email' => 'Email address is invalid.',
            'invalid_owner' => 'Owner is not an active manager in this organization.',
            'invalid_status' => 'Lead status is invalid.',
            'invalid_next_contact_at' => 'next_contact_at must be a valid date-time.',
            'no_changes' => 'No supported Lead changes were supplied.',
            'request_not_found', 'not_found', 'case_not_found' => 'Sales resource was not found.',
            'activity_title_required' => 'Activity title is required.',
            'invalid_activity_type' => 'Activity type is invalid.',
            'idempotency_key_required' => 'X-Idempotency-Key is required.',
            'idempotency_conflict' => 'The idempotent operation is already in progress.',
            default => str_replace('_', ' ', $code),
        };
    }

    private function stableCode(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        return trim($value, '_') !== '' ? trim($value, '_') : $fallback;
    }
}

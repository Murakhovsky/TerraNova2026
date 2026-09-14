<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use DateTimeImmutable;
use DomainException;
use Domains\Sales\Application\DTO\SendMessageCommand;
use Interfaces\Web\Controller\WebController;
use Kernel\Action\ActionStatus;
use Kernel\Action\Service\ActionService;
use Kernel\Approval\Service\ApprovalService;
use Kernel\Queue\Contract\JobQueueInterface;
use Kernel\Queue\Handler\ActionExecutionJobHandler;
use Phalcon\Http\Response;
use Throwable;

/**
 * Sales-facing mutation facade for EPIC 2 operational actions.
 *
 * Business semantics stay in Sales services while approvals, action state and
 * execution queue remain Kernel-owned. The controller only authorizes, validates
 * transport input and delegates to those canonical mechanisms.
 */
final class SalesWorkspaceActionsController extends WebController
{
    public function messageAction(?string $id = null): Response
    {
        return $this->dealMutation($id, function (int $dealId, array $input, array $user): Response {
            $body = trim((string) ($input['body'] ?? ''));
            $channel = strtoupper(trim((string) ($input['channel'] ?? 'WEB')));
            if ($body === '' || !in_array($channel, ['WEB', 'EMAIL', 'TELEGRAM', 'WHATSAPP', 'VIBER'], true)) {
                return $this->json(422, ['ok' => false, 'error' => 'Valid channel and non-empty body are required.']);
            }
            $key = 'sales-ui:' . $user['id'] . ':' . bin2hex(random_bytes(16));
            $result = $this->di->getShared('salesOperationService')->sendMessage(
                new SendMessageCommand($this->organization()->id(), (string) $dealId, $channel, mb_substr($body, 0, 8000), $key),
                ['purpose' => 'manager_message'],
                'USER',
                (string) $user['id'],
            );
            return $result->successful
                ? $this->json(201, ['ok' => true, 'data' => ['external_id' => $result->externalId, ...$result->data]])
                : $this->json(422, ['ok' => false, 'error' => $result->error ?? 'Message delivery failed.']);
        });
    }

    public function completeActivityAction(?string $id = null, ?string $activityId = null): Response
    {
        return $this->activityMutation($id, $activityId, function (int $dealId, int $activity, array $user): Response {
            $result = $this->di->getShared('salesOperationService')->completeActivity(
                $this->organization()->id(), (string) $dealId, $activity, (int) $user['id'], 'USER'
            );
            return $result->successful
                ? $this->json(200, ['ok' => true, 'data' => $result->data])
                : $this->json(404, ['ok' => false, 'error' => $result->error ?? 'Activity was not found.']);
        });
    }

    public function rescheduleActivityAction(?string $id = null, ?string $activityId = null): Response
    {
        return $this->activityMutation($id, $activityId, function (int $dealId, int $activity, array $user, array $input): Response {
            try {
                $dueAt = new DateTimeImmutable(trim((string) ($input['due_at'] ?? '')));
            } catch (Throwable) {
                return $this->json(422, ['ok' => false, 'error' => 'Valid due_at is required.']);
            }
            $result = $this->di->getShared('salesOperationService')->rescheduleActivity(
                $this->organization()->id(), (string) $dealId, $activity, $dueAt
            );
            return $result->successful
                ? $this->json(200, ['ok' => true, 'data' => $result->data])
                : $this->json(422, ['ok' => false, 'error' => $result->error ?? 'Activity rescheduling failed.']);
        });
    }

    public function leadStatusAction(?string $id = null): Response
    {
        return $this->leadMutation($id, function (int $leadId, array $input, array $user): Response {
            $status = strtolower(trim((string) ($input['status'] ?? '')));
            if (!in_array($status, ['new', 'contacted', 'qualified', 'disqualified', 'lost'], true)) {
                return $this->json(422, ['ok' => false, 'error' => 'Unsupported lead status.']);
            }
            $result = $this->di->getShared('salesInboundService')->updateRequest($leadId, [
                'status' => $status,
                'activity_type' => 'status_change',
                'activity_title' => 'Lead status updated from Sales Workspace',
                'activity_body' => trim((string) ($input['note'] ?? '')),
            ], $user);
            return $result->ok
                ? $this->json(200, ['ok' => true, 'data' => ['code' => $result->code, ...$result->data]])
                : $this->json(422, ['ok' => false, 'error' => $result->code]);
        });
    }

    public function leadOwnerAction(?string $id = null): Response
    {
        return $this->leadMutation($id, function (int $leadId, array $input, array $user): Response {
            $ownerId = (int) ($input['owner_id'] ?? 0);
            if ($ownerId <= 0) return $this->json(422, ['ok' => false, 'error' => 'A valid owner_id is required.']);
            $result = $this->di->getShared('salesInboundService')->updateRequest($leadId, [
                'assigned_user_id' => $ownerId,
                'activity_type' => 'status_change',
                'activity_title' => 'Lead owner updated from Sales Workspace',
            ], $user);
            return $result->ok
                ? $this->json(200, ['ok' => true, 'data' => ['code' => $result->code, ...$result->data]])
                : $this->json(422, ['ok' => false, 'error' => $result->code]);
        });
    }

    public function leadDealAction(?string $id = null): Response
    {
        return $this->leadMutation($id, function (int $leadId, array $input, array $user): Response {
            $result = $this->di->getShared('salesInboundService')->createCaseFromRequest($leadId, [
                'assigned_user_id' => (int) ($input['owner_id'] ?? $user['id']),
                'priority' => strtolower(trim((string) ($input['priority'] ?? 'normal'))),
            ], $user);
            return $result->ok
                ? $this->json($result->code === 'created' ? 201 : 200, ['ok' => true, 'data' => ['code' => $result->code, ...$result->data]])
                : $this->json(422, ['ok' => false, 'error' => $result->code]);
        });
    }

    public function leadFollowupAction(?string $id = null): Response
    {
        return $this->leadMutation($id, function (int $leadId, array $input, array $user): Response {
            $due = trim((string) ($input['due_at'] ?? ''));
            try { $dueAt = new DateTimeImmutable($due); } catch (Throwable) { return $this->json(422, ['ok' => false, 'error' => 'Valid due_at is required.']); }
            if ($dueAt <= new DateTimeImmutable()) return $this->json(422, ['ok' => false, 'error' => 'Follow-up must be scheduled in the future.']);
            $result = $this->di->getShared('salesInboundService')->updateRequest($leadId, [
                'next_contact_at' => $dueAt->format('Y-m-d H:i:s'),
                'activity_type' => 'followup',
                'activity_title' => mb_substr(trim((string) ($input['title'] ?? 'Lead follow-up')) ?: 'Lead follow-up', 0, 180),
                'activity_body' => mb_substr(trim((string) ($input['body'] ?? '')), 0, 4000),
            ], $user);
            return $result->ok
                ? $this->json(201, ['ok' => true, 'data' => ['code' => $result->code, 'due_at' => $dueAt->format(DATE_ATOM), ...$result->data]])
                : $this->json(422, ['ok' => false, 'error' => $result->code]);
        });
    }

    public function approveAction(?string $id = null): Response { return $this->approvalDecision($id, true); }
    public function rejectAction(?string $id = null): Response { return $this->approvalDecision($id, false); }

    public function executeAction(?string $id = null): Response
    {
        $actionId = $this->resourceId($id);
        $user = $this->managerMutation($actionId);
        if ($user instanceof Response) return $user;
        try {
            /** @var ActionService $actions */
            $actions = $this->di->getShared('cosActionService');
            $action = $actions->find($this->organization()->id(), $actionId);
            if ($action === null) return $this->json(404, ['ok' => false, 'error' => 'Action not found.']);
            if ($action->status === ActionStatus::PendingApproval) return $this->json(409, ['ok' => false, 'error' => 'Action requires approval first.']);
            if (in_array($action->status, [ActionStatus::Completed, ActionStatus::Rejected, ActionStatus::Running], true)) {
                return $this->json(409, ['ok' => false, 'error' => 'Action cannot be executed from its current status.']);
            }
            if (in_array($action->status, [ActionStatus::Proposed, ActionStatus::Failed], true)) $actions->queue($this->organization()->id(), $actionId);
            $action = $actions->find($this->organization()->id(), $actionId);
            /** @var JobQueueInterface $queue */
            $queue = $this->di->getShared('cosJobQueue');
            $jobId = $queue->enqueue(
                $this->organization()->id(), ActionExecutionJobHandler::TYPE, ['action_id' => $actionId],
                $action?->correlationId ?: $actionId, 'action-execution:' . $actionId, 5, 120
            );
            return $this->json(202, ['ok' => true, 'data' => ['status' => 'QUEUED', 'job_id' => $jobId]]);
        } catch (DomainException $error) {
            return $this->json(409, ['ok' => false, 'error' => $error->getMessage()]);
        } catch (Throwable) {
            return $this->json(500, ['ok' => false, 'error' => 'Action execution could not be queued.']);
        }
    }

    public function dismissAction(?string $id = null): Response
    {
        $actionId = $this->resourceId($id);
        $user = $this->managerMutation($actionId);
        if ($user instanceof Response) return $user;
        try {
            /** @var ActionService $actions */
            $actions = $this->di->getShared('cosActionService');
            $action = $actions->find($this->organization()->id(), $actionId);
            if ($action === null) return $this->json(404, ['ok' => false, 'error' => 'Action not found.']);

            // Pending approvals must be rejected through ApprovalService so the approval
            // decision and Action rejection stay atomic, tenant-safe and Kernel-audited.
            if ($action->status === ActionStatus::PendingApproval) {
                return $this->json(409, ['ok' => false, 'error' => 'Pending approval must be rejected through its approval decision.']);
            }
            if ($action->status !== ActionStatus::Proposed) {
                return $this->json(409, ['ok' => false, 'error' => 'Only a proposed action can be dismissed directly.']);
            }
            $actions->reject($this->organization()->id(), $actionId);
            return $this->json(200, ['ok' => true, 'data' => ['status' => 'REJECTED']]);
        } catch (DomainException $error) {
            return $this->json(409, ['ok' => false, 'error' => $error->getMessage()]);
        }
    }

    private function approvalDecision(?string $id, bool $approved): Response
    {
        $approvalId = $this->resourceId($id);
        $user = $this->managerMutation($approvalId);
        if ($user instanceof Response) return $user;
        try {
            $approval = $this->di->getShared('cosApprovalRepository')->findPending($this->organization()->id(), $approvalId);
            if ($approval === null) return $this->json(404, ['ok' => false, 'error' => 'Pending approval not found.']);
            if (strtoupper($approval->approverType) === 'USER' && $approval->approverId !== '' && $approval->approverId !== (string) $user['id']) {
                return $this->json(403, ['ok' => false, 'error' => 'Approval belongs to another user.']);
            }
            $note = trim((string) ($this->input()['note'] ?? '')) ?: null;
            /** @var ApprovalService $service */
            $service = $this->di->getShared('cosApprovalService');
            $approved
                ? $service->approve($this->organization()->id(), $approvalId, (string) $user['id'], $note)
                : $service->reject($this->organization()->id(), $approvalId, (string) $user['id'], $note);
            return $this->json(200, ['ok' => true, 'data' => ['status' => $approved ? 'APPROVED' : 'REJECTED']]);
        } catch (DomainException $error) {
            return $this->json(409, ['ok' => false, 'error' => $error->getMessage()]);
        }
    }

    private function dealMutation(?string $id, callable $handler): Response
    {
        $dealId = (string) ($id ?: $this->dispatcher->getParam('id'));
        $user = $this->managerMutation(ctype_digit($dealId) ? $dealId : '');
        return $user instanceof Response ? $user : $handler((int) $dealId, $this->input(), $user);
    }

    private function activityMutation(?string $id, ?string $activityId, callable $handler): Response
    {
        $dealId = (string) ($id ?: $this->dispatcher->getParam('id'));
        $activity = (string) ($activityId ?: $this->dispatcher->getParam('activityId'));
        $user = $this->managerMutation(ctype_digit($dealId) && ctype_digit($activity) ? $dealId : '');
        return $user instanceof Response ? $user : $handler((int) $dealId, (int) $activity, $user, $this->input());
    }

    private function leadMutation(?string $id, callable $handler): Response
    {
        $leadId = (string) ($id ?: $this->dispatcher->getParam('id'));
        $user = $this->managerMutation(ctype_digit($leadId) ? $leadId : '');
        return $user instanceof Response ? $user : $handler((int) $leadId, $this->input(), $user);
    }

    private function managerMutation(string $resourceId): array|Response
    {
        $user = $this->auth()->currentUser();
        if ($user === null || !$this->auth()->isManager($user)) return $this->json(403, ['ok' => false, 'error' => 'Manager authorization required.']);
        if ($resourceId === '' || !$this->validMutation()) return $this->json(400, ['ok' => false, 'error' => 'Invalid resource id or CSRF token.']);
        return $user;
    }

    private function resourceId(?string $id): string
    {
        $value = (string) ($id ?: $this->dispatcher->getParam('id'));
        return preg_match('/^[a-f0-9]{32}$/', $value) ? $value : '';
    }

    private function input(): array
    {
        $json = $this->request->getJsonRawBody(true);
        return is_array($json) ? $json : (array) $this->request->getPost();
    }

    private function json(int $status, array $payload): Response
    {
        $this->view->disable();
        $this->response->setStatusCode($status);
        $this->response->setContentType('application/json', 'UTF-8');
        return $this->response->setJsonContent($payload);
    }
}

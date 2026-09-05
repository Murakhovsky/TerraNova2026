<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use DomainException;
use Interfaces\Web\Controller\WebController;
use Kernel\Action\ActionStatus;
use Kernel\Action\Service\ActionService;
use Kernel\Approval\Service\ApprovalService;
use Kernel\Operations\Contract\OperationsReadModelInterface;
use Kernel\Queue\Contract\JobQueueInterface;
use Kernel\Queue\Handler\ActionExecutionJobHandler;
use Phalcon\Http\Response;
use Throwable;

final class CosRuntimeController extends WebController
{
    public function actionsAction(): Response { return $this->section('actions'); }
    public function approvalsAction(): Response { return $this->section('approvals'); }
    public function agentsAction(): Response { return $this->section('agent_runs'); }
    public function rulesAction(): Response { return $this->section('rules'); }
    public function eventsAction(): Response { return $this->section('events'); }
    public function auditAction(): Response { return $this->section('audit'); }

    public function actionAction(?string $id = null): Response
    {
        $id = (string) ($id ?: $this->dispatcher->getParam('id'));
        return $this->section('actions', $id);
    }

    public function executeAction(?string $id = null): Response
    {
        $user = $this->manager();
        if ($user instanceof Response) return $user;
        $actionId = (string) ($id ?: $this->dispatcher->getParam('id'));
        if (!$this->validMutation() || !preg_match('/^[a-f0-9]{32}$/', $actionId)) return $this->json(400, ['ok' => false, 'error' => 'Invalid request or CSRF token.']);
        try {
            /** @var ActionService $actions */
            $actions = $this->di->getShared('cosActionService');
            $action = $actions->find($this->organization()->id(), $actionId);
            if ($action === null || $action->status !== ActionStatus::Queued) throw new DomainException('Action is not queued.');
            /** @var JobQueueInterface $queue */
            $queue = $this->di->getShared('cosJobQueue');
            $jobId = $queue->enqueue($this->organization()->id(), ActionExecutionJobHandler::TYPE, ['action_id' => $actionId], $action->correlationId, 'action-execution:' . $actionId, 5, 120);
            return $this->json(202, ['ok' => true, 'status' => 'QUEUED', 'job_id' => $jobId]);
        } catch (DomainException $error) {
            return $this->json(409, ['ok' => false, 'error' => $error->getMessage()]);
        } catch (Throwable) {
            return $this->json(500, ['ok' => false, 'error' => 'Action execution could not be queued.']);
        }
    }

    public function approveAction(?string $id = null): Response { return $this->decide($id, true); }
    public function rejectAction(?string $id = null): Response { return $this->decide($id, false); }

    private function decide(?string $id, bool $approved): Response
    {
        $user = $this->manager();
        if ($user instanceof Response) return $user;
        $approvalId = (string) ($id ?: $this->dispatcher->getParam('id'));
        if (!$this->validMutation() || !preg_match('/^[a-f0-9]{32}$/', $approvalId)) return $this->json(400, ['ok' => false, 'error' => 'Invalid request or CSRF token.']);
        try {
            /** @var ApprovalService $service */
            $service = $this->di->getShared('cosApprovalService');
            $note = trim((string) $this->request->getPost('note', 'string', '')) ?: null;
            $approved
                ? $service->approve($this->organization()->id(), $approvalId, (string) $user['id'], $note)
                : $service->reject($this->organization()->id(), $approvalId, (string) $user['id'], $note);
            return $this->json(200, ['ok' => true, 'status' => $approved ? 'APPROVED' : 'REJECTED']);
        } catch (DomainException $error) {
            return $this->json(409, ['ok' => false, 'error' => $error->getMessage()]);
        }
    }

    private function section(string $section, ?string $id = null): Response
    {
        $user = $this->manager();
        if ($user instanceof Response) return $user;
        try {
            /** @var OperationsReadModelInterface $query */
            $query = $this->di->getShared('cosOperationsReadModel');
            $overview = $query->overview($this->organization()->id(), 100);
            $items = $overview[$section] ?? [];
            if ($id !== null) {
                foreach ($items as $item) if (($item['id'] ?? null) === $id) return $this->json(200, ['ok' => true, 'data' => $item]);
                return $this->json(404, ['ok' => false, 'error' => 'Resource not found.']);
            }
            return $this->json(200, ['ok' => true, 'data' => $items]);
        } catch (Throwable) {
            return $this->json(500, ['ok' => false, 'error' => 'COS runtime query failed.']);
        }
    }

    private function manager(): array|Response
    {
        $user = $this->auth()->currentUser();
        return $user !== null && $this->auth()->isManager($user)
            ? $user
            : $this->json(403, ['ok' => false, 'error' => 'Manager authorization required.']);
    }

    private function json(int $status, array $payload): Response
    {
        $this->view->disable();
        $this->response->setStatusCode($status);
        $this->response->setContentType('application/json', 'UTF-8');
        return $this->response->setJsonContent($payload);
    }
}

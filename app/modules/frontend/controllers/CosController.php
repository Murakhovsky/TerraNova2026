<?php
declare(strict_types=1);

namespace Modules\Frontend\Controllers;

use DomainException;
use Kernel\Action\ActionStatus;
use Kernel\Action\Service\ActionService;
use Kernel\Approval\Service\ApprovalService;
use Kernel\Queue\Contract\JobQueueInterface;
use Kernel\Queue\Handler\ActionExecutionJobHandler;
use Modules\Frontend\Services\CosConsoleService;
use Throwable;

final class CosController extends ControllerBase
{
    public function indexAction(): void
    {
        if (!$this->requireManager()) return;

        $this->view->title = 'COS Control Center';
        $this->view->overview = ['stats' => [], 'events' => [], 'decisions' => [], 'actions' => [], 'approvals' => [], 'results' => [], 'audit' => []];
        $this->view->pageStatus = null;
        $this->view->actionStatus = (string) $this->request->getQuery('status_message', 'string', '');
        try {
            $this->view->overview = $this->console()->overview((int) $this->request->getQuery('limit', 'int', 30));
        } catch (Throwable $exception) {
            $this->logFrontendError('cos-console', $exception);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'COS Control Center тимчасово недоступний. Перевірте, чи застосовані міграції.';
        }
    }

    public function executeAction(?string $id = null): void
    {
        if (!$this->requireManager()) return;
        $actionId = (string) ($id ?: $this->dispatcher->getParam('id'));
        if (!$this->request->isPost() || !preg_match('/^[a-f0-9]{32}$/', $actionId)) {
            $this->redirectWithStatus('Некоректна Action.'); return;
        }

        try {
            $organizationId = $this->organizationId();
            /** @var ActionService $actions */
            $actions = $this->di->getShared('cosActionService');
            $action = $actions->find($organizationId, $actionId);
            if ($action === null) throw new DomainException('Action не знайдено.');
            if ($action->status !== ActionStatus::Queued) {
                throw new DomainException('Execute доступний лише для дозволеної QUEUED Action.');
            }
            $this->enqueueAction($actionId, $action->correlationId);
            $this->redirectWithStatus('Action поставлено в чергу.');
        } catch (DomainException $exception) {
            $this->redirectWithStatus($exception->getMessage());
        } catch (Throwable $exception) {
            $this->logFrontendError('cos-action-execute', $exception);
            $this->redirectWithStatus('Не вдалося поставити Action у чергу.');
        }
    }

    public function approveAction(?string $id = null): void { $this->decideApproval($id, true); }
    public function rejectAction(?string $id = null): void { $this->decideApproval($id, false); }

    private function decideApproval(?string $id, bool $approved): void
    {
        $user = $this->requireManager();
        if (!$user) return;
        $approvalId = (string) ($id ?: $this->dispatcher->getParam('id'));
        if (!$this->request->isPost() || !preg_match('/^[a-f0-9]{32}$/', $approvalId)) {
            $this->redirectWithStatus('Некоректний Approval.'); return;
        }
        try {
            $organizationId = $this->organizationId();
            $approval = $this->di->getShared('cosApprovalRepository')->findPending($organizationId, $approvalId);
            if ($approval === null) throw new DomainException('Approval вже оброблений або не існує.');
            /** @var ApprovalService $service */
            $service = $this->di->getShared('cosApprovalService');
            $note = trim((string) $this->request->getPost('note', 'string', '')) ?: null;
            if ($approved) {
                $service->approve($organizationId, $approvalId, (string) $user['id'], $note);
            } else {
                $service->reject($organizationId, $approvalId, (string) $user['id'], $note);
            }
            $this->redirectWithStatus($approved ? 'Action схвалено та поставлено в чергу.' : 'Action відхилено.');
        } catch (DomainException $exception) {
            $this->redirectWithStatus($exception->getMessage());
        } catch (Throwable $exception) {
            $this->logFrontendError('cos-approval', $exception);
            $this->redirectWithStatus('Не вдалося обробити Approval.');
        }
    }

    private function enqueueAction(string $actionId, string $correlationId): void
    {
        /** @var JobQueueInterface $queue */
        $queue = $this->di->getShared('cosJobQueue');
        $queue->enqueue(
            $this->organizationId(), ActionExecutionJobHandler::TYPE, ['action_id' => $actionId],
            $correlationId, 'action-execution:' . $actionId, 5, 120,
        );
    }

    private function console(): CosConsoleService { return $this->di->getShared('frontendCosConsoleService'); }
    private function organizationId(): string { return (string) $this->di->getShared('config')->cos->organizationId; }
    private function redirectWithStatus(string $message): void
    {
        $returnUrl = ltrim(trim((string) $this->request->getPost('return_url', 'string', '')), '/');
        if ($returnUrl !== 'cos' && !preg_match('#^client-case/show/\d+$#', $returnUrl)) $returnUrl = 'cos';
        $this->response->redirect($returnUrl . '?status_message=' . rawurlencode($message));
    }
}

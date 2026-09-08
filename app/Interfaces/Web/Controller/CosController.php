<?php
declare(strict_types=1);

namespace Interfaces\Web\Controller;

use DomainException;
use Kernel\Action\ActionStatus;
use Kernel\Action\Service\ActionService;
use Kernel\Approval\Service\ApprovalService;
use Kernel\Operations\Contract\OperationsReadModelInterface;
use Kernel\Queue\Contract\JobQueueInterface;
use Kernel\Queue\Handler\ActionExecutionJobHandler;
use Throwable;

final class CosController extends WebController
{
    public function indexAction(): void
    {
        if (!$this->requireManager()) return;
        $this->view->title = 'COS Control Center';
        $this->view->workspaceSection = 'cos';
        $this->view->pageAssetEntries = ['cos-control-center'];
        $this->view->csrfToken = $this->di->getShared('csrfTokenManager')->token();
        $this->view->actionStatus = (string) $this->request->getQuery('status_message', 'string', '');
        $this->view->pageStatus = null;
        try {
            /** @var OperationsReadModelInterface $query */
            $query = $this->di->getShared('cosOperationsReadModel');
            $this->view->overview = $query->overview(
                $this->organization()->id(),
                (int) $this->request->getQuery('limit', 'int', 30),
            );
        } catch (Throwable) {
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->overview = [];
            $this->view->pageStatus = 'COS Control Center тимчасово недоступний.';
        }
    }

    public function executeAction(?string $id = null): void
    {
        if (!$this->requireManager()) return;
        $actionId = (string) ($id ?: $this->dispatcher->getParam('id'));
        if (!$this->validMutation() || !preg_match('/^[a-f0-9]{32}$/', $actionId)) {
            $this->redirectWithStatus('Некоректний або непідтверджений запит.');
            return;
        }
        try {
            /** @var ActionService $actions */
            $actions = $this->di->getShared('cosActionService');
            $action = $actions->find($this->organization()->id(), $actionId);
            if ($action === null || $action->status !== ActionStatus::Queued) {
                throw new DomainException('Action недоступна для виконання.');
            }
            /** @var JobQueueInterface $queue */
            $queue = $this->di->getShared('cosJobQueue');
            $queue->enqueue(
                $this->organization()->id(),
                ActionExecutionJobHandler::TYPE,
                ['action_id' => $actionId],
                $action->correlationId,
                'action-execution:' . $actionId,
                5,
                120,
            );
            $this->redirectWithStatus('Action поставлено в чергу.');
        } catch (DomainException $error) {
            $this->redirectWithStatus($error->getMessage());
        }
    }

    public function approveAction(?string $id = null): void { $this->decide($id, true); }
    public function rejectAction(?string $id = null): void { $this->decide($id, false); }

    private function decide(?string $id, bool $approved): void
    {
        $user = $this->requireManager();
        $approvalId = (string) ($id ?: $this->dispatcher->getParam('id'));
        if ($user === null || !$this->validMutation() || !preg_match('/^[a-f0-9]{32}$/', $approvalId)) {
            $this->redirectWithStatus('Некоректний або непідтверджений запит.');
            return;
        }
        try {
            /** @var ApprovalService $service */
            $service = $this->di->getShared('cosApprovalService');
            $note = trim((string) $this->request->getPost('note', 'string', '')) ?: null;
            if ($approved) {
                $service->approve($this->organization()->id(), $approvalId, (string) $user['id'], $note);
            } else {
                $service->reject($this->organization()->id(), $approvalId, (string) $user['id'], $note);
            }
            $this->redirectWithStatus($approved ? 'Action схвалено.' : 'Action відхилено.');
        } catch (DomainException $error) {
            $this->redirectWithStatus($error->getMessage());
        }
    }

    private function redirectWithStatus(string $message): void
    {
        $returnUrl = ltrim(trim((string) $this->request->getPost('return_url', 'string', '')), '/');
        if ($returnUrl !== 'cos/control-center' && !preg_match('#^client-case/show/\d+$#', $returnUrl)) {
            $returnUrl = 'cos/control-center';
        }
        $this->response->redirect($returnUrl . '?status_message=' . rawurlencode($message));
    }
}

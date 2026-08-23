<?php
declare(strict_types=1);

namespace Modules\Frontend\Controllers;

use DomainException;
use Kernel\Approval\Service\ApprovalService;
use Throwable;

final class ApprovalController extends ControllerBase
{
    public function approveAction(?string $id = null): void
    {
        $this->decide((string) ($id ?: $this->dispatcher->getParam('id')), true);
    }

    public function rejectAction(?string $id = null): void
    {
        $this->decide((string) ($id ?: $this->dispatcher->getParam('id')), false);
    }

    private function decide(string $approvalId, bool $approved): void
    {
        $this->view->disable();
        $this->response->setContentType('application/json', 'UTF-8');
        $user = $this->currentUser();
        if (!$user) {
            $this->json(401, ['ok' => false, 'error' => 'Authentication required.']);
            return;
        }
        if (!$this->authService()->isManager($user)) {
            $this->json(403, ['ok' => false, 'error' => 'Manager role required.']);
            return;
        }
        if (!$this->request->isPost() || $approvalId === '') {
            $this->json(405, ['ok' => false, 'error' => 'POST and approval id are required.']);
            return;
        }

        try {
            /** @var ApprovalService $service */
            $service = $this->di->getShared('cosApprovalService');
            $organizationId = (string) $this->di->getShared('config')->cos->organizationId;
            $approval = $this->di->getShared('cosApprovalRepository')->findPending($organizationId, $approvalId);
            if ($approval === null) {
                throw new DomainException('Approval is not pending or does not exist.');
            }
            $note = trim((string) $this->request->getPost('note', 'string', '')) ?: null;
            if ($approved) {
                $service->approve($organizationId, $approvalId, (string) $user['id'], $note);
            } else {
                $service->reject($organizationId, $approvalId, (string) $user['id'], $note);
            }
            $this->json(200, ['ok' => true, 'status' => $approved ? 'APPROVED' : 'REJECTED']);
        } catch (DomainException $exception) {
            $this->json(409, ['ok' => false, 'error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            $this->logFrontendError('approval-decision', $exception);
            $this->json(500, ['ok' => false, 'error' => 'Approval decision failed.']);
        }
    }

    private function json(int $status, array $payload): void
    {
        $this->response->setStatusCode($status);
        $this->response->setJsonContent($payload);
    }
}

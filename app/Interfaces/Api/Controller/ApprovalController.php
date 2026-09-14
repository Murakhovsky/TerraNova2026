<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use DomainException;
use Interfaces\Web\Controller\WebController;
use Kernel\Approval\Service\ApprovalService;
use Phalcon\Http\Response;
use Throwable;

final class ApprovalController extends WebController
{
    public function approveAction(?string $id = null): Response { return $this->decide($id, true); }
    public function rejectAction(?string $id = null): Response { return $this->decide($id, false); }

    private function decide(?string $id, bool $approved): Response
    {
        $this->view->disable();
        $this->response->setContentType('application/json', 'UTF-8');
        $user = $this->auth()->currentUser();
        if ($user === null || !$this->auth()->isManager($user)) {
            return $this->json(403, ['ok' => false, 'error' => 'Manager authorization required.']);
        }
        $approvalId = (string) ($id ?: $this->dispatcher->getParam('id'));
        if (!$this->validMutation() || !preg_match('/^[a-f0-9]{32}$/', $approvalId)) {
            return $this->json(400, ['ok' => false, 'error' => 'Invalid request or CSRF token.']);
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
            return $this->json(200, ['ok' => true, 'status' => $approved ? 'APPROVED' : 'REJECTED']);
        } catch (DomainException $error) {
            return $this->json(409, ['ok' => false, 'error' => $error->getMessage()]);
        } catch (Throwable) {
            return $this->json(500, ['ok' => false, 'error' => 'Approval decision failed.']);
        }
    }

    private function json(int $status, array $payload): Response
    {
        $this->response->setStatusCode($status);
        return $this->response->setJsonContent($payload);
    }
}

<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use Domains\Sales\Application\Contract\SalesAdministrationReadModelInterface;
use Domains\Sales\Model\SalesCapability;
use Interfaces\Web\Controller\WebController;
use Phalcon\Http\Response;
use Throwable;

final class SalesAdminHealthController extends WebController
{
    public function indexAction(): Response
    {
        $user = $this->authorizedUser();
        if ($user instanceof Response) return $user;
        try {
            $limit = max(5, min(100, (int) $this->request->getQuery('limit', 'int', 50)));
            return $this->json(200, ['ok'=>true,'data'=>$this->service()->dashboard($this->organization()->id(), $limit)]);
        } catch (Throwable $error) {
            return $this->json(500, ['ok'=>false,'error'=>$error->getMessage()]);
        }
    }

    private function service(): SalesAdministrationReadModelInterface
    {
        return $this->di->getShared('salesAdministrationReadModel');
    }

    private function authorizedUser(): array|Response
    {
        $user = $this->auth()->currentUser();
        if ($user !== null && ($this->auth()->isAdmin($user) || $this->di->getShared('salesAccessControl')->hasCapability(
            $this->organization()->id(), (int) $user['id'], SalesCapability::AdminAuditView->value,
        ))) return $user;
        return $this->json(403, ['ok'=>false,'error'=>'Sales audit administration capability required.']);
    }

    private function json(int $status, array $payload): Response
    {
        $this->view->disable();
        $this->response->setStatusCode($status);
        $this->response->setContentType('application/json', 'UTF-8');
        return $this->response->setJsonContent($payload);
    }
}

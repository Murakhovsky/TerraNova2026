<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use Domains\Sales\Application\Contract\SalesWorkspaceOperationalReadModelInterface;
use Interfaces\Web\Controller\WebController;
use Phalcon\Http\Response;
use Throwable;

final class SalesWorkspaceSearchController extends WebController
{
    public function searchAction(): Response
    {
        $user = $this->auth()->currentUser();
        if ($user === null || !$this->auth()->isManager($user)) {
            return $this->json(403, ['ok' => false, 'error' => 'Manager authorization required.']);
        }

        $query = trim((string) $this->request->getQuery('q', 'string', ''));
        if (mb_strlen($query) < 2) {
            return $this->json(200, ['ok' => true, 'data' => [
                'query' => $query,
                'items' => [],
                'groups' => ['deals' => 0, 'leads' => 0, 'people' => 0],
            ]]);
        }
        $limit = max(1, min((int) $this->request->getQuery('limit', 'int', 6), 12));

        try {
            /** @var SalesWorkspaceOperationalReadModelInterface $search */
            $search = $this->di->getShared('salesWorkspaceOperationalReadModel');
            return $this->json(200, [
                'ok' => true,
                'data' => $search->search($this->organization()->id(), $query, $limit),
            ]);
        } catch (Throwable $error) {
            $this->di->getShared('cosLogger')->error('sales.workspace.search_failed', [
                'organization_id' => $this->organization()->id(),
                'error' => $error->getMessage(),
            ]);
            return $this->json(500, ['ok' => false, 'error' => 'Sales search is unavailable.']);
        }
    }

    private function json(int $status, array $payload): Response
    {
        $this->view->disable();
        $this->response->setStatusCode($status);
        $this->response->setContentType('application/json', 'UTF-8');
        return $this->response->setJsonContent($payload);
    }
}

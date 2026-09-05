<?php
declare(strict_types=1);

namespace Interfaces\Web\Controller;

use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;
use Throwable;

final class SalesController extends WebController
{
    public function dashboardAction(): void
    {
        $user = $this->requireManager();
        if ($user === null) return;
        $this->load('Sales Dashboard', fn ($q, $org) => $q->dashboard($org, (int) $user['id']));
    }

    public function pipelineAction(): void
    {
        if ($this->requireManager() === null) return;
        $this->load('Sales Pipeline', fn ($q, $org) => [
            'pipelines' => $q->pipelines($org),
            'deals' => $q->deals($org, (array) $this->request->getQuery()),
        ]);
    }

    public function todayAction(): void
    {
        $user = $this->requireManager();
        if ($user === null) return;
        $this->load('Today', fn ($q, $org) => $q->today($org, (int) $user['id']));
    }

    private function load(string $title, callable $reader): void
    {
        $this->view->title = $title;
        $this->view->workspace = [];
        $this->view->pageStatus = null;
        try {
            /** @var SalesWorkspaceReadModelInterface $query */
            $query = $this->di->getShared('salesWorkspaceReadModel');
            $this->view->workspace = $reader($query, $this->organization()->id());
        } catch (Throwable $error) {
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'Sales workspace тимчасово недоступний.';
            $this->di->getShared('cosLogger')->error('sales.workspace.read_failed', ['error' => $error->getMessage()]);
        }
    }
}

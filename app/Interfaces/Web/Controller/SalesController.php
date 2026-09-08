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

    public function leadsAction(): void
    {
        if ($this->requireManager() === null) return;
        $this->load('Sales Leads', fn ($q, $org) => [
            'leads' => $q->leads($org, (array) $this->request->getQuery()),
        ]);
    }

    public function dealAction(int $id): void
    {
        if ($this->requireManager() === null) return;
        $this->load('Deal Workspace', function ($q, $org) use ($id): array {
            $deal = $q->deal($org, $id);
            if ($deal === null) {
                $this->response->setStatusCode(404, 'Not Found');
                return ['deal' => null, 'timeline' => [], 'pipelines' => []];
            }
            return [
                'deal' => $deal,
                'timeline' => $q->timeline($org, $id, 100),
                'pipelines' => $q->pipelines($org),
            ];
        });
    }

    public function directorAction(): void
    {
        if ($this->requireManager() === null) return;
        $this->load('Sales Director', fn ($q, $org) => [
            'metrics' => $q->metrics($org, 30),
            'dashboard' => $q->dashboard($org, null),
            'deals' => $q->deals($org, ['limit' => 50]),
        ]);
    }

    public function adminAction(): void
    {
        if ($this->requireAdmin() === null) return;
        $this->load('Sales Administration', fn ($q, $org) => [
            'pipelines' => $q->pipelines($org),
            'metrics' => $q->metrics($org, 30),
        ]);
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

<?php
declare(strict_types=1);

namespace Interfaces\Web\Controller;

use Domains\Sales\Application\Contract\SalesWorkspaceOperationalReadModelInterface;
use Throwable;

final class SalesController extends WebController
{
    public function dashboardAction(): void
    {
        $user = $this->requireManager();
        if ($user === null) return;
        $this->load('Sales Overview', 'sales', fn ($q, $org) => $q->dashboard($org, (int) $user['id']));
    }

    public function pipelineAction(): void
    {
        if ($this->requireManager() === null) return;
        $this->load('Sales Pipeline', 'pipeline', fn ($q, $org) => [
            'pipelines' => $q->pipelines($org),
            'deals' => $q->deals($org, (array) $this->request->getQuery()),
            'owners' => $this->managerOptions(),
        ]);
    }

    public function todayAction(): void
    {
        $user = $this->requireManager();
        if ($user === null) return;
        $scope = strtolower(trim((string) $this->request->getQuery('scope', 'string', 'mine')));
        $ownerId = $scope === 'team' ? 0 : (int) $user['id'];
        $this->load('Sales Today', 'today', fn ($q, $org) => [
            'scope' => $scope === 'team' ? 'team' : 'mine',
            'sections' => $q->today($org, $ownerId),
        ]);
    }

    public function leadsAction(): void
    {
        if ($this->requireManager() === null) return;
        $this->load('Sales Leads', 'leads', fn ($q, $org) => [
            'leads' => $q->leads($org, (array) $this->request->getQuery()),
            'owners' => $this->managerOptions(),
        ]);
    }

    public function dealsAction(): void
    {
        if ($this->requireManager() === null) return;
        $this->load('Sales Deals', 'deals', fn ($q, $org) => [
            'pipelines' => $q->pipelines($org),
            'deals' => $q->deals($org, (array) $this->request->getQuery()),
            'owners' => $this->managerOptions(),
        ]);
    }

    public function dealAction(int $id): void
    {
        if ($this->requireManager() === null) return;
        $this->load('Deal Workspace', 'deals', function ($q, $org) use ($id): array {
            $deal = $q->deal($org, $id);
            if ($deal === null) {
                $this->response->setStatusCode(404, 'Not Found');
                return ['deal' => null, 'timeline' => [], 'communications' => [], 'approvals' => [], 'pipelines' => [], 'owners' => [], 'intelligence' => []];
            }
            $intelligence = [];
            try {
                $intelligence = (array) $this->di->getShared('cosOperationsReadModel')->dealIntelligence($org, $id);
            } catch (Throwable) {
                // Deal operations remain available even if intelligence projection is unavailable.
            }
            return [
                'deal' => $deal,
                'timeline' => $q->timeline($org, $id, 100),
                'communications' => $q->communications($org, $id, 50),
                'approvals' => $q->approvals($org, $id, null, 50),
                'pipelines' => $q->pipelines($org),
                'owners' => $this->managerOptions(),
                'intelligence' => $intelligence,
            ];
        });
    }

    public function directorAction(): void
    {
        if ($this->requireManager() === null) return;
        $this->load('Sales Director', 'director', fn ($q, $org) => [
            'metrics' => $q->metrics($org, 30),
            'dashboard' => $q->dashboard($org, null),
            'analytics' => $q->directorAnalytics($org, 30),
            'deals' => $q->deals($org, ['limit' => 50]),
        ]);
    }

    public function adminAction(): void
    {
        if ($this->requireAdmin() === null) return;
        $this->load('Sales Administration', 'sales-admin', fn ($q, $org) => [
            'pipelines' => $q->pipelines($org),
            'metrics' => $q->metrics($org, 30),
        ]);
    }

    private function load(string $title, string $active, callable $reader): void
    {
        $this->view->title = $title;
        $this->view->metaTitle = $title . ' | Terra Nova COS';
        $this->view->workspaceSection = 'sales';
        $this->view->workspaceActive = $active;
        $this->view->pageAssetEntries = ['sales-workspace'];
        $this->view->csrfToken = $this->di->getShared('csrfTokenManager')->token();
        $this->view->workspace = [];
        $this->view->pageStatus = null;
        try {
            /** @var SalesWorkspaceOperationalReadModelInterface $query */
            $query = $this->di->getShared('salesWorkspaceOperationalReadModel');
            $this->view->workspace = $reader($query, $this->organization()->id());
        } catch (Throwable $error) {
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'Sales workspace тимчасово недоступний.';
            $this->di->getShared('cosLogger')->error('sales.workspace.read_failed', ['error' => $error->getMessage()]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function managerOptions(): array
    {
        try {
            return $this->di->getShared('salesClientCaseReadModel')->managerOptions();
        } catch (Throwable) {
            return [];
        }
    }
}

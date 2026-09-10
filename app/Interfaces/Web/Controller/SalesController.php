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
                return ['deal' => null, 'timeline' => [], 'communications' => [], 'approvals' => [], 'pipelines' => [], 'owners' => [], 'intelligence' => $this->normalizeDealIntelligence([])];
            }
            $intelligence = $this->normalizeDealIntelligence([]);
            try {
                $intelligence = $this->normalizeDealIntelligence((array) $this->di->getShared('cosOperationsReadModel')->dealIntelligence($org, $id));
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

    /**
     * Stable Sales-facing intelligence contract. COS can evolve its internal
     * projection shape without forcing the Deal workspace to know every alias.
     *
     * @return array<string, mixed>
     */
    private function normalizeDealIntelligence(array $raw): array
    {
        $analysis = is_array($raw['analysis'] ?? null) ? $raw['analysis'] : [];
        $decision = is_array($raw['decision'] ?? null) ? $raw['decision'] : [];
        $signals = is_array($raw['signals'] ?? null) ? $raw['signals'] : [];
        $actions = is_array($raw['actions'] ?? null) ? $raw['actions'] : [];
        $firstAction = [];
        foreach ($actions as $candidate) {
            if (is_array($candidate)) {
                $firstAction = $candidate;
                break;
            }
        }
        $pick = static function (mixed ...$values): mixed {
            foreach ($values as $value) {
                if ($value !== null && $value !== '' && $value !== []) return $value;
            }
            return null;
        };

        return array_replace($raw, [
            'contract_version' => 'sales.intelligence.v1',
            'deal_health' => $pick($raw['deal_health'] ?? null, $analysis['deal_health'] ?? null, $decision['deal_health'] ?? null, $raw['health'] ?? null, $decision['risk_level'] ?? null),
            'customer_intent' => $pick($raw['customer_intent'] ?? null, $analysis['customer_intent'] ?? null, $signals['customer_intent'] ?? null, $decision['customer_intent'] ?? null),
            'objections' => $pick($raw['objections'] ?? null, $analysis['objections'] ?? null, $signals['objections'] ?? null),
            'missing_information' => $pick($raw['missing_information'] ?? null, $analysis['missing_information'] ?? null, $signals['missing_information'] ?? null, $raw['missing_info'] ?? null),
            'next_best_action' => $pick($raw['next_best_action'] ?? null, $analysis['next_best_action'] ?? null, $decision['next_best_action'] ?? null, $firstAction['reason'] ?? null, $firstAction['description'] ?? null, $firstAction['type'] ?? null),
            'recommended_timing' => $pick($raw['recommended_timing'] ?? null, $analysis['recommended_timing'] ?? null, $decision['recommended_timing'] ?? null, $firstAction['recommended_at'] ?? null, $firstAction['execute_at'] ?? null),
            'confidence' => $pick($raw['confidence'] ?? null, $analysis['confidence'] ?? null, $decision['confidence'] ?? null),
        ]);
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

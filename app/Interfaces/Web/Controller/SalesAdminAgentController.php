<?php
declare(strict_types=1);

namespace Interfaces\Web\Controller;

use Domains\Sales\Application\Contract\SalesAgentAdministrationInterface;
use Throwable;

final class SalesAdminAgentController extends WebController
{
    public function agentsAction(): void
    {
        if ($this->requireAdmin() === null) return;
        $this->base('Sales Intelligence Agents', 'agents');
        try {
            $this->view->agents = $this->service()->agents($this->organization()->id());
            $this->view->agentCatalog = $this->service()->catalog();
        } catch (Throwable $exception) {
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = $exception->getMessage();
        }
    }

    public function agentAction(?string $name = null): void
    {
        if ($this->requireAdmin() === null) return;
        $this->base('Sales Intelligence Agent', 'agent');
        $agentName = trim((string) ($name ?: $this->dispatcher->getParam('name')));
        try {
            $this->view->agent = $this->service()->agent($this->organization()->id(), $agentName);
            $this->view->agentCatalog = $this->service()->catalog();
            $this->view->agentRevisions = $this->view->agent === null
                ? []
                : $this->service()->revisions($this->organization()->id(), $agentName, 50);
            if ($this->view->agent === null) $this->response->setStatusCode(404, 'Not Found');
        } catch (Throwable $exception) {
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = $exception->getMessage();
        }
    }

    private function base(string $title, string $view): void
    {
        $this->view->title = $title;
        $this->view->metaTitle = $title . ' | Terra Nova COS';
        $this->view->workspaceSection = 'sales';
        $this->view->workspaceActive = 'sales-admin';
        $this->view->pageAssetEntries = ['sales-workspace'];
        $this->view->csrfToken = $this->di->getShared('csrfTokenManager')->token();
        $this->view->pageStatus = null;
        $this->view->pick('sales_admin/' . $view);
    }

    private function service(): SalesAgentAdministrationInterface
    {
        return $this->di->getShared('salesAgentAdministration');
    }
}

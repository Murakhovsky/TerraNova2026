<?php
declare(strict_types=1);

namespace Interfaces\Web\Controller;

use Domains\Sales\Application\Contract\SalesPipelineAdministrationInterface;
use Domains\Sales\Application\Contract\SalesRuleAdministrationInterface;
use Throwable;

final class SalesAdminController extends WebController
{
    public function pipelinesAction(): void
    {
        if ($this->requireAdmin() === null) return;
        $this->base('Sales Pipelines', 'pipelines');
        try {
            $this->view->pipelines = $this->pipelineService()->pipelines($this->organization()->id());
        } catch (Throwable $exception) {
            $this->renderFrontendException($exception, 'sales_admin.pipelines');
        }
    }

    public function pipelineAction(?string $id = null): void
    {
        if ($this->requireAdmin() === null) return;
        $this->base('Pipeline Configuration', 'pipeline');
        $pipelineId = trim((string) ($id ?: $this->dispatcher->getParam('id')));
        try {
            $this->view->pipeline = $this->pipelineService()->pipeline($this->organization()->id(), $pipelineId);
            if ($this->view->pipeline === null) {
                $this->renderFrontendFailure(404, null, 'workspace');
            }
        } catch (Throwable $exception) {
            $this->renderFrontendException($exception, 'sales_admin.pipeline');
        }
    }

    public function rulesAction(): void
    {
        if ($this->requireAdmin() === null) return;
        $this->base('Sales Business Rules', 'rules');
        try {
            $this->view->rules = $this->ruleService()->rules($this->organization()->id());
            $this->view->ruleCatalog = $this->ruleService()->catalog();
        } catch (Throwable $exception) {
            $this->renderFrontendException($exception, 'sales_admin.rules');
        }
    }

    public function ruleAction(?string $id = null): void
    {
        if ($this->requireAdmin() === null) return;
        $this->base('Business Rule Editor', 'rule');
        $ruleId = trim((string) ($id ?: $this->dispatcher->getParam('id')));
        try {
            $this->view->rule = $this->ruleService()->rule($this->organization()->id(), $ruleId);
            if ($this->view->rule === null) {
                $this->renderFrontendFailure(404, null, 'workspace');
                return;
            }
            $this->view->ruleCatalog = $this->ruleService()->catalog();
            $this->view->ruleRevisions = $this->ruleService()->revisions($this->organization()->id(), $ruleId, 50);
        } catch (Throwable $exception) {
            $this->renderFrontendException($exception, 'sales_admin.rule');
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

    private function pipelineService(): SalesPipelineAdministrationInterface
    {
        return $this->di->getShared('salesPipelineAdministration');
    }

    private function ruleService(): SalesRuleAdministrationInterface
    {
        return $this->di->getShared('salesRuleAdministration');
    }
}

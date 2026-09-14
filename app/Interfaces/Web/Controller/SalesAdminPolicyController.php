<?php
declare(strict_types=1);
namespace Interfaces\Web\Controller;
use Domains\Sales\Application\Contract\SalesPolicyAdministrationInterface;
use Throwable;
final class SalesAdminPolicyController extends WebController
{
    public function actionsAction(): void
    {
        if($this->requireAdmin()===null)return;
        $this->view->title='Actions & Policies Administration';$this->view->metaTitle='Sales Policies | Terra Nova COS';$this->view->workspaceSection='sales';$this->view->workspaceActive='sales-admin';$this->view->pageAssetEntries=['sales-workspace'];$this->view->csrfToken=$this->di->getShared('csrfTokenManager')->token();$this->view->pageStatus=null;$this->view->pick('sales_admin/actions');
        try{$this->view->actions=$this->service()->actions($this->organization()->id());$this->view->policyCatalog=$this->service()->catalog($this->organization()->id());}catch(Throwable $e){$this->response->setStatusCode(503,'Service Unavailable');$this->view->pageStatus=$e->getMessage();}
    }
    private function service(): SalesPolicyAdministrationInterface{return $this->di->getShared('salesPolicyAdministration');}
}

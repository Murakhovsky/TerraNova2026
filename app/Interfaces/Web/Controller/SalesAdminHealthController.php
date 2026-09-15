<?php
declare(strict_types=1);

namespace Interfaces\Web\Controller;

use Domains\Sales\Application\Contract\SalesAdministrationReadModelInterface;
use Domains\Sales\Model\SalesCapability;
use Throwable;

final class SalesAdminHealthController extends WebController
{
    public function indexAction(): void
    {
        $user = $this->auth()->currentUser();
        if ($user === null) { $this->response->redirect('auth/login'); return; }
        if (!$this->auth()->isAdmin($user) && !$this->di->getShared('salesAccessControl')->hasCapability(
            $this->organization()->id(), (int) $user['id'], SalesCapability::AdminAuditView->value,
        )) {
            $this->renderFrontendFailure(403, null, 'workspace');
            return;
        }

        $this->view->title = 'Sales Administration Health & Audit';
        $this->view->metaTitle = 'Sales Health & Audit | Terra Nova COS';
        $this->view->workspaceSection = 'sales';
        $this->view->workspaceActive = 'sales-admin';
        $this->view->pageAssetEntries = ['sales-workspace'];
        $this->view->pageStatus = null;
        $this->view->pick('sales_admin/health');
        try {
            $this->view->administrationHealth = $this->service()->dashboard($this->organization()->id(), 50);
        } catch (Throwable $error) {
            $this->renderFrontendException($error, 'sales_admin.health');
        }
    }

    private function service(): SalesAdministrationReadModelInterface
    {
        return $this->di->getShared('salesAdministrationReadModel');
    }
}

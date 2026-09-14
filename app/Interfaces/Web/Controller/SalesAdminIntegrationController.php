<?php
declare(strict_types=1);

namespace Interfaces\Web\Controller;

use Domains\Sales\Application\Contract\SalesIntegrationAdministrationInterface;
use Domains\Sales\Model\SalesCapability;
use Throwable;

final class SalesAdminIntegrationController extends WebController
{
    public function integrationsAction(): void
    {
        $user = $this->auth()->currentUser();
        if ($user === null) {
            $this->response->redirect('auth/login');
            return;
        }
        if (!$this->auth()->isAdmin($user)
            && !$this->di->getShared('salesAccessControl')->hasCapability(
                $this->organization()->id(),
                (int) $user['id'],
                SalesCapability::AdminIntegrationsManage->value,
            )) {
            $this->response->setStatusCode(403, 'Forbidden');
            return;
        }

        $this->view->title = 'Sales Integrations Administration';
        $this->view->metaTitle = 'Sales Integrations | Terra Nova COS';
        $this->view->workspaceSection = 'sales';
        $this->view->workspaceActive = 'sales-admin';
        $this->view->pageAssetEntries = ['sales-workspace'];
        $this->view->csrfToken = $this->di->getShared('csrfTokenManager')->token();
        $this->view->pageStatus = null;
        $this->view->pick('sales_admin/integrations');

        try {
            $organizationId = $this->organization()->id();
            $items = $this->service()->integrations($organizationId);
            foreach ($items as &$item) {
                $full = $this->service()->integration($organizationId, (int) $item['id']);
                if ($full !== null) {
                    $item = array_merge($item, $full);
                }
            }
            unset($item);
            $this->view->integrations = $items;
            $this->view->integrationCatalog = $this->service()->catalog();
            $this->view->routingOptions = $this->service()->routingOptions($organizationId);
        } catch (Throwable $error) {
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = $error->getMessage();
        }
    }

    private function service(): SalesIntegrationAdministrationInterface
    {
        return $this->di->getShared('salesIntegrationAdministration');
    }
}

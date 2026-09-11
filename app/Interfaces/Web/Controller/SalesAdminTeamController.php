<?php
declare(strict_types=1);

namespace Interfaces\Web\Controller;

use Domains\Sales\Application\Contract\SalesTeamAdministrationInterface;
use Domains\Sales\Model\SalesCapability;
use Throwable;

final class SalesAdminTeamController extends WebController
{
    public function teamsAction(): void
    {
        $user = $this->auth()->currentUser();
        if ($user === null) { $this->response->redirect('auth/login'); return; }
        if (!$this->auth()->isAdmin($user)
            && !$this->di->getShared('salesAccessControl')->hasCapability($this->organization()->id(), (int) $user['id'], SalesCapability::AdminTeamsManage->value)) {
            $this->response->setStatusCode(403, 'Forbidden');
            return;
        }
        $this->view->title = 'Users, Teams & Authority';
        $this->view->metaTitle = 'Sales Teams | Terra Nova COS';
        $this->view->workspaceSection = 'sales';
        $this->view->workspaceActive = 'sales-admin';
        $this->view->pageAssetEntries = ['sales-workspace'];
        $this->view->csrfToken = $this->di->getShared('csrfTokenManager')->token();
        $this->view->pageStatus = null;
        $this->view->pick('sales_admin/teams');
        try {
            $this->view->teams = $this->service()->teams($this->organization()->id());
            $this->view->users = $this->service()->users($this->organization()->id());
            $this->view->teamCatalog = $this->service()->catalog();
        } catch (Throwable $error) {
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = $error->getMessage();
        }
    }

    private function service(): SalesTeamAdministrationInterface
    {
        return $this->di->getShared('salesTeamAdministration');
    }
}

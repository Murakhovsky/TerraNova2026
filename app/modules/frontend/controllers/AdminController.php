<?php
declare(strict_types=1);

namespace Modules\Frontend\Controllers;

use Throwable;

class AdminController extends ControllerBase
{
    public function indexAction(): void
    {
        if (!$this->requireManager()) {
            return;
        }

        $this->view->title = 'Admin dashboard';
        $this->view->metaTitle = 'Admin dashboard | Terra Nova CLUB';
        $this->view->pageStatus = null;
        $this->view->metrics = [];
        $this->view->propertyStatus = [];
        $this->view->submissionStatus = [];
        $this->view->caseStages = [];
        $this->view->recentSubmissions = [];
        $this->view->recentRequests = [];
        $this->view->activeCases = [];
        $this->view->attentionProperties = [];
        $this->view->moderationProperties = [];
        $this->view->activeProperties = [];
        $this->view->recentManagerActivities = [];

        try {
            $dashboard = $this->di->getShared('frontendAdminDashboardService');
            $this->view->metrics = $dashboard->metrics();
            $this->view->propertyStatus = $dashboard->propertyStatus();
            $this->view->submissionStatus = $dashboard->submissionStatus();
            $this->view->caseStages = $dashboard->caseStages();
            $this->view->recentSubmissions = $dashboard->recentSubmissions();
            $this->view->recentRequests = $dashboard->recentRequests();
            $this->view->activeCases = $dashboard->activeCases();
            $this->view->attentionProperties = $dashboard->attentionProperties();
            $this->view->moderationProperties = $dashboard->moderationProperties();
            $this->view->activeProperties = $dashboard->activeProperties();
            $this->view->recentManagerActivities = $dashboard->recentManagerActivities();
        } catch (Throwable $e) {
            $this->logFrontendError('admin-dashboard', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'Панель керування тимчасово недоступна. Деталі записано в лог.';
        }
    }

    public function usersAction(): void
    {
        if (!$this->requireAdmin()) {
            return;
        }

        $this->view->title = 'Користувачі та ролі';
        $this->view->metaTitle = 'Користувачі та ролі | Terra Nova CLUB';
        $this->view->pageStatus = null;
        $this->view->actionStatus = (string) $this->request->getQuery('status_message', 'string', '');
        $this->view->filters = [];
        $this->view->users = [];
        $this->view->userStats = [];
        $this->view->roleCapabilities = $this->authService()->roleCapabilities();

        try {
            $dashboard = $this->di->getShared('frontendAdminDashboardService');
            $this->view->filters = $dashboard->userFilters((array) $this->request->getQuery());
            $this->view->users = $dashboard->users($this->view->filters);
            $this->view->userStats = $dashboard->userStats();
        } catch (Throwable $e) {
            $this->logFrontendError('admin-users', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'Керування користувачами тимчасово недоступне.';
        }
    }

    public function createUserAction(): void
    {
        if (!$this->requireAdmin()) {
            return;
        }

        if (!$this->request->isPost()) {
            $this->response->redirect('admin/users');
            return;
        }

        $result = $this->di->getShared('frontendAdminDashboardService')->createUser((array) $this->request->getPost());
        $this->response->redirect('admin/users?status_message=' . rawurlencode($result['message']));
    }

    public function updateUserAction(?string $id = null): void
    {
        $admin = $this->requireAdmin();
        if (!$admin) {
            return;
        }

        $userId = (int) ($id ?: $this->dispatcher->getParam('params') ?: $this->dispatcher->getParam('id'));
        if (!$this->request->isPost() || $userId <= 0) {
            $this->response->redirect('admin/users');
            return;
        }

        $result = $this->di->getShared('frontendAdminDashboardService')->updateUser($userId, (array) $this->request->getPost(), $admin);
        $this->response->redirect('admin/users?status_message=' . rawurlencode($result['message']));
    }
}

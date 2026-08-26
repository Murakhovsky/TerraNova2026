<?php
declare(strict_types=1);

namespace Modules\Frontend\Controllers;

use Throwable;

class ClientCaseController extends ControllerBase
{
    public function indexAction(): void
    {
        if (!$this->requireManager()) {
            return;
        }

        $this->view->title = 'CRM кейсів';
        $this->view->filters = $this->clientCaseService()->filters((array) $this->request->getQuery());
        $this->view->cases = [];
        $this->view->stats = [];
        $this->view->unlinkedInboundRequests = [];
        $this->view->openCaseOptions = [];
        $this->view->managerOptions = [];
        $this->view->propertyTypes = [];
        $this->view->locations = [];
        $this->view->pageStatus = null;
        $this->view->actionStatus = (string) $this->request->getQuery('status_message', 'string', '');

        try {
            $this->view->cases = $this->clientCaseService()->cases($this->view->filters);
            $this->view->stats = $this->clientCaseService()->stats();
            $this->view->unlinkedInboundRequests = $this->clientCaseService()->unlinkedInboundRequests();
            $this->view->openCaseOptions = $this->clientCaseService()->openCaseOptions();
            $this->view->managerOptions = $this->clientCaseService()->managerOptions();
            $this->view->propertyTypes = $this->catalogService()->propertyTypes();
            $this->view->locations = $this->catalogService()->locations();
        } catch (Throwable $e) {
            $this->logFrontendError('client-case-index', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'CRM кейсів тимчасово недоступна.';
        }
    }

    public function inboxAction(): void
    {
        if (!$this->requireManager()) {
            return;
        }

        $this->view->title = 'CRM заявки';
        $this->view->filters = $this->clientCaseService()->inboundFilters((array) $this->request->getQuery());
        $this->view->inboundRequests = [];
        $this->view->inboundStats = [];
        $this->view->openCaseOptions = [];
        $this->view->managerOptions = [];
        $this->view->pageStatus = null;
        $this->view->actionStatus = (string) $this->request->getQuery('status_message', 'string', '');

        try {
            $this->view->inboundRequests = $this->clientCaseService()->inboundInbox($this->view->filters);
            $this->view->inboundStats = $this->clientCaseService()->inboundInboxStats();
            $this->view->openCaseOptions = $this->clientCaseService()->openCaseOptions();
            $this->view->managerOptions = $this->clientCaseService()->managerOptions();
        } catch (Throwable $e) {
            $this->logFrontendError('client-case-inbox', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'CRM заявки тимчасово недоступні.';
        }
    }

    public function showAction(?string $id = null): void
    {
        if (!$this->requireManager()) {
            return;
        }

        $caseId = (int) ($id ?: $this->dispatcher->getParam('params') ?: $this->dispatcher->getParam('id'));
        $this->view->title = 'Картка кейсу';
        $this->view->case = null;
        $this->view->inboundRequests = [];
        $this->view->activities = [];
        $this->view->propertyMatches = [];
        $this->view->requestMatches = [];
        $this->view->aiIntelligence = ['decision' => null, 'actions' => []];
        $this->view->pageStatus = null;
        $this->view->actionStatus = (string) $this->request->getQuery('status_message', 'string', '');

        try {
            $case = $this->clientCaseService()->case($caseId);

            if (!$case) {
                $this->response->setStatusCode(404, 'Not Found');
                return;
            }

            $this->view->case = $case;
            $this->view->inboundRequests = $this->clientCaseService()->inboundRequests($caseId);
            $this->view->activities = $this->clientCaseService()->activities($caseId);
            $this->view->propertyMatches = $this->clientCaseService()->propertyMatches($caseId);
            $this->view->requestMatches = $this->clientCaseService()->requestMatches($caseId);
            try {
                $this->view->aiIntelligence = $this->di->getShared('cosOperationsReadModel')->dealIntelligence(
                    $this->di->getShared('organizationContext')->id(),
                    $caseId,
                );
                $this->view->csrfToken = $this->di->getShared('csrfTokenManager')->token();
            } catch (Throwable $exception) {
                $this->logFrontendError('client-case-ai-intelligence', $exception);
            }
            $this->view->managerOptions = $this->clientCaseService()->managerOptions();
            $this->view->propertyTypes = $this->catalogService()->propertyTypes();
            $this->view->locations = $this->catalogService()->locations();
        } catch (Throwable $e) {
            $this->logFrontendError('client-case-show', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'Картку кейсу тимчасово не вдалося відкрити.';
        }
    }

    public function createAction(): void
    {
        if (!$this->requireManager()) {
            return;
        }

        if (!$this->request->isPost()) {
            $this->response->redirect('client-case');
            return;
        }

        $result = $this->clientCaseService()->create((array) $this->request->getPost(), $this->currentUser());
        $target = !empty($result['case_id'])
            ? 'client-case/show/' . $result['case_id']
            : 'client-case';

        $this->response->redirect($target . '?status_message=' . rawurlencode($result['message']));
    }

    public function updateAction(?string $id = null): void
    {
        if (!$this->requireManager()) {
            return;
        }

        $caseId = (int) ($id ?: $this->dispatcher->getParam('params') ?: $this->dispatcher->getParam('id'));
        if (!$this->request->isPost() || $caseId <= 0) {
            $this->response->redirect('client-case');
            return;
        }

        $result = $this->clientCaseService()->update($caseId, (array) $this->request->getPost(), $this->currentUser());
        $this->response->redirect('client-case/show/' . $caseId . '?status_message=' . rawurlencode($result['message']));
    }

    public function quickUpdateAction(?string $id = null): void
    {
        if (!$this->requireManager()) {
            return;
        }

        $caseId = (int) ($id ?: $this->dispatcher->getParam('params') ?: $this->dispatcher->getParam('id'));
        if (!$this->request->isPost() || $caseId <= 0) {
            $this->response->redirect('client-case');
            return;
        }

        $result = $this->clientCaseService()->quickUpdate($caseId, (array) $this->request->getPost(), $this->currentUser());
        $returnUrl = $this->safeReturnUrl((string) $this->request->getPost('return_url', 'string', ''));
        $target = $returnUrl ?: 'client-case';
        $separator = str_contains($target, '?') ? '&' : '?';

        $this->response->redirect($target . $separator . 'status_message=' . rawurlencode($result['message']));
    }

    public function activityAction(?string $id = null): void
    {
        if (!$this->requireManager()) {
            return;
        }

        $caseId = (int) ($id ?: $this->dispatcher->getParam('params') ?: $this->dispatcher->getParam('id'));
        if (!$this->request->isPost() || $caseId <= 0) {
            $this->response->redirect('client-case');
            return;
        }

        $result = $this->clientCaseService()->addActivity($caseId, (array) $this->request->getPost(), $this->currentUser());
        $this->response->redirect('client-case/show/' . $caseId . '?status_message=' . rawurlencode($result['message']));
    }

    public function attachInboundRequestAction(?string $id = null): void
    {
        if (!$this->requireManager()) {
            return;
        }

        $caseId = (int) ($id ?: $this->dispatcher->getParam('params') ?: $this->dispatcher->getParam('id'));
        $requestId = (int) $this->request->getPost('request_id', 'int', 0);

        if (!$this->request->isPost() || $caseId <= 0 || $requestId <= 0) {
            $this->response->redirect('client-case');
            return;
        }

        $result = $this->clientCaseService()->attachInboundRequest($caseId, $requestId, $this->currentUser());
        $this->response->redirect('client-case/show/' . $caseId . '?status_message=' . rawurlencode($result['message']));
    }

    public function linkInboundRequestAction(): void
    {
        if (!$this->requireManager()) {
            return;
        }

        $caseId = (int) $this->request->getPost('case_id', 'int', 0);
        $requestId = (int) $this->request->getPost('request_id', 'int', 0);
        $returnUrl = $this->safeReturnUrl((string) $this->request->getPost('return_url', 'string', ''));

        if (!$this->request->isPost() || $caseId <= 0 || $requestId <= 0) {
            $this->response->redirect($returnUrl ?: 'client-case');
            return;
        }

        $result = $this->clientCaseService()->attachInboundRequest($caseId, $requestId, $this->currentUser());
        $target = $returnUrl ?: 'client-case';
        $separator = str_contains($target, '?') ? '&' : '?';
        $this->response->redirect($target . $separator . 'status_message=' . rawurlencode($result['message']));
    }

    public function createFromInboundRequestAction(?string $id = null): void
    {
        if (!$this->requireManager()) {
            return;
        }

        $requestId = (int) ($id ?: $this->dispatcher->getParam('params') ?: $this->dispatcher->getParam('id'));
        $returnUrl = $this->safeReturnUrl((string) $this->request->getPost('return_url', 'string', ''));
        if (!$this->request->isPost() || $requestId <= 0) {
            $this->response->redirect($returnUrl ?: 'client-case');
            return;
        }

        $result = $this->clientCaseService()->createCaseFromInboundRequest($requestId, (array) $this->request->getPost(), $this->currentUser());
        $target = $returnUrl ?: (!empty($result['case_id'])
            ? 'client-case/show/' . $result['case_id']
            : 'client-case');
        $separator = str_contains($target, '?') ? '&' : '?';

        $this->response->redirect($target . $separator . 'status_message=' . rawurlencode($result['message']));
    }

    public function matchPropertyAction(): void
    {
        if (!$this->requireManager()) {
            return;
        }

        $caseId = (int) $this->request->getPost('case_id', 'int', 0);
        $propertyId = (int) $this->request->getPost('property_id', 'int', 0);
        $returnUrl = $this->safeReturnUrl((string) $this->request->getPost('return_url', 'string', ''));

        if (!$this->request->isPost() || $caseId <= 0 || $propertyId <= 0) {
            $this->response->redirect($returnUrl ?: 'client-case');
            return;
        }

        $result = $this->clientCaseService()->addPropertyMatch($caseId, $propertyId, (array) $this->request->getPost(), $this->currentUser());
        $target = $returnUrl ?: 'client-case/show/' . $caseId;
        $separator = str_contains($target, '?') ? '&' : '?';

        $this->response->redirect($target . $separator . 'status_message=' . rawurlencode($result['message']));
    }

    public function updatePropertyMatchAction(?string $id = null): void
    {
        if (!$this->requireManager()) {
            return;
        }

        $matchId = (int) ($id ?: $this->dispatcher->getParam('params') ?: $this->dispatcher->getParam('id'));
        if (!$this->request->isPost() || $matchId <= 0) {
            $this->response->redirect('client-case');
            return;
        }

        $result = $this->clientCaseService()->updatePropertyMatch($matchId, (array) $this->request->getPost(), $this->currentUser());
        $caseId = (int) ($result['case_id'] ?? 0);
        $target = $caseId > 0 ? 'client-case/show/' . $caseId : 'client-case';

        $this->response->redirect($target . '?status_message=' . rawurlencode($result['message']));
    }

    public function updateInboundRequestAction(?string $id = null): void
    {
        if (!$this->requireManager()) {
            return;
        }

        $requestId = (int) ($id ?: $this->dispatcher->getParam('params') ?: $this->dispatcher->getParam('id'));
        $returnUrl = $this->safeReturnUrl((string) $this->request->getPost('return_url', 'string', ''));

        if (!$this->request->isPost() || $requestId <= 0) {
            $this->response->redirect($returnUrl ?: 'client-case/inbox');
            return;
        }

        $result = $this->clientCaseService()->updateInboundRequest($requestId, (array) $this->request->getPost(), $this->currentUser());
        $target = $returnUrl ?: 'client-case/inbox';
        $separator = str_contains($target, '?') ? '&' : '?';

        $this->response->redirect($target . $separator . 'status_message=' . rawurlencode($result['message']));
    }

    private function safeReturnUrl(string $value): string
    {
        $value = ltrim(trim($value), '/');

        if ($value === '') {
            return '';
        }

        foreach (['property/', 'client-case', 'cabinet'] as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return $value;
            }
        }

        return '';
    }
}

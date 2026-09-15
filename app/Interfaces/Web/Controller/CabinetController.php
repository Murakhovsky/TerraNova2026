<?php
declare(strict_types=1);

namespace Interfaces\Web\Controller;

use Throwable;

class CabinetController extends ControllerBase
{
    public function indexAction(): void
    {
        $user = $this->requireUser();
        if (!$user) {
            return;
        }

        $this->preparePortalSurface($user, 'Кабінет');
        $this->view->user = $user;
        $this->view->myProperties = [];
        $this->view->submissions = [];
        $this->view->inboundRequests = [];
        $this->view->pageStatus = null;
        $this->view->telegramBinding = null;
        $this->view->telegramStatus = (string) $this->request->getQuery('telegram_status', 'string', '');

        try {
            $data = $this->authService()->cabinetData($user);
            $this->view->myProperties = (array) ($data['my_properties'] ?? []);
            $this->view->submissions = (array) ($data['submissions'] ?? []);
            $this->view->inboundRequests = (array) ($data['inbound_requests'] ?? []);
            $this->view->telegramBinding = $this->telegramAutomationService()->bindingForUser((int) $user['id']);
        } catch (Throwable $e) {
            $this->logFrontendError('cabinet-page', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'Дані кабінету тимчасово недоступні. Спробуйте оновити сторінку трохи пізніше.';
        }
    }

    public function telegramConnectAction(): void
    {
        $user = $this->requireUser();
        if (!$user || !$this->request->isPost()) {
            $this->response->redirect('cabinet');
            return;
        }

        try {
            $link = $this->telegramAutomationService()->createUserLink((int) $user['id']);
            $username = ltrim((string) $this->di->getShared('config')->telegram->bot_username, '@');
            if ($username === '') {
                throw new \RuntimeException('Telegram bot username is not configured.');
            }

            $this->response->redirect(
                'https://t.me/' . rawurlencode($username) . '?start=' . rawurlencode((string) $link['token']),
                true
            );
        } catch (Throwable $e) {
            $this->logFrontendError('telegram-connect', $e);
            $this->response->redirect('cabinet?telegram_status=connect_error');
        }
    }

    public function telegramDisconnectAction(): void
    {
        $user = $this->requireUser();
        if (!$user || !$this->request->isPost()) {
            $this->response->redirect('cabinet');
            return;
        }

        $this->telegramAutomationService()->disconnectUser((int) $user['id']);
        $this->response->redirect('cabinet?telegram_status=disconnected');
    }

    public function submissionAction(?string $id = null): void
    {
        $user = $this->requireUser();
        if (!$user) {
            return;
        }

        $this->preparePortalSurface($user, 'Редагування поданого об’єкта');
        $submissionId = (int) ($id ?: $this->dispatcher->getParam('params') ?: $this->dispatcher->getParam('id'));
        $this->view->submission = null;
        $this->view->media = [];
        $this->view->pageStatus = null;
        $this->view->actionStatus = null;
        $this->view->formData = [];

        if ($submissionId <= 0) {
            $this->response->redirect('cabinet');
            return;
        }

        try {
            if ($this->request->isPost()) {
                $result = $this->propertySubmissionService()->updateForUser(
                    $submissionId,
                    $user,
                    (array) $this->request->getPost(),
                    (array) $_FILES
                );
                $this->view->actionStatus = (string) ($result['message'] ?? '');
            }

            $submission = $this->propertySubmissionService()->submissionForUser($submissionId, $user);
            if (!$submission) {
                $this->response->setStatusCode(404, 'Not Found');
                $this->view->pageStatus = 'Заявку не знайдено або вона належить іншому користувачу.';
                return;
            }

            $this->view->submission = $submission;
            $this->view->formData = array_merge($submission, (array) $this->request->getPost());
            $this->view->media = $this->di->getShared('mediaStorageService')->assetsFor(
                'property_submission',
                $submissionId
            );
        } catch (Throwable $e) {
            $this->logFrontendError('cabinet-submission', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'Редагування заявки тимчасово недоступне.';
        }
    }

    private function preparePortalSurface(array $user, string $title): void
    {
        $role = (string) ($user['role'] ?? 'buyer');

        try {
            if ($this->authService()->isManager($user)) {
                $role = $this->authService()->isAdmin($user) ? 'admin' : 'manager';
            }
        } catch (Throwable $e) {
            // Keep the account role if organization membership cannot be resolved.
        }

        $capabilityMatrix = $this->authService()->roleCapabilities();
        $capabilities = (array) ($capabilityMatrix[$role] ?? []);

        $this->view->user = $user;
        $this->view->title = $title;
        $this->view->metaTitle = $title . ' | Terra Nova CLUB';
        $this->view->metaRobots = 'noindex,nofollow';
        $this->view->pageAssetEntries = ['portal-cabinet'];
        $this->view->interfaceSurface = 'portal';
        $this->view->portalRole = $role;
        $this->view->portalCapabilities = $capabilities;
        $this->view->canSubmitProperty = !empty($capabilities['submit_property']);
    }
}

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

        $this->view->title = 'Кабінет';
        $this->view->user = $user;
        $this->view->isManager = $this->authService()->isManager($user);
        $this->view->myProperties = [];
        $this->view->submissions = [];
        $this->view->inboundRequests = [];
        $this->view->managerWorkspace = [];
        $this->view->pageStatus = null;
        $this->view->telegramBinding = null;
        $this->view->telegramOutboxStats = [];
        $this->view->telegramStatus = (string) $this->request->getQuery('telegram_status', 'string', '');

        try {
            $data = $this->authService()->cabinetData($user);
            $this->view->myProperties = $data['my_properties'] ?? [];
            $this->view->submissions = $data['submissions'];
            $this->view->inboundRequests = $data['inbound_requests'];
            $this->view->telegramBinding = $this->telegramAutomationService()->bindingForUser((int) $user['id']);

            if ($this->authService()->isManager($user)) {
                $this->view->managerWorkspace = $this->managerWorkspace($user);
                $this->view->telegramOutboxStats = $this->notificationOperations()->outboxStats();
            }
        } catch (Throwable $e) {
            $this->logFrontendError('cabinet-page', $e);
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
            $this->response->redirect('https://t.me/' . rawurlencode($username) . '?start=' . rawurlencode((string) $link['token']), true);
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

        $submissionId = (int) ($id ?: $this->dispatcher->getParam('params') ?: $this->dispatcher->getParam('id'));
        $this->view->title = 'Редагування поданого об’єкта';
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
            $this->view->media = $this->di->getShared('mediaStorageService')->assetsFor('property_submission', $submissionId);
        } catch (Throwable $e) {
            $this->logFrontendError('cabinet-submission', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'Редагування заявки тимчасово недоступне.';
        }
    }

    private function managerWorkspace(array $user): array
    {
        $propertyService = $this->propertyMediaService();
        $agents = $propertyService->agents();
        $agent = $this->agentForUser($agents, (string) ($user['email'] ?? ''));
        $scope = [];
        $scopeNotice = null;

        if (!$this->authService()->isAdmin($user) && $agent) {
            $scope['agent_id'] = (int) $agent['id'];
        } elseif (!$this->authService()->isAdmin($user)) {
            $scopeNotice = 'Для цього користувача ще не знайдено картку агента, тому показано загальну чергу об’єктів.';
        }

        $filters = fn(array $extra = []): array => $propertyService->adminFilters(array_merge($scope, $extra));
        $properties = $propertyService->adminProperties($filters(['sort' => 'updated']));
        $overdue = $propertyService->adminProperties($filters(['quality' => 'overdue_action', 'sort' => 'next_action']));
        $noNextAction = $propertyService->adminProperties($filters(['quality' => 'no_next_action', 'sort' => 'updated']));
        $noPhoto = $propertyService->adminProperties($filters(['quality' => 'no_photo', 'sort' => 'updated']));
        $notReady = $propertyService->adminProperties($filters(['quality' => 'not_ready', 'sort' => 'updated']));
        $ready = $propertyService->adminProperties($filters(['quality' => 'ready', 'sort' => 'updated']));

        return [
            'agent' => $agent,
            'scope' => $scope,
            'scope_notice' => $scopeNotice,
            'counts' => [
                'properties' => count($properties),
                'overdue' => count($overdue),
                'no_next_action' => count($noNextAction),
                'no_photo' => count($noPhoto),
                'not_ready' => count($notReady),
                'ready' => count($ready),
            ],
            'attention_properties' => $this->uniqueProperties(array_merge($overdue, $noNextAction, $noPhoto, $notReady), 8),
            'recent_properties' => array_slice($properties, 0, 6),
            'property_groups' => $propertyService->propertyGroups(false),
        ];
    }

    private function agentForUser(array $agents, string $email): ?array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        foreach ($agents as $agent) {
            if (mb_strtolower(trim((string) ($agent['email'] ?? ''))) === $email) {
                return $agent;
            }
        }

        return null;
    }

    private function uniqueProperties(array $properties, int $limit): array
    {
        $seen = [];
        $unique = [];

        foreach ($properties as $property) {
            $id = (int) ($property['id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $unique[] = $property;

            if (count($unique) >= $limit) {
                break;
            }
        }

        return $unique;
    }
}


<?php
declare(strict_types=1);

namespace Modules\Frontend\Controllers;

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
        $this->view->submissions = [];
        $this->view->inboundRequests = [];
        $this->view->managerWorkspace = [];
        $this->view->pageStatus = null;

        try {
            $data = $this->authService()->cabinetData($user);
            $this->view->submissions = $data['submissions'];
            $this->view->inboundRequests = $data['inbound_requests'];

            if ($this->authService()->isManager($user)) {
                $this->view->managerWorkspace = $this->managerWorkspace($user);
            }
        } catch (Throwable $e) {
            $this->logFrontendError('cabinet-page', $e);
            $this->view->pageStatus = 'Дані кабінету тимчасово недоступні. Спробуйте оновити сторінку трохи пізніше.';
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

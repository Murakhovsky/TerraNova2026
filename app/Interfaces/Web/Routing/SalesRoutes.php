<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

final class SalesRoutes
{
    public static function register(RouterInterface $router): void
    {
        $salesApi = static fn (string $action): array => [
            'namespace' => 'Interfaces\\Api\\Controller',
            'module' => 'frontend',
            'controller' => 'sales',
            'action' => $action,
        ];
        $workspaceApi = static fn (string $action): array => [
            'namespace' => 'Interfaces\\Api\\Controller',
            'module' => 'frontend',
            'controller' => 'sales_workspace_actions',
            'action' => $action,
        ];
        $searchApi = static fn (string $action): array => [
            'namespace' => 'Interfaces\\Api\\Controller',
            'module' => 'frontend',
            'controller' => 'sales_workspace_search',
            'action' => $action,
        ];
        $web = static fn (string $action): array => [
            'namespace' => 'Interfaces\\Web\\Controller',
            'module' => 'frontend',
            'controller' => 'sales',
            'action' => $action,
        ];

        // Sales opens on the operational inbox. Dashboard remains a secondary overview.
        $router->addGet('/sales', $web('today'));
        // Search is read-only and intentionally separated from all mutation endpoints.
        $router->addGet('/api/sales/search', $searchApi('search'));

        // Existing manager operations stay in the original Sales API controller.
        $router->addPost('/api/sales/deals/{id:[0-9]+}/quick', $salesApi('quickUpdate'));
        $router->addPost('/api/sales/deals/{id:[0-9]+}/activities', $salesApi('activity'));
        $router->addPost('/api/sales/deals/{id:[0-9]+}/followups', $salesApi('followup'));
        $router->addPost('/api/sales/deals/{id:[0-9]+}/meetings', $salesApi('meeting'));
        $router->addPost('/api/sales/deals/{id:[0-9]+}/owner', $salesApi('owner'));

        // Sales-facing facade over canonical Sales/Kernel mechanisms.
        $router->addPost('/api/sales/deals/{id:[0-9]+}/messages', $workspaceApi('message'));
        $router->addPost('/api/sales/deals/{id:[0-9]+}/activities/{activityId:[0-9]+}/complete', $workspaceApi('completeActivity'));
        $router->addPost('/api/sales/deals/{id:[0-9]+}/activities/{activityId:[0-9]+}/reschedule', $workspaceApi('rescheduleActivity'));

        $router->addPost('/api/sales/leads/{id:[0-9]+}/status', $workspaceApi('leadStatus'));
        $router->addPost('/api/sales/leads/{id:[0-9]+}/owner', $workspaceApi('leadOwner'));
        $router->addPost('/api/sales/leads/{id:[0-9]+}/deal', $workspaceApi('leadDeal'));
        $router->addPost('/api/sales/leads/{id:[0-9]+}/followups', $workspaceApi('leadFollowup'));

        $router->addPost('/api/sales/approvals/{id:[a-f0-9]{32}}/approve', $workspaceApi('approve'));
        $router->addPost('/api/sales/approvals/{id:[a-f0-9]{32}}/reject', $workspaceApi('reject'));
        $router->addPost('/api/sales/actions/{id:[a-f0-9]{32}}/execute', $workspaceApi('execute'));
        $router->addPost('/api/sales/actions/{id:[a-f0-9]{32}}/dismiss', $workspaceApi('dismiss'));
    }
}

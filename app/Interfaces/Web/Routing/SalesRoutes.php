<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

final class SalesRoutes
{
    public static function register(RouterInterface $router): void
    {
        $api = static fn (string $action): array => [
            'namespace' => 'Interfaces\\Api\\Controller',
            'module' => 'frontend',
            'controller' => 'sales',
            'action' => $action,
        ];
        $web = static fn (string $action): array => [
            'namespace' => 'Interfaces\\Web\\Controller',
            'module' => 'frontend',
            'controller' => 'sales',
            'action' => $action,
        ];

        // Sales is an operational workspace: entering the module starts at Today,
        // not at a passive KPI dashboard.
        $router->addGet('/sales', $web('today'));

        $router->addPost('/api/sales/deals/{id:[0-9]+}/quick', $api('quickUpdate'));
        $router->addPost('/api/sales/deals/{id:[0-9]+}/activities', $api('activity'));
        $router->addPost('/api/sales/deals/{id:[0-9]+}/followups', $api('followup'));
        $router->addPost('/api/sales/deals/{id:[0-9]+}/meetings', $api('meeting'));
        $router->addPost('/api/sales/deals/{id:[0-9]+}/owner', $api('owner'));
        $router->addPost('/api/sales/deals/{id:[0-9]+}/messages', $api('message'));
        $router->addPost('/api/sales/deals/{id:[0-9]+}/activities/{activityId:[0-9]+}/complete', $api('completeActivity'));
        $router->addPost('/api/sales/deals/{id:[0-9]+}/activities/{activityId:[0-9]+}/reschedule', $api('rescheduleActivity'));

        $router->addPost('/api/sales/leads/{id:[0-9]+}/status', $api('leadStatus'));
        $router->addPost('/api/sales/leads/{id:[0-9]+}/owner', $api('leadOwner'));
        $router->addPost('/api/sales/leads/{id:[0-9]+}/deal', $api('leadDeal'));
        $router->addPost('/api/sales/leads/{id:[0-9]+}/followups', $api('leadFollowup'));

        $router->addPost('/api/sales/approvals/{id:[a-f0-9]{32}}/approve', $api('approve'));
        $router->addPost('/api/sales/approvals/{id:[a-f0-9]{32}}/reject', $api('reject'));
        $router->addPost('/api/sales/actions/{id:[a-f0-9]{32}}/execute', $api('executeAction'));
        $router->addPost('/api/sales/actions/{id:[a-f0-9]{32}}/dismiss', $api('dismissAction'));
    }
}

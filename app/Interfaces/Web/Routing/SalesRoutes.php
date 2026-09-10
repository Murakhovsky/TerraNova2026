<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

final class SalesRoutes
{
    public static function register(RouterInterface $router): void
    {
        $salesApi = static fn (string $action): array => ['namespace'=>'Interfaces\\Api\\Controller','module'=>'frontend','controller'=>'sales','action'=>$action];
        $workspaceApi = static fn (string $action): array => ['namespace'=>'Interfaces\\Api\\Controller','module'=>'frontend','controller'=>'sales_workspace_actions','action'=>$action];
        $searchApi = static fn (string $action): array => ['namespace'=>'Interfaces\\Api\\Controller','module'=>'frontend','controller'=>'sales_workspace_search','action'=>$action];
        $adminApi = static fn (string $action): array => ['namespace'=>'Interfaces\\Api\\Controller','module'=>'frontend','controller'=>'sales_admin_pipeline','action'=>$action];
        $lifecycleApi = static fn (string $action): array => ['namespace'=>'Interfaces\\Api\\Controller','module'=>'frontend','controller'=>'sales_deal_lifecycle','action'=>$action];
        $web = static fn (string $action): array => ['namespace'=>'Interfaces\\Web\\Controller','module'=>'frontend','controller'=>'sales','action'=>$action];
        $adminWeb = static fn (string $action): array => ['namespace'=>'Interfaces\\Web\\Controller','module'=>'frontend','controller'=>'sales_admin','action'=>$action];

        $router->addGet('/sales', $web('today'));
        $router->addGet('/api/sales/search', $searchApi('search'));
        $router->addPost('/api/sales/deals/{id:[0-9]+}/quick', $salesApi('quickUpdate'));
        $router->addPost('/api/sales/deals/{id:[0-9]+}/activities', $salesApi('activity'));
        $router->addPost('/api/sales/deals/{id:[0-9]+}/followups', $salesApi('followup'));
        $router->addPost('/api/sales/deals/{id:[0-9]+}/meetings', $salesApi('meeting'));
        $router->addPost('/api/sales/deals/{id:[0-9]+}/owner', $salesApi('owner'));
        $router->addGet('/api/sales/pipelines/{id:[A-Za-z0-9_-]{8,64}}/lost-reasons', $lifecycleApi('lostReasons'));
        $router->addPost('/api/sales/deals/{id:[0-9]+}/lost', $lifecycleApi('lose'));
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

        $router->addGet('/sales/admin/pipelines', $adminWeb('pipelines'));
        $router->addGet('/sales/admin/pipelines/{id:[A-Za-z0-9_-]{8,64}}', $adminWeb('pipeline'));
        $router->addGet('/api/sales/admin/pipelines', $adminApi('pipelines'));
        $router->addPost('/api/sales/admin/pipelines', $adminApi('create'));
        $router->addGet('/api/sales/admin/pipelines/{id:[A-Za-z0-9_-]{8,64}}', $adminApi('pipeline'));
        $router->addPost('/api/sales/admin/pipelines/{id:[A-Za-z0-9_-]{8,64}}', $adminApi('update'));
        $router->addPost('/api/sales/admin/pipelines/{id:[A-Za-z0-9_-]{8,64}}/stages', $adminApi('createStage'));
        $router->addPost('/api/sales/admin/stages/{id:[A-Za-z0-9_-]{8,64}}', $adminApi('updateStage'));
        $router->addPost('/api/sales/admin/pipelines/{id:[A-Za-z0-9_-]{8,64}}/stages/reorder', $adminApi('reorder'));
        $router->addPost('/api/sales/admin/pipelines/{id:[A-Za-z0-9_-]{8,64}}/transitions', $adminApi('transitions'));
        $router->addGet('/api/sales/admin/pipelines/{id:[A-Za-z0-9_-]{8,64}}/lost-reasons', $adminApi('lostReasons'));
        $router->addPost('/api/sales/admin/pipelines/{id:[A-Za-z0-9_-]{8,64}}/lost-reasons', $adminApi('createLostReason'));
        $router->addPost('/api/sales/admin/lost-reasons/{id:[A-Za-z0-9_-]{8,64}}', $adminApi('updateLostReason'));
        $router->addGet('/api/sales/admin/pipelines/{id:[A-Za-z0-9_-]{8,64}}/validate', $adminApi('validate'));
    }
}

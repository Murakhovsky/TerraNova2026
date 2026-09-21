<?php

declare(strict_types=1);

namespace App\Web\Sales;

use App\Web\Experience\Action\UIActionPermissionCheckerInterface;
use App\Web\Experience\Action\UIActionPermissionDecision;
use App\Web\Experience\Model\EntityRef;
use Domains\Sales\Application\Contract\SalesAccessControlInterface;
use Domains\Sales\Model\SalesCapability;
use Kernel\Tenant\Model\TenantContext;

final readonly class SalesUIActionPermissionChecker implements UIActionPermissionCheckerInterface
{
    public function __construct(private SalesAccessControlInterface $access)
    {
    }

    public function supports(string $permission): bool
    {
        return in_array($permission, SalesCapability::values(), true);
    }

    public function decide(
        string $permission,
        TenantContext $tenant,
        ?EntityRef $entity = null,
    ): UIActionPermissionDecision {
        if ($tenant->isAdmin()) {
            return UIActionPermissionDecision::allow();
        }

        $userId = $tenant->userId()->value();
        if (!ctype_digit($userId) || (int) $userId <= 0) {
            return UIActionPermissionDecision::deny('Authenticated Sales actor is invalid.');
        }

        return $this->access->hasCapability(
            $tenant->organizationId()->value(),
            (int) $userId,
            $permission,
        )
            ? UIActionPermissionDecision::allow()
            : UIActionPermissionDecision::deny('Required Sales capability is missing.');
    }
}

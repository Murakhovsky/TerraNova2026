<?php

declare(strict_types=1);

namespace App\Web\Experience\Action;

use App\Web\Experience\Model\EntityRef;
use Kernel\Tenant\Model\TenantContext;

final class UIActionPermissionResolver
{
    /** @var list<UIActionPermissionCheckerInterface> */
    private array $checkers;

    /** @param iterable<UIActionPermissionCheckerInterface> $checkers */
    public function __construct(iterable $checkers)
    {
        $this->checkers = is_array($checkers) ? array_values($checkers) : iterator_to_array($checkers, false);
    }

    public function decide(
        ?string $permission,
        TenantContext $tenant,
        ?EntityRef $entity = null,
    ): UIActionPermissionDecision {
        if ($permission === null || trim($permission) === '') {
            return UIActionPermissionDecision::allow();
        }

        foreach ($this->checkers as $checker) {
            if ($checker->supports($permission)) {
                return $checker->decide($permission, $tenant, $entity);
            }
        }

        return UIActionPermissionDecision::deny('No UI permission checker is registered for this action.');
    }
}

<?php
declare(strict_types=1);

namespace Kernel\Identity\Contract;

use Kernel\Identity\Model\AuthenticatedIdentity;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;

interface IdentityResolverInterface
{
    public function resolve(UserId $userId, OrganizationId $organizationId): ?AuthenticatedIdentity;
}

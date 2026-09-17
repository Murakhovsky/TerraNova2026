<?php
declare(strict_types=1);

namespace App\Security;

use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Service\TenantContextFactory;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class SecurityTenantContextProvider implements TenantContextProviderInterface
{
    public function __construct(
        private Security $security,
        private TenantContextFactory $factory,
    ) {
    }

    public function current(): ?TenantContext
    {
        $user = $this->security->getUser();
        if (!$user instanceof LegacySecurityUser) {
            return null;
        }

        return $this->factory->fromIdentity($user->identity());
    }
}

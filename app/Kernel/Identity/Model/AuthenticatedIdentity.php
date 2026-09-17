<?php
declare(strict_types=1);

namespace Kernel\Identity\Model;

use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Shared\Domain\ValueObject;

final readonly class AuthenticatedIdentity extends ValueObject
{
    public function __construct(
        private UserId $userId,
        private OrganizationId $organizationId,
        private OrganizationRole $role,
        private string $email = '',
    ) {
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function organizationId(): OrganizationId
    {
        return $this->organizationId;
    }

    public function role(): OrganizationRole
    {
        return $this->role;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function isManager(): bool
    {
        return $this->role->isManager();
    }

    public function isAdmin(): bool
    {
        return $this->role->isAdmin();
    }
}

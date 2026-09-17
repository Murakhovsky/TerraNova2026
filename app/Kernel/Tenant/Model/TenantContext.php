<?php
declare(strict_types=1);

namespace Kernel\Tenant\Model;

use InvalidArgumentException;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Shared\Domain\ValueObject;

final readonly class TenantContext extends ValueObject
{
    /** @var array<string, Permission> */
    private array $permissions;

    /** @param list<Permission> $permissions */
    public function __construct(
        private UserId $userId,
        private OrganizationId $organizationId,
        private OrganizationRole $role,
        array $permissions = [],
    ) {
        $normalized = [];
        foreach ($permissions as $permission) {
            if (!$permission instanceof Permission) {
                throw new InvalidArgumentException('TenantContext permissions must contain Permission values only.');
            }
            $normalized[$permission->value()] = $permission;
        }
        ksort($normalized);
        $this->permissions = $normalized;
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

    /** @return list<Permission> */
    public function permissions(): array
    {
        return array_values($this->permissions);
    }

    public function allows(Permission|string $permission): bool
    {
        $permission = is_string($permission) ? Permission::fromString($permission) : $permission;

        return isset($this->permissions[$permission->value()]);
    }

    public function belongsTo(OrganizationId $organizationId): bool
    {
        return $this->organizationId->equals($organizationId);
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

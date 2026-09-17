<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Kernel\Identity\Model\AuthenticatedIdentity;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Contract\TenantPermissionResolverInterface;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantPermissions;
use Kernel\Tenant\Service\TenantContextFactory;

function expectTenant(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final readonly class StubTenantPermissionResolver implements TenantPermissionResolverInterface
{
    public function permissionsFor(AuthenticatedIdentity $identity): array
    {
        return [
            Permission::fromString(TenantPermissions::ACCESS),
            Permission::fromString(TenantPermissions::MANAGE),
            Permission::fromString(TenantPermissions::MANAGE),
        ];
    }
}

$identity = new AuthenticatedIdentity(
    UserId::fromString('42'),
    OrganizationId::fromString('org-demo'),
    OrganizationRole::fromString('manager'),
    'manager@example.test',
);

$context = (new TenantContextFactory(new StubTenantPermissionResolver()))->fromIdentity($identity);

expectTenant($context->userId()->value() === '42', 'Tenant context must keep the authenticated actor.');
expectTenant($context->organizationId()->value() === 'org-demo', 'Tenant context must keep the authenticated organization.');
expectTenant($context->role()->value() === 'manager', 'Tenant context must keep the membership role.');
expectTenant($context->isManager(), 'Manager role must remain manager in tenant context.');
expectTenant(!$context->isAdmin(), 'Manager role must not become admin.');
expectTenant($context->belongsTo(OrganizationId::fromString('org-demo')), 'Tenant context must match its organization.');
expectTenant(!$context->belongsTo(OrganizationId::fromString('org-other')), 'Tenant context must reject another organization.');
expectTenant($context->allows(TenantPermissions::ACCESS), 'Base tenant access permission must be resolved.');
expectTenant($context->allows(TenantPermissions::MANAGE), 'Manager permission must be resolved.');
expectTenant(!$context->allows(TenantPermissions::ADMIN), 'Unresolved admin permission must be denied.');
expectTenant(count($context->permissions()) === 2, 'Tenant context must de-duplicate permissions.');

$invalidRejected = false;
try {
    Permission::fromString('bad permission');
} catch (InvalidArgumentException) {
    $invalidRejected = true;
}
expectTenant($invalidRejected, 'Invalid permission names must be rejected.');

echo "Kernel Tenant foundation contract is valid.\n";

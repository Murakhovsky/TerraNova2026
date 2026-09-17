<?php

declare(strict_types=1);

use App\Security\LegacySecurityUser;
use App\Security\TenantPermissionVoter;
use Kernel\Identity\Model\AuthenticatedIdentity;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class VoterTenantContextStub implements TenantContextProviderInterface
{
    public function __construct(private ?TenantContext $context)
    {
    }

    public function current(): ?TenantContext
    {
        return $this->context;
    }
}

function expectVoter(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Tenant voter contract failed: {$message}\n");
        exit(1);
    }
}

$identity = new AuthenticatedIdentity(
    UserId::fromString('42'),
    OrganizationId::fromString('tenant-a'),
    OrganizationRole::fromString('manager'),
    'manager@example.test',
);

$context = new TenantContext(
    $identity->userId(),
    $identity->organizationId(),
    $identity->role(),
    [
        Permission::fromString(TenantPermissions::ACCESS),
        Permission::fromString(TenantPermissions::MANAGE),
    ],
);

$user = new LegacySecurityUser('42|tenant-a', $identity);
$token = new UsernamePasswordToken($user, 'migration_api', $user->getRoles());
$voter = new TenantPermissionVoter(new VoterTenantContextStub($context));

expectVoter(
    $voter->vote($token, OrganizationId::fromString('tenant-a'), [TenantPermissions::MANAGE]) === VoterInterface::ACCESS_GRANTED,
    'manager must be granted manage permission inside own organization',
);
expectVoter(
    $voter->vote($token, OrganizationId::fromString('tenant-b'), [TenantPermissions::MANAGE]) === VoterInterface::ACCESS_DENIED,
    'same permission must be denied across organization boundary',
);
expectVoter(
    $voter->vote($token, OrganizationId::fromString('tenant-a'), [TenantPermissions::ADMIN]) === VoterInterface::ACCESS_DENIED,
    'manager must not inherit tenant admin permission',
);
expectVoter(
    $voter->vote($token, OrganizationId::fromString('tenant-a'), ['sales.deal.write']) === VoterInterface::ACCESS_ABSTAIN,
    'tenant voter must abstain from domain permissions it does not own',
);

$anonymousVoter = new TenantPermissionVoter(new VoterTenantContextStub(null));
expectVoter(
    $anonymousVoter->vote($token, OrganizationId::fromString('tenant-a'), [TenantPermissions::ACCESS]) === VoterInterface::ACCESS_DENIED,
    'missing tenant context must deny tenant permission',
);

echo "Symfony TenantPermissionVoter enforces tenant ownership and base permission boundaries.\n";

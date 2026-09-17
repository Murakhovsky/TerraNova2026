<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use InvalidArgumentException;
use Kernel\Identity\Contract\IdentityResolverInterface;
use Kernel\Identity\Model\AuthenticatedIdentity;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;

function expectIdentity(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class IdentityResolverStub implements IdentityResolverInterface
{
    public function __construct(private readonly AuthenticatedIdentity $identity)
    {
    }

    public function resolve(UserId $userId, OrganizationId $organizationId): ?AuthenticatedIdentity
    {
        if (!$this->identity->userId()->equals($userId)) {
            return null;
        }

        if (!$this->identity->organizationId()->equals($organizationId)) {
            return null;
        }

        return $this->identity;
    }
}

$managerRole = OrganizationRole::fromString(' Manager ');
expectIdentity($managerRole->value() === 'manager', 'Role should normalize to lowercase.');
expectIdentity($managerRole->isManager(), 'Manager role should satisfy manager capability.');
expectIdentity(!$managerRole->isAdmin(), 'Manager role should not imply admin.');

$adminRole = OrganizationRole::fromString('admin');
expectIdentity($adminRole->isManager() && $adminRole->isAdmin(), 'Admin should satisfy manager and admin capabilities.');
expectIdentity(!OrganizationRole::fromString('realtor')->isManager(), 'Non-manager organization roles must stay non-manager.');

try {
    OrganizationRole::fromString('');
    throw new RuntimeException('Empty role must fail.');
} catch (InvalidArgumentException) {
}

$identity = new AuthenticatedIdentity(
    UserId::fromString('1001'),
    OrganizationId::fromString('default'),
    $managerRole,
    'manager@example.test',
);

expectIdentity($identity->userId()->value() === '1001', 'Identity should expose user id.');
expectIdentity($identity->organizationId()->value() === 'default', 'Identity should expose organization id.');
expectIdentity($identity->email() === 'manager@example.test', 'Identity should expose email.');
expectIdentity($identity->isManager() && !$identity->isAdmin(), 'Identity capabilities should delegate to organization role.');

$resolver = new IdentityResolverStub($identity);
expectIdentity($resolver->resolve(UserId::fromString('1001'), OrganizationId::fromString('default'))?->equals($identity) === true, 'Resolver contract should return matching identity.');
expectIdentity($resolver->resolve(UserId::fromString('1002'), OrganizationId::fromString('default')) === null, 'Resolver contract should preserve user boundary.');
expectIdentity($resolver->resolve(UserId::fromString('1001'), OrganizationId::fromString('other')) === null, 'Resolver contract should preserve tenant boundary.');

echo "Kernel Identity foundation contract passed without Symfony kernel.\n";

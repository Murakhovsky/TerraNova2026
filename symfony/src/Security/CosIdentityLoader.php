<?php
declare(strict_types=1);

namespace App\Security;

use Kernel\Identity\Contract\IdentityResolverInterface;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class CosIdentityLoader implements UserProviderInterface
{
    public function __construct(private readonly IdentityResolverInterface $identities) {}

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        [$id, $organization] = array_pad(explode('|', $identifier, 2), 2, '');
        $organization = rawurldecode($organization);
        if (!ctype_digit($id) || (int)$id <= 0 || $organization === '' || preg_match('/^[A-Za-z0-9._:-]{1,190}$/', $organization) !== 1) {
            $this->notFound($identifier);
        }

        $identity = $this->identities->resolve(UserId::fromString($id), OrganizationId::fromString($organization));
        if ($identity === null) {
            $this->notFound($identifier);
        }

        return new CosSecurityUser($identifier, $identity);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof CosSecurityUser) {
            throw new UnsupportedUserException(sprintf('Unsupported user class: %s', $user::class));
        }
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return is_a($class, CosSecurityUser::class, true);
    }

    private function notFound(string $identifier): never
    {
        $error = new UserNotFoundException();
        $error->setUserIdentifier($identifier);
        throw $error;
    }
}

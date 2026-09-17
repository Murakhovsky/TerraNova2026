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

final class LegacyIdentityLoader implements UserProviderInterface
{
    private readonly OrganizationId $organizationId;

    public function __construct(
        private readonly IdentityResolverInterface $identities,
        string $organizationId,
    ) {
        $organizationId = trim($organizationId);
        $this->organizationId = OrganizationId::fromString($organizationId !== '' ? $organizationId : 'default');
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        [$id, $sessionOrganization] = array_pad(explode('|', $identifier, 2), 2, '');
        if (!ctype_digit($id) || (int) $id <= 0) {
            $this->notFound($identifier);
        }

        $sessionOrganization = rawurldecode($sessionOrganization);
        if ($sessionOrganization !== '' && !preg_match('/^[A-Za-z0-9._:-]{1,190}$/', $sessionOrganization)) {
            $this->notFound($identifier);
        }

        if ($sessionOrganization !== '' && !hash_equals($this->organizationId->value(), $sessionOrganization)) {
            $this->notFound($identifier);
        }

        $identity = $this->identities->resolve(
            UserId::fromString($id),
            $this->organizationId,
        );
        if ($identity === null) {
            $this->notFound($identifier);
        }

        return new LegacySecurityUser($identifier, $identity);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof LegacySecurityUser) {
            throw new UnsupportedUserException(sprintf('Unsupported user class: %s', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return is_a($class, LegacySecurityUser::class, true);
    }

    private function notFound(string $identifier): never
    {
        $error = new UserNotFoundException();
        $error->setUserIdentifier($identifier);
        throw $error;
    }
}

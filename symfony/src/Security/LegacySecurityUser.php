<?php

declare(strict_types=1);

namespace App\Security;

use Kernel\Identity\Model\AuthenticatedIdentity;
use Symfony\Component\Security\Core\User\UserInterface;

final class LegacySecurityUser implements UserInterface
{
    public function __construct(
        private readonly string $identifier,
        private readonly AuthenticatedIdentity $identity,
    ) {
    }

    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }

    public function getRoles(): array
    {
        $role = $this->identity->role()->value();
        $roles = ['ROLE_USER'];

        $roles[] = 'ROLE_' . strtoupper((string) preg_replace('/[^a-z0-9_]+/i', '_', $role));

        if ($this->identity->isManager()) {
            $roles[] = 'ROLE_MANAGER';
        }

        if ($this->identity->isAdmin()) {
            $roles[] = 'ROLE_ADMIN';
        }

        return array_values(array_unique($roles));
    }

    public function eraseCredentials(): void
    {
    }

    public function id(): int
    {
        return (int) $this->identity->userId()->value();
    }

    public function organizationId(): string
    {
        return $this->identity->organizationId()->value();
    }

    public function organizationRole(): string
    {
        return $this->identity->role()->value();
    }

    public function email(): string
    {
        return $this->identity->email();
    }

    public function identity(): AuthenticatedIdentity
    {
        return $this->identity;
    }
}

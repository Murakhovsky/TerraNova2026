<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

final class LegacySecurityUser implements UserInterface
{
    public function __construct(
        private readonly string $identifier,
        private readonly int $id,
        private readonly string $organizationId,
        private readonly string $organizationRole,
        private readonly string $email,
    ) {
    }

    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }

    public function getRoles(): array
    {
        $role = strtolower(trim($this->organizationRole));
        $roles = ['ROLE_USER'];

        if ($role !== '') {
            $roles[] = 'ROLE_' . strtoupper((string) preg_replace('/[^a-z0-9_]+/i', '_', $role));
        }

        if (in_array($role, ['manager', 'admin'], true)) {
            $roles[] = 'ROLE_MANAGER';
        }

        if ($role === 'admin') {
            $roles[] = 'ROLE_ADMIN';
        }

        return array_values(array_unique($roles));
    }

    public function eraseCredentials(): void
    {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function organizationId(): string
    {
        return $this->organizationId;
    }

    public function organizationRole(): string
    {
        return $this->organizationRole;
    }

    public function email(): string
    {
        return $this->email;
    }
}

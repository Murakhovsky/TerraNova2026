<?php

declare(strict_types=1);

namespace App\Security;

use PDO;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class LegacyIdentityLoader implements UserProviderInterface
{
    private readonly string $organizationId;

    public function __construct(
        private readonly PDO $connection,
        string $organizationId,
    ) {
        $organizationId = trim($organizationId);
        $this->organizationId = $organizationId !== '' ? $organizationId : 'default';
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        [$id, $sessionOrganization] = array_pad(explode('|', $identifier, 2), 2, '');
        if (!ctype_digit($id) || (int) $id <= 0) {
            $this->notFound($identifier);
        }

        $userId = (int) $id;
        $sessionOrganization = rawurldecode($sessionOrganization);
        if ($sessionOrganization !== '' && !preg_match('/^[A-Za-z0-9._:-]{1,190}$/', $sessionOrganization)) {
            $this->notFound($identifier);
        }

        // During the migration the Operations surface is pinned to one tenant.
        // A legacy session explicitly switched to another tenant must not be
        // accepted here, even if the same user also belongs to this tenant.
        if ($sessionOrganization !== '' && !hash_equals($this->organizationId, $sessionOrganization)) {
            $this->notFound($identifier);
        }

        $statement = $this->connection->prepare(
            "SELECT id, organization_id, email, full_name, status FROM tn_users "
            . "WHERE id = :id AND status = 'active' LIMIT 1"
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user)) {
            $this->notFound($identifier);
        }

        // The migration API is deliberately pinned to COS_ORGANIZATION_ID until
        // dynamic tenant routing moves to Symfony. Authorize only membership in
        // that same tenant so a manager in another organization cannot read it.
        $membership = $this->membership($userId, $this->organizationId);
        if ($membership === null) {
            $this->notFound($identifier);
        }

        return new LegacySecurityUser(
            $identifier,
            $userId,
            (string) $membership['organization_id'],
            (string) $membership['role'],
            (string) ($user['email'] ?? ''),
        );
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

    private function membership(int $userId, string $organizationId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT organization_id, role FROM cos_organization_memberships "
            . "WHERE user_id = :user_id AND organization_id = :organization_id "
            . "AND status = 'ACTIVE' LIMIT 1"
        );
        $statement->execute([
            'user_id' => $userId,
            'organization_id' => $organizationId,
        ]);
        $membership = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($membership) ? $membership : null;
    }

    private function notFound(string $identifier): never
    {
        $error = new UserNotFoundException();
        $error->setUserIdentifier($identifier);
        throw $error;
    }
}

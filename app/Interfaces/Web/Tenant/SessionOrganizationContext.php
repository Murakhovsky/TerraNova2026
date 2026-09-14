<?php
declare(strict_types=1);

namespace Interfaces\Web\Tenant;

use Domains\Identity\Application\Contract\AuthenticatedUserContextInterface;
use Kernel\Tenant\OrganizationContextInterface;
use RuntimeException;

final readonly class SessionOrganizationContext implements OrganizationContextInterface
{
    public function __construct(
        private AuthenticatedUserContextInterface $auth,
        private string $publicOrganizationId,
    ) {
    }

    public function id(): string
    {
        $id = $this->auth->currentOrganizationId();
        if ($id === null && $this->auth->currentUser() !== null) {
            throw new RuntimeException('Authenticated user has no active organization membership.');
        }
        $id ??= $this->publicOrganizationId;
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,39}$/', $id)) {
            throw new RuntimeException('Invalid organization context.');
        }
        return $id;
    }

    public function actorId(): string
    {
        $user = $this->auth->currentUser();
        return isset($user['id']) ? (string) $user['id'] : 'public-web';
    }

    public function isAuthenticated(): bool
    {
        return $this->auth->currentUser() !== null;
    }
}

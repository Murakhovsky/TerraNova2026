<?php
declare(strict_types=1);

namespace Domains\Identity\Application\Contract;

interface AuthenticatedUserContextInterface
{
    public function currentUser(): ?array;

    public function currentOrganizationId(): ?string;

    public function isManager(?array $user = null): bool;

    public function register(array $input): array;

    public function login(array $input): array;

    public function logout(): void;

    public function selectOrganization(string $organizationId): bool;

    public function cabinetData(array $user): array;

    public function isAdmin(?array $user = null): bool;

    public function roleCapabilities(): array;
}

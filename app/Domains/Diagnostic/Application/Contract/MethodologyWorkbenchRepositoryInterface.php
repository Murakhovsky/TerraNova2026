<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Application\Contract;

interface MethodologyWorkbenchRepositoryInterface
{
    /** @return list<array<string,mixed>> */
    public function scenarios(string $organizationId, string $packId, string $version): array;

    /** @return array<string,mixed>|null */
    public function diagnosticRun(string $organizationId, string $sessionId): ?array;

    /** @return array{users:list<array<string,mixed>>,audit:list<array<string,mixed>>} */
    public function permissionMatrix(string $organizationId): array;

    public function setPermissionOverride(
        string $organizationId,
        int $actorUserId,
        int $targetUserId,
        string $permission,
        string $mode,
    ): void;
}

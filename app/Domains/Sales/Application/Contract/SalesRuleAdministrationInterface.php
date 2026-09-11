<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface SalesRuleAdministrationInterface
{
    /** @return array<string, mixed> */
    public function catalog(): array;

    /** @return list<array<string, mixed>> */
    public function rules(string $organizationId): array;

    /** @return array<string, mixed>|null */
    public function rule(string $organizationId, string $ruleId): ?array;

    /** @return array<string, mixed> */
    public function createDraft(string $organizationId, array $input, string $actorId): array;

    /** @return array<string, mixed> */
    public function updateDraft(string $organizationId, string $ruleId, array $input, string $actorId): array;

    /** @return array<string, mixed> */
    public function activate(string $organizationId, string $ruleId, int $version, string $actorId): array;

    /** @return array<string, mixed> */
    public function disable(string $organizationId, string $ruleId, int $version, string $actorId): array;

    /** @return array<string, mixed> */
    public function archive(string $organizationId, string $ruleId, int $version, string $actorId): array;

    /** @return array<string, mixed> */
    public function restoreSystem(string $organizationId, string $ruleId, int $version, string $actorId): array;

    /** @return array<string, mixed> */
    public function dryRun(string $organizationId, string $ruleId): array;

    /** @return list<array<string, mixed>> */
    public function revisions(string $organizationId, string $ruleId, int $limit = 100): array;
}

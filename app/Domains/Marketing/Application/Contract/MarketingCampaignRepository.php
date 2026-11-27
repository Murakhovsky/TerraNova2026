<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;

interface MarketingCampaignRepository
{
    /** @param array<string,mixed> $draft @return array<string,mixed> */
    public function createDraft(string $organizationId, string $ownerId, array $draft, string $idempotencyKey): array;
    /** @return array<string,mixed>|null */
    public function get(string $organizationId, string $campaignId): ?array;
    /** @return list<array<string,mixed>> */
    public function search(string $organizationId, string $query = '', int $limit = 50, int $offset = 0): array;
}

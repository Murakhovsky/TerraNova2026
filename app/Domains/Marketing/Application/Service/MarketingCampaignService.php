<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;

use DomainException;
use Domains\Marketing\Application\Contract\MarketingCampaignRepository;
use Domains\Marketing\Domain\MarketingDraftPolicy;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

final readonly class MarketingCampaignService
{
    public function __construct(
        private MarketingCampaignRepository $campaigns,
        private MarketingDraftPolicy $policy,
    ) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createDraft(TenantContext $actor, array $input, string $idempotencyKey): array
    {
        $this->assertAccess($actor);
        if (!preg_match('/^[A-Za-z0-9_.:-]{8,191}$/D', $idempotencyKey)) {
            throw new DomainException('Stable bounded idempotency key required.');
        }
        return $this->campaigns->createDraft(
            $actor->organizationId()->value(),
            $actor->userId()->value(),
            $this->policy->validate($input),
            $idempotencyKey,
        );
    }

    /** @return array<string,mixed>|null */
    public function get(TenantContext $actor, string $id): ?array
    {
        $this->assertAccess($actor);
        if (!preg_match('/^mkt-[a-f0-9]{24}$/D', $id)) throw new DomainException('Invalid campaign ID.');
        return $this->campaigns->get($actor->organizationId()->value(), $id);
    }

    /** @return list<array<string,mixed>> */
    public function search(TenantContext $actor, string $query = '', int $limit = 50): array
    {
        $this->assertAccess($actor);
        if (mb_strlen($query) > 180 || $limit < 1 || $limit > 100) throw new DomainException('Invalid search scope.');
        return $this->campaigns->search($actor->organizationId()->value(), $query, $limit);
    }

    private function assertAccess(TenantContext $actor): void
    {
        // Current Kernel exposes tenant-level RBAC; granular marketing.* cutover is a separate gate.
        if (!$actor->isManager() || !$actor->allows(TenantPermissions::ACCESS) || !$actor->allows(TenantPermissions::MANAGE)) {
            throw new DomainException('Marketing manager permission required.');
        }
    }
}

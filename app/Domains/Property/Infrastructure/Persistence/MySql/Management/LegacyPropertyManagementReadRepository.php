<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\Persistence\MySql\Management;

use Domains\Property\Application\Contract\PropertyManagementReadRepositoryInterface;
use Domains\Property\Infrastructure\Persistence\MySql\MysqlPropertyManagementRepository;

final readonly class LegacyPropertyManagementReadRepository implements PropertyManagementReadRepositoryInterface
{
    public function __construct(private MysqlPropertyManagementRepository $backend) {}

    public function property(int $id): ?array { return $this->backend->property($id); }
    public function images(int $propertyId): array { return $this->backend->images($propertyId); }
    public function agents(): array { return $this->backend->agents(); }
    public function activities(int $propertyId, int $limit = 20): array { return $this->backend->activities($propertyId, $limit); }
    public function inboundRequests(int $propertyId): array { return $this->backend->inboundRequests($propertyId); }
    public function caseMatches(int $propertyId): array { return $this->backend->caseMatches($propertyId); }
    public function adminFilters(array $query): array { return $this->backend->adminFilters($query); }
    public function adminProperties(array $filters): array { return $this->backend->adminProperties($filters); }
    public function listingProperties(array $filters, array $user): array { return $this->backend->listingProperties($filters, $user); }
    public function adminQualityStats(array $filters): array { return $this->backend->adminQualityStats($filters); }
    public function adminStats(): array { return $this->backend->adminStats(); }
}

<?php
declare(strict_types=1);

namespace Domains\RealEstate\Application\Contract;

use DateTimeImmutable;
use Domains\RealEstate\Domain\BrokerageProcess;
use Domains\RealEstate\Domain\Offer;
use Domains\RealEstate\Domain\Showing;

interface RealEstateRepositoryInterface
{
    public function findCase(string $organizationId, string $caseId): ?BrokerageProcess;
    public function findMatch(string $organizationId, int $opportunityId, string $propertyId): ?BrokerageProcess;
    public function createCase(BrokerageProcess $case, int $actorId): bool;
    public function transitionCase(BrokerageProcess $case, int $actorId, string $expectedStatus): bool;

    /** @return array<string,mixed>|null */
    public function findOffer(string $organizationId, string $offerId): ?array;
    public function createOffer(string $caseId, Offer $offer, int $actorId): bool;

    /** @return array<string,mixed>|null */
    public function findShowing(string $organizationId, string $showingId): ?array;
    public function createShowing(string $caseId, Showing $showing, DateTimeImmutable $scheduledAt, ?string $notes, int $actorId): bool;

    /** @return array<string,mixed>|null */
    public function view(string $organizationId, string $caseId): ?array;
}

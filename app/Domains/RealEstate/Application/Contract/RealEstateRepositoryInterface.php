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
    public function saveCase(BrokerageProcess $case, int $actorId): void;
    public function saveOffer(string $caseId, Offer $offer, int $actorId): void;
    public function saveShowing(string $caseId, Showing $showing, DateTimeImmutable $scheduledAt, ?string $notes, int $actorId): void;

    /** @return array<string,mixed>|null */
    public function view(string $organizationId, string $caseId): ?array;
}

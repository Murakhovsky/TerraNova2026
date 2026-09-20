<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PublicPropertyReadRepositoryInterface
{
    /** @return list<array<string,mixed>> */
    public function search(string $organizationId, array $filters): array;

    public function count(string $organizationId, array $filters): int;

    /** @return array{total:int,price_min:mixed,price_max:mixed,area_avg:mixed} */
    public function stats(string $organizationId, array $filters): array;

    /** @return list<array<string,mixed>> */
    public function featured(string $organizationId, int $limit): array;

    /** @return array<string,mixed>|null */
    public function findBySlug(string $organizationId, string $slug): ?array;

    /** @return list<array<string,mixed>> */
    public function images(string $organizationId, int $propertyId): array;

    /** @return list<array<string,mixed>> */
    public function features(string $organizationId, int $propertyId): array;

    /** @return list<array<string,mixed>> */
    public function related(string $organizationId, array $property, int $limit): array;

    /** @return list<array{code:string}> */
    public function sitemapTypes(string $organizationId): array;

    /** @return list<array{slug:string}> */
    public function sitemapLocations(string $organizationId): array;

    /** @return list<array{location_slug:string,type_code:string}> */
    public function sitemapLandingPairs(string $organizationId): array;

    /** @return list<array{slug:string,updated_at:mixed}> */
    public function sitemapProperties(string $organizationId): array;

    /** @return list<array<string,mixed>> */
    public function grouped(string $organizationId, array $property, int $limit): array;
}

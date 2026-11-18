<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Application\Contract;

interface ProductManagementStoreInterface
{
    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function createProduct(string $organizationId, array $data, string $key, string $actor, string $correlation): array;
    /** @return array<string,mixed>|null */
    public function getProduct(string $organizationId, string $id): ?array;
    /** @return list<array<string,mixed>> */
    public function searchProducts(string $organizationId, string $query, int $limit, int $offset): array;
    /** @return array<string,mixed> */
    public function createLocation(string $organizationId, string $code, string $name, string $key, string $actor, string $correlation): array;
    /** @return array<string,mixed> */
    public function postMovement(string $organizationId, string $variantId, string $locationId, string $delta, string $kind, string $source, string $key, string $actor, string $correlation): array;
    /** @return array<string,mixed> */
    public function stockBalance(string $organizationId, string $variantId, string $locationId): array;
}

<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Application\Contract;

interface ProductManagementStoreInterface
{
    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function createProduct(string $organizationId, array $data, string $key, string $actor, string $correlation): array;
    /** @return array<string,mixed> */
    public function createVariant(string $organizationId,string $productId,string $sku,string $uom,bool $track,string $key,string $actor,string $correlation): array;
    /** @param array<string,mixed> $changes @return array<string,mixed> */
    public function updateVariant(string $org,string $variantId,array $changes,int $expectedRevision,string $key,string $actor,string $correlation):array;
    /** @return array<string,mixed> */
    public function archiveVariant(string $org,string $variantId,int $expectedRevision,string $key,string $actor,string $correlation):array;
    /** @param array<string,mixed> $changes @return array<string,mixed> */
    public function updateProduct(string $organizationId, string $productId, array $changes, int $expectedRevision, string $key, string $actor, string $correlation): array;
    /** @return array<string,mixed> */
    public function archiveProduct(string $organizationId, string $productId, int $expectedRevision, string $key, string $actor, string $correlation): array;
    /** @return list<array<string,mixed>> */
    public function searchStockMovements(string $organizationId, string $variantId, int $limit, int $offset): array;
    /** @return array<string,mixed> */
    public function createCategory(string $org,string $name,string $slug,?string $parentId,string $key,string $actor,string $correlation):array;
    /** @return array<string,mixed> */
    public function assignCategory(string $org,string $productId,string $categoryId,string $key,string $actor,string $correlation):array;
    /** @return list<array<string,mixed>> */
    public function searchCategories(string $org,int $limit,int $offset):array;
    /** @return array<string,mixed>|null */
    public function getProduct(string $organizationId, string $id): ?array;
    /** @return list<array<string,mixed>> */
    public function searchProducts(string $organizationId, string $query, int $limit, int $offset): array;
    /** @return array<string,mixed> */
    public function createLocation(string $organizationId, string $code, string $name, string $key, string $actor, string $correlation): array;
    /** @return array<string,mixed> */
    public function postMovement(string $organizationId, string $variantId, string $locationId, string $delta, string $kind, string $source, string $key, string $actor, string $correlation): array;
    /** @return array<string,mixed> */
    public function transferStock(string $org,string $variantId,string $fromLocation,string $toLocation,string $qty,string $source,string $key,string $actor,string $correlation):array;
    /** @return array<string,mixed> */
    public function stockBalance(string $organizationId, string $variantId, string $locationId): array;
}

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
    /** @return array<string,mixed> */
    public function upsertProductTranslation(string $org,string $productId,string $locale,string $name,?string $description,string $key,string $actor,string $correlation):array;
    /** @param array{file_id:string,storage_key:string,sha256:string,mime_type:string} $file @return array<string,mixed> */
    public function attachMedia(string $org,string $productId,?string $variantId,array $file,string $role,int $sortOrder,string $key,string $actor,string $correlation):array;
    /** @return array<string,mixed> */
    public function detachMedia(string $org,string $mediaId,string $key,string $actor,string $correlation):array;
    /** @return list<array<string,mixed>> */
    public function searchMedia(string $org,string $productId,int $limit,int $offset):array;
    /** @return array<string,mixed>|null */
    public function getProduct(string $organizationId, string $id): ?array;
    /** @return list<array<string,mixed>> */
    public function searchProducts(string $organizationId, string $query, int $limit, int $offset): array;
    /** @return list<array<string,mixed>> */
    public function searchLocations(string $organizationId,string $q,int $limit,int $offset):array;
    /** @return array<string,mixed> */
    public function createLocation(string $organizationId, string $code, string $name, string $key, string $actor, string $correlation): array;
    /** @return array<string,mixed> */
    public function postMovement(string $organizationId, string $variantId, string $locationId, string $delta, string $kind, string $source, string $key, string $actor, string $correlation): array;
    /** @return array<string,mixed> */
    public function transferStock(string $org,string $variantId,string $fromLocation,string $toLocation,string $qty,string $source,string $key,string $actor,string $correlation):array;
    /** @return array<string,mixed> */
    public function reserveStock(string $org,string $variantId,string $locationId,string $qty,string $source,string $key,string $actor,string $correlation):array;
    /** @return array<string,mixed> */
    public function releaseStock(string $org,string $reservationId,string $reason,string $key,string $actor,string $correlation):array;
    /** @return array<string,mixed> */
    public function searchReservations(string $org,string $variantId,string $locationId,?string $reservationId,int $limit,int $offset):array;
    public function applyStockCount(string $org,string $countId,string $variantId,string $locationId,string $counted,int $expectedRevision,string $source,string $key,string $actor,string $correlation):array;
    public function applyCountSession(string $org,string $sessionId,string $location,array $rows,string $source,string $key,string $actor,string $correlation):array;
    public function getCountSession(string $org,string $sessionId):?array;
    public function getStockCount(string $org,string $countId):?array;
    public function stockBalance(string $organizationId, string $variantId, string $locationId): array;
}

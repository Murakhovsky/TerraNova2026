<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Application\Service;

use Domains\ProductManagement\Application\Contract\ProductManagementStoreInterface;
use Domains\ProductManagement\Domain\ProductRules;
use DomainException;

/**
 * Stable business entry point for human/UI, approved workflow, and adapters.
 * Authorization/tenant identity must be resolved by the trusted caller.
 */
final readonly class ProductManagementService
{
    public function __construct(private ProductManagementStoreInterface $store) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createProduct(string $organizationId, array $input, string $key, string $actor, string $correlation): array
    {
        $this->scope($organizationId, $key, $actor, $correlation);
        $name = ProductRules::name((string) ($input['name'] ?? ''));
        $type = ProductRules::productType((string) ($input['type'] ?? 'stock'));
        $sku = ProductRules::sku((string) ($input['sku'] ?? ''));
        $uom = (string) ($input['uom'] ?? 'pcs');
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,15}$/D', $uom)) throw new DomainException('Invalid UOM.');
        $track = (bool) ($input['track_inventory'] ?? in_array($type, ['stock','material'], true));
        if (in_array($type, ['service','digital'], true) && $track) throw new DomainException('Non-stock product cannot track inventory.');
        return $this->store->createProduct($organizationId, [
            'name' => $name, 'type' => $type, 'sku' => $sku,
            'uom' => $uom, 'track_inventory' => $track,
        ], $key, $actor, $correlation);
    }

    /** @return array<string,mixed> */
    public function createVariant(string $org,string $productId,string $sku,string $uom,bool $track,string $key,string $actor,string $correlation): array
    {
        $this->scope($org,$key,$actor,$correlation);
        $sku=ProductRules::sku($sku);
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,15}$/D',$uom)) throw new DomainException('Invalid UOM.');
        return $this->store->createVariant($org,$productId,$sku,$uom,$track,$key,$actor,$correlation);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function updateProduct(string $org, string $productId, array $input, int $expectedRevision, string $key, string $actor, string $correlation): array
    {
        $this->scope($org,$key,$actor,$correlation);
        if (!preg_match('/^[a-f0-9]{32}$/D',$productId) || $expectedRevision<1) throw new DomainException('Invalid Product reference or revision.');
        $changes=[];
        if (array_key_exists('name',$input)) {
            if (!is_string($input['name'])) throw new DomainException('Name must be a string.');
            $changes['name']=ProductRules::name($input['name']);
        }
        foreach(['description'=>20000,'brand'=>190] as $field=>$max) {
            if (array_key_exists($field,$input)) {
                if (!is_string($input[$field]) || mb_strlen($input[$field])>$max) throw new DomainException('Invalid Product text field: '.$field);
                $changes[$field]=trim($input[$field]);
            }
        }
        if ($changes===[]) throw new DomainException('At least one permitted field must change.');
        return $this->store->updateProduct($org,$productId,$changes,$expectedRevision,$key,$actor,$correlation);
    }

    /** @return array<string,mixed> */
    public function archiveProduct(string $org, string $productId, int $expectedRevision, string $key, string $actor, string $correlation): array
    {
        $this->scope($org,$key,$actor,$correlation);
        if (!preg_match('/^[a-f0-9]{32}$/D',$productId) || $expectedRevision<1) throw new DomainException('Invalid Product reference or revision.');
        return $this->store->archiveProduct($org,$productId,$expectedRevision,$key,$actor,$correlation);
    }

    /** @return list<array<string,mixed>> */
    public function stockMovements(string $org, string $variantId, int $limit=30, int $offset=0): array
    {
        $this->tenant($org);
        if (!preg_match('/^[a-f0-9]{32}$/D',$variantId)||$limit<1||$limit>100||$offset<0) {
            throw new DomainException('Invalid stock movement search bounds.');
        }
        return $this->store->searchStockMovements($org,$variantId,$limit,$offset);
    }

    /** @return array<string,mixed> */
    public function createCategory(string $org,string $name,string $slug,?string $parentId,string $key,string $actor,string $correlation):array
    {
        $this->scope($org,$key,$actor,$correlation);
        if ($parentId!==null&&!preg_match('/^[a-f0-9]{32}$/D',$parentId)) throw new DomainException('Invalid parent category.');
        return $this->store->createCategory($org,ProductRules::name($name),ProductRules::slug($slug),
            $parentId,$key,$actor,$correlation);
    }

    /** @return array<string,mixed> */
    public function assignCategory(string $org,string $productId,string $categoryId,string $key,string $actor,string $correlation):array
    {
        $this->scope($org,$key,$actor,$correlation);
        if (!preg_match('/^[a-f0-9]{32}$/D',$productId)||!preg_match('/^[a-f0-9]{32}$/D',$categoryId)) {
            throw new DomainException('Invalid Product or Category ID.');
        }
        return $this->store->assignCategory($org,$productId,$categoryId,$key,$actor,$correlation);
    }

    /** @return list<array<string,mixed>> */
    public function categories(string $org,int $limit=30,int $offset=0):array
    {
        $this->tenant($org);
        if ($limit<1||$limit>100||$offset<0) throw new DomainException('Invalid Category listing bounds.');
        return $this->store->searchCategories($org,$limit,$offset);
    }

    /** @return array<string,mixed>|null */
    public function getProduct(string $organizationId, string $id): ?array
    {
        $this->tenant($organizationId);
        return $this->store->getProduct($organizationId, $id);
    }

    /** @return list<array<string,mixed>> */
    public function searchProducts(string $organizationId, string $q = '', int $limit = 30, int $offset = 0): array
    {
        $this->tenant($organizationId);
        if ($limit < 1 || $limit > 100 || $offset < 0 || mb_strlen($q) > 200) throw new DomainException('Invalid search bounds.');
        return $this->store->searchProducts($organizationId, trim($q), $limit, $offset);
    }

    /** @return array<string,mixed> */
    public function createLocation(string $organizationId, string $code, string $name, string $key, string $actor, string $correlation): array
    {
        $this->scope($organizationId, $key, $actor, $correlation);
        return $this->store->createLocation($organizationId, ProductRules::code($code), ProductRules::name($name), $key, $actor, $correlation);
    }

    /** @return array<string,mixed> */
    public function recordMovement(string $organizationId, string $variantId, string $locationId, string $delta, string $kind, string $source, string $key, string $actor, string $correlation): array
    {
        $this->scope($organizationId, $key, $actor, $correlation);
        // Adjustment is not an approved P0 capability. Do not expose this through
        // generic Domain services until a verified Kernel Action admission exists.
        if ($kind === 'adjustment') throw new DomainException('Adjustment requires approved canonical Action.');
        if (!in_array($kind, ['receipt','issue'], true)) throw new DomainException('Invalid movement type.');
        if ($kind === 'receipt' && str_starts_with($delta, '-')) throw new DomainException('Receipt must add stock.');
        if ($kind === 'issue' && !str_starts_with($delta, '-')) throw new DomainException('Issue must remove stock.');
        if (trim($source) === '' || strlen($source) > 191) throw new DomainException('Documented source_ref required.');
        return $this->store->postMovement($organizationId, $variantId, $locationId, ProductRules::quantity($delta, true), $kind, $source, $key, $actor, $correlation);
    }

    /** @return array<string,mixed> */
    public function balance(string $organizationId, string $variantId, string $locationId): array
    {
        $this->tenant($organizationId);
        return $this->store->stockBalance($organizationId, $variantId, $locationId);
    }

    private function tenant(string $organizationId): void
    {
        if ($organizationId === '' || strlen($organizationId) > 40) throw new DomainException('Trusted tenant context required.');
    }

    private function scope(string $org, string $key, string $actor, string $correlation): void
    {
        $this->tenant($org);
        if ($key==='' || strlen($key)>191 || $actor==='' || strlen($actor)>100 || $correlation==='' || strlen($correlation)>191) {
            throw new DomainException('Idempotency, actor and correlation must satisfy Kernel event bounds.');
        }
    }
}

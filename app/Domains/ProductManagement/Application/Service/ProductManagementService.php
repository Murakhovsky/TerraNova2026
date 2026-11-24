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
    public function updateVariant(string $org,string $variantId,array $input,int $expectedRevision,string $key,string $actor,string $correlation):array
    {
        $this->scope($org,$key,$actor,$correlation);
        if (!preg_match('/^[a-f0-9]{32}$/D',$variantId) || $expectedRevision<1) {
            throw new DomainException('Invalid Variant reference or revision.');
        }
        $changes=[];
        if (array_key_exists('sku',$input)) {
            if (!is_string($input['sku'])) throw new DomainException('SKU must be a string.');
            $changes['sku']=ProductRules::sku($input['sku']);
        }
        if (array_key_exists('uom',$input)) {
            $uom=$input['uom'];
            if (!is_string($uom)||!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,15}$/D',$uom)) {
                throw new DomainException('Invalid UOM.');
            }
            $changes['uom']=$uom;
        }
        if (array_key_exists('barcode',$input)) {
            if (!is_string($input['barcode'])||strlen($input['barcode'])>128
                || ($input['barcode']!==''&&!preg_match('/^[A-Za-z0-9._:\/-]+$/D',$input['barcode']))) {
                throw new DomainException('Invalid Variant barcode.');
            }
            $changes['barcode']=$input['barcode']!==''?$input['barcode']:null;
        }
        if (array_key_exists('options',$input)) {
            $options=$input['options'];
            if (!is_array($options)||!array_is_list($options)||count($options)>12) {
                throw new DomainException('Variant options must be a bounded name/value list.');
            }
            $normal=[];
            foreach($options as $entry) {
                if (!is_array($entry)||array_keys($entry)!==['name','value']) {
                    throw new DomainException('Variant option requires name and value.');
                }
                $name=$entry['name'];$value=$entry['value'];
                if (!is_string($name)||!preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$name)
                    ||!is_string($value)||trim($value)===''||mb_strlen($value)>190
                    ||array_key_exists($name,$normal)) {
                    throw new DomainException('Variant option must be unique and nonempty.');
                }
                $normal[$name]=$value;
            }
            $changes['options_json']=json_encode($normal,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        }
        if ($changes===[]) throw new DomainException('Variant update requires a permitted field.');
        return $this->store->updateVariant($org,$variantId,$changes,$expectedRevision,$key,$actor,$correlation);
    }

    /** @return array<string,mixed> */
    public function archiveVariant(string $org,string $variantId,int $expectedRevision,string $key,string $actor,string $correlation):array
    {
        $this->scope($org,$key,$actor,$correlation);
        if (!preg_match('/^[a-f0-9]{32}$/D',$variantId)||$expectedRevision<1) {
            throw new DomainException('Invalid Variant reference or revision.');
        }
        return $this->store->archiveVariant($org,$variantId,$expectedRevision,$key,$actor,$correlation);
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

    /** @return array<string,mixed> */
    public function upsertProductTranslation(string $org,string $productId,string $locale,string $name,?string $description,string $key,string $actor,string $correlation):array
    {
        $this->scope($org,$key,$actor,$correlation);
        if(!preg_match('/^[a-f0-9]{32}$/D',$productId)
            ||!preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D',$locale)) {
            throw new DomainException('Invalid Product translation target or locale.');
        }
        $name=ProductRules::name($name);
        if($description!==null && mb_strlen($description)>20000) {
            throw new DomainException('Product translation description too long.');
        }
        return $this->store->upsertProductTranslation($org,$productId,$locale,$name,$description,$key,$actor,$correlation);
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

    /** @return list<array<string,mixed>> */
    public function locations(string $org,string $q='',int $limit=30,int $offset=0):array
    {
        $this->tenant($org);
        if ($limit<1||$limit>100||$offset<0||$offset>100000000||mb_strlen($q)>100) {
            throw new DomainException('Invalid location search bounds.');
        }
        return $this->store->searchLocations($org,trim($q),$limit,$offset);
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
    public function transferStock(string $org,string $variantId,string $fromLocation,string $toLocation,string $quantity,string $source,string $key,string $actor,string $correlation):array
    {
        $this->scope($org,$key,$actor,$correlation);
        foreach([$variantId,$fromLocation,$toLocation] as $id) {
            if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new DomainException('Invalid transfer identifier.');
        }
        if($fromLocation===$toLocation)throw new DomainException('Stock transfer needs two distinct locations.');
        if(trim($source)===''||strlen($source)>191)throw new DomainException('Transfer requires a bounded source document.');
        return $this->store->transferStock($org,$variantId,$fromLocation,$toLocation,
            ProductRules::quantity($quantity),$source,$key,$actor,$correlation);
    }

    /** Tenant-authorized manual reservation only. Cross-domain Order ownership is not assumed. */
    public function reserveStock(string $org,string $variantId,string $locationId,string $qty,string $source,string $key,string $actor,string $correlation):array
    {
        $this->scope($org,$key,$actor,$correlation);
        foreach([$variantId,$locationId] as $id) {
            if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new DomainException('Invalid reservation identifier.');
        }
        if (!preg_match('#^manual:[A-Za-z0-9._:/-]{1,150}$#D',$source)) {
            throw new DomainException('Manual reservation requires a distinct manual: document reference.');
        }
        return $this->store->reserveStock($org,$variantId,$locationId,ProductRules::quantity($qty),$source,$key,$actor,$correlation);
    }

    /** Full release only; no automatic expiry or phantom Sales order. */
    public function releaseStock(string $org,string $reservationId,string $reason,string $key,string $actor,string $correlation):array
    {
        $this->scope($org,$key,$actor,$correlation);
        if (!preg_match('/^[a-f0-9]{32}$/D',$reservationId)) throw new DomainException('Invalid reservation identifier.');
        $reason=trim($reason);
        if ($reason===''||strlen($reason)>191||preg_match('/[\\x00-\\x1f]/',$reason)) {
            throw new DomainException('Reservation release requires a bounded reason.');
        }
        return $this->store->releaseStock($org,$reservationId,$reason,$key,$actor,$correlation);
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

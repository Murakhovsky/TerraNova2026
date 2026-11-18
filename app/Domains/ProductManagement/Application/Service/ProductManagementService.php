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
        if (!in_array($kind, ['receipt','issue','adjustment'], true)) throw new DomainException('Invalid movement type.');
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
        if ($organizationId === '' || strlen($organizationId) > 190) throw new DomainException('Trusted tenant context required.');
    }

    private function scope(string $org, string $key, string $actor, string $correlation): void
    {
        $this->tenant($org);
        foreach ([$key,$actor,$correlation] as $value) {
            if ($value === '' || strlen($value) > 191) throw new DomainException('Idempotency, actor and correlation required.');
        }
    }
}

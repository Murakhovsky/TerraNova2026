<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\ReadModel\MySql;

use Domains\Property\Contract\PropertyReferencePort;
use PDO;

final readonly class MysqlPropertyReferencePort implements PropertyReferencePort
{
    public function __construct(private PDO $connection) {}

    public function getPropertyReference(string $organizationId, string|int $reference): ?array
    {
        $where = is_int($reference) || ctype_digit((string) $reference)
            ? 'a.legacy_property_id = :reference'
            : 'a.asset_id = :reference';

        return $this->one('SELECT a.asset_id,a.legacy_property_id,a.kind,a.type_code,a.lifecycle,
                rs.total_area AS residential_total_area, cs.total_area AS commercial_total_area,
                bs.gross_area, ls.land_area
            FROM tn_property_assets a
            LEFT JOIN tn_property_residential_specs rs ON rs.organization_id=a.organization_id AND rs.asset_id=a.asset_id
            LEFT JOIN tn_property_commercial_specs cs ON cs.organization_id=a.organization_id AND cs.asset_id=a.asset_id
            LEFT JOIN tn_property_building_specs bs ON bs.organization_id=a.organization_id AND bs.asset_id=a.asset_id
            LEFT JOIN tn_property_land_specs ls ON ls.organization_id=a.organization_id AND ls.asset_id=a.asset_id
            WHERE a.organization_id=:organization_id AND ' . $where . ' LIMIT 1', [
                'organization_id' => $organizationId,
                'reference' => $reference,
            ]);
    }

    public function getInventorySnapshot(string $organizationId, string $inventoryId): ?array
    {
        return $this->one('SELECT inventory_id,asset_id,transaction_type,status,price_amount,price_currency,price_period,
                available_from,available_until,responsible_party_reference,source_id,updated_at
            FROM tn_property_inventory_items
            WHERE organization_id=:organization_id AND inventory_id=:inventory_id LIMIT 1', [
                'organization_id' => $organizationId,
                'inventory_id' => $inventoryId,
            ]);
    }

    public function findAvailableInventory(string $organizationId, array $criteria = []): array
    {
        $where = ['i.organization_id=:organization_id', 'i.status IN ("available","under_offer")'];
        $params = ['organization_id' => $organizationId];
        if (!empty($criteria['transaction_type'])) {
            $where[] = 'i.transaction_type=:transaction_type';
            $params['transaction_type'] = (string) $criteria['transaction_type'];
        }
        if (!empty($criteria['type_code'])) {
            $where[] = 'a.type_code=:type_code';
            $params['type_code'] = (string) $criteria['type_code'];
        }
        if (isset($criteria['price_max']) && is_numeric($criteria['price_max'])) {
            $where[] = '(i.price_amount IS NULL OR i.price_amount<=:price_max)';
            $params['price_max'] = (float) $criteria['price_max'];
        }

        return $this->all('SELECT i.inventory_id,i.asset_id,i.transaction_type,i.status,i.price_amount,i.price_currency,i.price_period,
                a.kind,a.type_code,a.lifecycle,l.listing_id,l.title,l.slug,l.status AS listing_status
            FROM tn_property_inventory_items i
            INNER JOIN tn_property_assets a ON a.organization_id=i.organization_id AND a.asset_id=i.asset_id
            LEFT JOIN tn_property_listings l ON l.organization_id=i.organization_id AND l.inventory_id=i.inventory_id
                AND l.status IN ("ready","published")
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY i.updated_at DESC LIMIT 200', $params);
    }

    public function getPropertyPresentation(string $organizationId, string|int $reference): ?array
    {
        $property = $this->getPropertyReference($organizationId, $reference);
        if ($property === null) return null;

        $presentation = $this->one('SELECT l.listing_id,l.inventory_id,l.title,l.description,l.presentation_price_amount,
                l.presentation_price_currency,l.slug,l.visibility,l.seo_title,l.seo_description,l.public_features_json,
                i.transaction_type,i.status AS inventory_status,i.price_amount,i.price_currency,i.price_period
            FROM tn_property_inventory_items i
            INNER JOIN tn_property_listings l ON l.organization_id=i.organization_id AND l.inventory_id=i.inventory_id
            WHERE i.organization_id=:organization_id AND i.asset_id=:asset_id
            ORDER BY FIELD(l.status,"published","ready","hidden","draft","expired","archived"), l.updated_at DESC LIMIT 1', [
                'organization_id' => $organizationId,
                'asset_id' => $property['asset_id'],
            ]);

        return $presentation === null ? ['property' => $property, 'inventory' => null, 'listing' => null] : [
            'property' => $property,
            'inventory' => [
                'inventory_id' => $presentation['inventory_id'],
                'transaction_type' => $presentation['transaction_type'],
                'status' => $presentation['inventory_status'],
                'price_amount' => $presentation['price_amount'],
                'price_currency' => $presentation['price_currency'],
                'price_period' => $presentation['price_period'],
            ],
            'listing' => [
                'listing_id' => $presentation['listing_id'],
                'title' => $presentation['title'],
                'description' => $presentation['description'],
                'presentation_price_amount' => $presentation['presentation_price_amount'],
                'presentation_price_currency' => $presentation['presentation_price_currency'],
                'slug' => $presentation['slug'],
                'visibility' => $presentation['visibility'],
                'seo_title' => $presentation['seo_title'],
                'seo_description' => $presentation['seo_description'],
                'public_features_json' => $presentation['public_features_json'],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function all(string $sql, array $params): array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    private function one(string $sql, array $params): ?array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}

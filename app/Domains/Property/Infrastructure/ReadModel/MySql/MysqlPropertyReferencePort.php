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
                a.location_node_id,a.address_id,
                location.name AS location_name,address.formatted_address,
                rs.total_area AS residential_total_area,rs.living_area,rs.rooms,rs.bedrooms,rs.bathrooms,
                cs.total_area AS commercial_total_area,cs.usable_area,cs.ceiling_height,cs.entrances,
                bs.gross_area,bs.floors,bs.built_year,
                ls.land_area,ls.buildable_area
            FROM tn_property_assets a
            LEFT JOIN tn_addresses address ON address.id=a.address_id
            LEFT JOIN tn_location_nodes location ON location.id=COALESCE(a.location_node_id,address.locality_node_id)
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
                a.kind,a.type_code,a.lifecycle,
                l.listing_id,l.title,l.slug,l.status AS listing_status
            FROM tn_property_inventory_items i
            INNER JOIN tn_property_assets a ON a.organization_id=i.organization_id AND a.asset_id=i.asset_id
            LEFT JOIN tn_property_listings l ON l.organization_id=i.organization_id AND l.inventory_id=i.inventory_id
                AND l.status IN ("ready","published")
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY i.updated_at DESC,l.updated_at DESC LIMIT 200', $params);
    }

    public function getPropertyPresentation(string $organizationId, string|int $reference): ?array
    {
        $property = $this->getPropertyReference($organizationId, $reference);
        if ($property === null) return null;

        $inventory = $this->one('SELECT inventory_id,asset_id,transaction_type,status,price_amount,price_currency,price_period,
                available_from,available_until,responsible_party_reference,source_id,updated_at
            FROM tn_property_inventory_items
            WHERE organization_id=:organization_id AND asset_id=:asset_id
            ORDER BY FIELD(status,"available","reserved","under_offer","on_hold","off_market","sold","rented","withdrawn"),updated_at DESC
            LIMIT 1', [
                'organization_id' => $organizationId,
                'asset_id' => $property['asset_id'],
            ]);

        $listing = null;
        if ($inventory !== null) {
            $listing = $this->one('SELECT listing_id,inventory_id,title,description,presentation_price_amount,
                    presentation_price_currency,slug,visibility,seo_title,seo_description,public_features_json,status
                FROM tn_property_listings
                WHERE organization_id=:organization_id AND inventory_id=:inventory_id
                ORDER BY FIELD(status,"published","ready","hidden","draft","expired","archived"),updated_at DESC LIMIT 1', [
                    'organization_id' => $organizationId,
                    'inventory_id' => $inventory['inventory_id'],
                ]);
        }

        return [
            'property' => $property,
            'inventory' => $inventory,
            'listing' => $listing,
        ];
    }

    public function searchPropertyReferences(string $organizationId, string $query, int $limit = 100): array
    {
        $query = trim($query);
        if ($query === '') return [];
        $limit = max(1, min(200, $limit));

        return $this->all('SELECT DISTINCT a.asset_id,a.legacy_property_id
            FROM tn_property_assets a
            LEFT JOIN tn_addresses address ON address.id=a.address_id
            LEFT JOIN tn_location_nodes location ON location.id=COALESCE(a.location_node_id,address.locality_node_id)
            LEFT JOIN tn_property_inventory_items inventory ON inventory.organization_id=a.organization_id AND inventory.asset_id=a.asset_id
            LEFT JOIN tn_property_listings listing ON listing.organization_id=inventory.organization_id AND listing.inventory_id=inventory.inventory_id
            WHERE a.organization_id=:organization_id
              AND (a.asset_id LIKE :query OR a.type_code LIKE :query OR listing.title LIKE :query OR listing.slug LIKE :query
                   OR location.name LIKE :query OR address.formatted_address LIKE :query)
            ORDER BY a.legacy_property_id DESC,a.asset_id
            LIMIT ' . $limit, [
                'organization_id' => $organizationId,
                'query' => '%' . $query . '%',
            ]);
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

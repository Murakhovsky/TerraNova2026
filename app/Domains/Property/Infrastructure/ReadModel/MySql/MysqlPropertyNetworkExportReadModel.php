<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\ReadModel\MySql;

use Domains\Property\Application\Contract\PropertyNetworkExportPort;
use Domains\Property\Network\PropertyNetworkBatch;
use Domains\Property\Network\PropertyNetworkRecord;
use PDO;

final readonly class MysqlPropertyNetworkExportReadModel implements PropertyNetworkExportPort
{
    public function __construct(private PDO $connection) {}

    public function batch(string $organizationId, array $connector, ?string $cursor, int $limit = 200): PropertyNetworkBatch
    {
        $limit = max(1, min(1000, $limit));
        $afterId = ctype_digit((string) $cursor) ? (int) $cursor : 0;
        $statement = $this->connection->prepare('SELECT
                l.id AS cursor_id,l.listing_id,l.title,l.description,l.slug,l.status AS listing_status,l.visibility,
                l.presentation_price_amount,l.presentation_price_currency,l.public_features_json,l.updated_at,
                i.inventory_id,i.transaction_type,i.status AS inventory_status,i.price_amount,i.price_currency,i.price_period,
                a.asset_id,a.kind,a.type_code,a.lifecycle,
                COALESCE(rs.total_area,cs.total_area,bs.gross_area,ls.land_area) AS area_total,
                rs.rooms,rs.bedrooms,rs.bathrooms,ls.land_area,
                location.name AS location_name,address.formatted_address
            FROM tn_property_listings l
            INNER JOIN tn_property_inventory_items i
                ON i.organization_id=l.organization_id AND i.inventory_id=l.inventory_id
            INNER JOIN tn_property_assets a
                ON a.organization_id=i.organization_id AND a.asset_id=i.asset_id
            LEFT JOIN tn_property_residential_specs rs
                ON rs.organization_id=a.organization_id AND rs.asset_id=a.asset_id
            LEFT JOIN tn_property_commercial_specs cs
                ON cs.organization_id=a.organization_id AND cs.asset_id=a.asset_id
            LEFT JOIN tn_property_building_specs bs
                ON bs.organization_id=a.organization_id AND bs.asset_id=a.asset_id
            LEFT JOIN tn_property_land_specs ls
                ON ls.organization_id=a.organization_id AND ls.asset_id=a.asset_id
            LEFT JOIN tn_addresses address ON address.id=a.address_id
            LEFT JOIN tn_location_nodes location ON location.id=COALESCE(a.location_node_id,address.locality_node_id)
            WHERE l.organization_id=:organization_id
              AND l.id>:after_id
              AND l.status IN ("ready","published")
              AND i.status IN ("available","reserved","under_offer")
            ORDER BY l.id ASC
            LIMIT ' . $limit);
        $statement->execute(['organization_id' => $organizationId, 'after_id' => $afterId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $records = [];
        $nextCursor = $cursor;
        foreach ($rows as $row) {
            $nextCursor = (string) $row['cursor_id'];
            $records[] = new PropertyNetworkRecord(
                'property_offer',
                (string) $row['listing_id'],
                [
                    'asset_id' => $row['asset_id'],
                    'kind' => $row['kind'],
                    'type_code' => $row['type_code'],
                    'lifecycle' => $row['lifecycle'],
                    'area_total' => $row['area_total'] !== null ? (float) $row['area_total'] : null,
                    'land_area' => $row['land_area'] !== null ? (float) $row['land_area'] : null,
                    'rooms' => $row['rooms'] !== null ? (float) $row['rooms'] : null,
                    'bedrooms' => $row['bedrooms'] !== null ? (int) $row['bedrooms'] : null,
                    'bathrooms' => $row['bathrooms'] !== null ? (int) $row['bathrooms'] : null,
                    'city' => $row['location_name'],
                    'formatted_address' => $row['formatted_address'],
                    'inventory_id' => $row['inventory_id'],
                    'transaction_type' => $row['transaction_type'],
                    'inventory_status' => $row['inventory_status'],
                    'price_amount' => $row['presentation_price_amount'] ?? $row['price_amount'],
                    'price_currency' => $row['presentation_price_currency'] ?? $row['price_currency'],
                    'price_period' => $row['price_period'],
                    'listing_id' => $row['listing_id'],
                    'title' => $row['title'],
                    'description' => $row['description'],
                    'slug' => $row['slug'],
                    'visibility' => $row['visibility'],
                    'features' => $row['public_features_json'] ? json_decode((string) $row['public_features_json'], true) : null,
                ],
                PropertyNetworkRecord::UPSERT,
                (string) $row['updated_at'],
                (string) $row['updated_at'],
            );
        }

        return new PropertyNetworkBatch($records, $nextCursor, count($rows) === $limit);
    }
}

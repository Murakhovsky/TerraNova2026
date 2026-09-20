<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\Persistence\MySql;

use Domains\Property\Application\Contract\LocationReferenceInterface;
use Domains\Property\Application\Contract\PropertyProjectionInterface;
use PDO;
use RuntimeException;

final readonly class MysqlPropertyProjection implements PropertyProjectionInterface
{
    public function __construct(
        private PDO $connection,
        private LocationReferenceInterface $locations,
    ) {}

    public function sync(string $organizationId, string $assetId): ?int
    {
        $asset = $this->asset($organizationId, $assetId);
        if ($asset === null) return null;

        $inventory = $this->inventory($organizationId, $assetId);
        $listing = $inventory !== null ? $this->listing($organizationId, (string) $inventory['inventory_id']) : null;
        $publication = $listing !== null ? $this->publication($organizationId, (string) $listing['listing_id']) : null;
        $legacyId = $this->legacyId($organizationId, $assetId);
        $typeId = $this->typeId((string) $asset['type_code']);
        $locationId = $this->locationId($asset);
        $title = trim((string) ($listing['title'] ?? '')) ?: $assetId;
        $slug = $this->uniqueSlug(
            trim((string) ($listing['slug'] ?? '')) ?: $this->slug($title),
            $legacyId,
            $organizationId,
            $assetId,
        );
        $features = $this->json($listing['public_features_json'] ?? null);
        $responsible = (string) ($inventory['responsible_party_reference'] ?? '');
        $agentId = preg_match('/^LEGACY:agent:(\d+)$/', $responsible, $matches) ? (int) $matches[1] : null;

        $data = [
            'organization_id' => $organizationId,
            'public_id' => $this->publicId($organizationId, $assetId, $legacyId),
            'slug' => $slug,
            'title' => mb_substr($title, 0, 220),
            'deal_type' => (string) ($inventory['transaction_type'] ?? 'sale'),
            'type_id' => $typeId,
            'status' => $this->status($inventory, $listing, $publication),
            'source_type' => 'own',
            'location_id' => $locationId,
            'agent_id' => $agentId,
            'price_amount' => $inventory['price_amount'] ?? null,
            'price_currency' => strtoupper((string) ($inventory['price_currency'] ?? 'USD')),
            'price_period' => (string) ($inventory['price_period'] ?? 'total'),
            'area_total' => $asset['total_area'] ?? null,
            'area_living' => $asset['living_area'] ?? null,
            'land_area' => $asset['land_area'] ?? null,
            'rooms' => $asset['rooms'] ?? null,
            'floor' => $asset['floor_number'] ?? null,
            'floors' => $asset['floors'] ?? null,
            'built_year' => $asset['built_year'] ?? null,
            'address' => $asset['formatted_address'] ?? null,
            'latitude' => $asset['latitude'] ?? null,
            'longitude' => $asset['longitude'] ?? null,
            'short_description' => $listing !== null ? mb_substr((string) ($listing['description'] ?? ''), 0, 500) : null,
            'description' => $listing['description'] ?? null,
            'features_json' => $listing['public_features_json'] ?? null,
            'visibility' => (string) ($listing['visibility'] ?? 'private'),
            'is_featured' => !empty($features['is_featured']) ? 1 : 0,
            'has_3d_tour' => !empty($features['tour_url']) ? 1 : 0,
            'tour_url' => $this->nullable($features['tour_url'] ?? null),
            'video_url' => $this->nullable($features['video_url'] ?? null),
            'meta_title' => $listing['seo_title'] ?? null,
            'meta_description' => $listing['seo_description'] ?? null,
            'published_at' => $publication['published_at'] ?? null,
        ];

        if ($legacyId === null) {
            $this->exec(
                'INSERT INTO tn_properties (
                    organization_id,public_id,slug,title,deal_type,type_id,status,source_type,location_id,agent_id,
                    price_amount,price_currency,price_period,area_total,area_living,land_area,rooms,floor,floors,built_year,address,latitude,longitude,
                    short_description,description,features_json,visibility,is_featured,has_3d_tour,tour_url,video_url,meta_title,meta_description,published_at
                ) VALUES (
                    :organization_id,:public_id,:slug,:title,:deal_type,:type_id,:status,:source_type,:location_id,:agent_id,
                    :price_amount,:price_currency,:price_period,:area_total,:area_living,:land_area,:rooms,:floor,:floors,:built_year,:address,:latitude,:longitude,
                    :short_description,:description,:features_json,:visibility,:is_featured,:has_3d_tour,:tour_url,:video_url,:meta_title,:meta_description,:published_at
                )',
                $data,
            );
            $legacyId = (int) $this->connection->lastInsertId();
            if ($legacyId <= 0) throw new RuntimeException('Compatibility projection did not create legacy id.');

            $this->exec(
                'UPDATE tn_property_assets
                 SET legacy_property_id=:legacy_property_id
                 WHERE organization_id=:organization_id AND asset_id=:asset_id',
                [
                    'legacy_property_id' => $legacyId,
                    'organization_id' => $organizationId,
                    'asset_id' => $assetId,
                ],
            );
            $this->exec(
                'INSERT INTO tn_property_asset_legacy_links (organization_id,legacy_property_id,asset_id,link_type)
                 VALUES (:organization_id,:legacy_property_id,:asset_id,"projection")
                 ON DUPLICATE KEY UPDATE asset_id=VALUES(asset_id),link_type="projection"',
                [
                    'organization_id' => $organizationId,
                    'legacy_property_id' => $legacyId,
                    'asset_id' => $assetId,
                ],
            );
        } else {
            $data['id'] = $legacyId;
            $this->exec(
                'UPDATE tn_properties SET
                    public_id=:public_id,slug=:slug,title=:title,deal_type=:deal_type,type_id=:type_id,status=:status,
                    source_type=:source_type,location_id=:location_id,agent_id=:agent_id,price_amount=:price_amount,price_currency=:price_currency,
                    price_period=:price_period,area_total=:area_total,area_living=:area_living,land_area=:land_area,rooms=:rooms,floor=:floor,floors=:floors,
                    built_year=:built_year,address=:address,latitude=:latitude,longitude=:longitude,short_description=:short_description,description=:description,
                    features_json=:features_json,visibility=:visibility,is_featured=:is_featured,has_3d_tour=:has_3d_tour,tour_url=:tour_url,video_url=:video_url,
                    meta_title=:meta_title,meta_description=:meta_description,published_at=:published_at,updated_at=NOW()
                 WHERE id=:id AND organization_id=:organization_id LIMIT 1',
                $data,
            );
        }

        $fingerprint = hash('sha256', json_encode(
            [$asset, $inventory, $listing, $publication],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
        $this->exec(
            'INSERT INTO tn_property_compatibility_projection_state (organization_id,asset_id,legacy_property_id,fingerprint,synced_at)
             VALUES (:organization_id,:asset_id,:legacy_property_id,:fingerprint,NOW())
             ON DUPLICATE KEY UPDATE legacy_property_id=VALUES(legacy_property_id),fingerprint=VALUES(fingerprint),synced_at=NOW()',
            [
                'organization_id' => $organizationId,
                'asset_id' => $assetId,
                'legacy_property_id' => $legacyId,
                'fingerprint' => $fingerprint,
            ],
        );

        return $legacyId;
    }

    public function syncOperationalMetadata(string $organizationId, int $legacyPropertyId, array $metadata): void
    {
        if ($legacyPropertyId <= 0) return;
        $allowed = [
            'property_group_id','commission_type','commission_value','sale_priority','min_price_amount',
            'reserved_until','reserved_by_case_id','fixed_client_case_id','manager_note','source_note',
            'status_note','operational_stage','next_action_title','next_action_due_at','next_action_note',
        ];
        $set = [];
        $params = ['id' => $legacyPropertyId, 'organization_id' => $organizationId];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $metadata)) continue;
            $set[] = $field . '=:' . $field;
            $value = $metadata[$field];
            $params[$field] = ($value === '' || $value === 0 || $value === '0')
                && in_array($field, ['property_group_id','reserved_by_case_id','fixed_client_case_id'], true)
                ? null
                : $value;
        }
        if ($set === []) return;
        if (array_key_exists('status_note', $metadata)) $set[] = 'status_changed_at=NOW()';
        $set[] = 'updated_at=NOW()';
        $this->exec(
            'UPDATE tn_properties SET ' . implode(',', $set) . ' WHERE id=:id AND organization_id=:organization_id LIMIT 1',
            $params,
        );
    }

    public function recordActivity(
        string $organizationId,
        int $legacyPropertyId,
        ?int $userId,
        string $activityType,
        string $title,
        ?string $body = null,
        ?string $oldValue = null,
        ?string $newValue = null,
    ): void {
        if ($legacyPropertyId <= 0) return;
        $this->exec(
            'INSERT INTO tn_property_activities (organization_id,property_id,user_id,activity_type,title,body,old_value,new_value)
             VALUES (:organization_id,:property_id,:user_id,:activity_type,:title,:body,:old_value,:new_value)',
            [
                'organization_id' => $organizationId,
                'property_id' => $legacyPropertyId,
                'user_id' => $userId,
                'activity_type' => $activityType,
                'title' => mb_substr($title, 0, 180),
                'body' => $body,
                'old_value' => $oldValue,
                'new_value' => $newValue,
            ],
        );
        $this->exec(
            'UPDATE tn_properties SET updated_at=NOW() WHERE id=:id AND organization_id=:organization_id LIMIT 1',
            ['id' => $legacyPropertyId, 'organization_id' => $organizationId],
        );
    }

    private function asset(string $organizationId, string $assetId): ?array
    {
        return $this->one(
            'SELECT
                a.asset_id,a.kind,a.type_code,a.lifecycle,a.legacy_property_id,
                n.node_type AS location_type,n.name AS location_name,n.country_code,
                p.node_type AS parent_type,p.name AS parent_name,
                g.node_type AS grand_type,g.name AS grand_name,
                ad.formatted_address,gp.latitude,gp.longitude,
                COALESCE(rs.total_area,cs.total_area,bs.gross_area) AS total_area,
                rs.living_area,rs.rooms,rs.floor_number,ls.land_area,bs.floors,bs.built_year
             FROM tn_property_assets a
             LEFT JOIN tn_location_nodes n ON n.id=a.location_node_id
             LEFT JOIN tn_location_nodes p ON p.id=n.parent_id
             LEFT JOIN tn_location_nodes g ON g.id=p.parent_id
             LEFT JOIN tn_addresses ad ON ad.id=a.address_id
             LEFT JOIN tn_geo_points gp ON gp.id=a.geo_point_id
             LEFT JOIN tn_property_residential_specs rs ON rs.organization_id=a.organization_id AND rs.asset_id=a.asset_id
             LEFT JOIN tn_property_land_specs ls ON ls.organization_id=a.organization_id AND ls.asset_id=a.asset_id
             LEFT JOIN tn_property_commercial_specs cs ON cs.organization_id=a.organization_id AND cs.asset_id=a.asset_id
             LEFT JOIN tn_property_building_specs bs ON bs.organization_id=a.organization_id AND bs.asset_id=a.asset_id
             WHERE a.organization_id=:organization_id AND a.asset_id=:asset_id LIMIT 1',
            ['organization_id' => $organizationId, 'asset_id' => $assetId],
        );
    }

    private function inventory(string $organizationId, string $assetId): ?array
    {
        return $this->one(
            'SELECT * FROM tn_property_inventory_items
             WHERE organization_id=:organization_id AND asset_id=:asset_id
             ORDER BY FIELD(status,"available","reserved","under_offer","on_hold","off_market","sold","rented","withdrawn"),updated_at DESC,id DESC
             LIMIT 1',
            ['organization_id' => $organizationId, 'asset_id' => $assetId],
        );
    }

    private function listing(string $organizationId, string $inventoryId): ?array
    {
        return $this->one(
            'SELECT * FROM tn_property_listings
             WHERE organization_id=:organization_id AND inventory_id=:inventory_id
             ORDER BY FIELD(status,"published","ready","draft","hidden","expired","archived"),updated_at DESC,id DESC
             LIMIT 1',
            ['organization_id' => $organizationId, 'inventory_id' => $inventoryId],
        );
    }

    private function publication(string $organizationId, string $listingId): ?array
    {
        return $this->one(
            'SELECT * FROM tn_property_publications
             WHERE organization_id=:organization_id AND listing_id=:listing_id AND channel_code="estatebook"
             LIMIT 1',
            ['organization_id' => $organizationId, 'listing_id' => $listingId],
        );
    }

    private function legacyId(string $organizationId, string $assetId): ?int
    {
        $row = $this->one(
            'SELECT legacy_property_id FROM tn_property_assets
             WHERE organization_id=:organization_id AND asset_id=:asset_id LIMIT 1',
            ['organization_id' => $organizationId, 'asset_id' => $assetId],
        );
        if ($row !== null && (int) ($row['legacy_property_id'] ?? 0) > 0) return (int) $row['legacy_property_id'];

        $row = $this->one(
            'SELECT legacy_property_id FROM tn_property_asset_legacy_links
             WHERE organization_id=:organization_id AND asset_id=:asset_id
             ORDER BY link_type="primary" DESC,created_at LIMIT 1',
            ['organization_id' => $organizationId, 'asset_id' => $assetId],
        );
        return $row !== null && (int) ($row['legacy_property_id'] ?? 0) > 0
            ? (int) $row['legacy_property_id']
            : null;
    }

    private function typeId(string $code): int
    {
        $row = $this->one('SELECT id FROM tn_property_types WHERE code=:code LIMIT 1', ['code' => $code]);
        if ($row !== null) return (int) $row['id'];

        $this->exec(
            'INSERT INTO tn_property_types (code,name_uk,sort_order,is_active) VALUES (:code,:name,999,1)',
            [
                'code' => mb_substr($code, 0, 50),
                'name' => mb_substr(ucfirst(str_replace('_', ' ', $code)), 0, 120),
            ],
        );
        return (int) $this->connection->lastInsertId();
    }

    private function locationId(array $asset): int
    {
        $country = strtoupper((string) ($asset['country_code'] ?? 'UA')) ?: 'UA';
        $city = $this->city($asset);
        $region = $this->region($asset);
        $district = $this->district($asset);

        $row = $this->one(
            'SELECT id FROM tn_locations
             WHERE country_code=:country AND city=:city
               AND ((region IS NULL AND :empty_region=1) OR region=:region)
               AND ((district IS NULL AND :empty_district=1) OR district=:district)
             LIMIT 1',
            [
                'country' => $country,
                'city' => $city,
                'empty_region' => $region === '' ? 1 : 0,
                'region' => $region,
                'empty_district' => $district === '' ? 1 : 0,
                'district' => $district,
            ],
        );
        if ($row !== null) return (int) $row['id'];

        if ($country !== 'UA') {
            throw new RuntimeException('Legacy compatibility projection requires an existing Reference location outside UA.');
        }

        // Reference owns tn_locations. Property asks the explicit Reference port to
        // resolve/materialize the compatibility location instead of writing the table.
        return $this->locations->resolveOrCreate(
            $city,
            $region !== '' ? $region : null,
            $district !== '' ? $district : null,
        );
    }

    private function city(array $asset): string
    {
        foreach ([['location_type','location_name'],['parent_type','parent_name'],['grand_type','grand_name']] as [$typeKey,$nameKey]) {
            if (($asset[$typeKey] ?? null) === 'city' && trim((string) ($asset[$nameKey] ?? '')) !== '') {
                return trim((string) $asset[$nameKey]);
            }
        }
        return trim((string) ($asset['location_name'] ?? '')) ?: 'Unknown';
    }

    private function region(array $asset): string
    {
        foreach ([['location_type','location_name'],['parent_type','parent_name'],['grand_type','grand_name']] as [$typeKey,$nameKey]) {
            if (($asset[$typeKey] ?? null) === 'region' && trim((string) ($asset[$nameKey] ?? '')) !== '') {
                return trim((string) $asset[$nameKey]);
            }
        }
        return '';
    }

    private function district(array $asset): string
    {
        foreach ([['location_type','location_name'],['parent_type','parent_name'],['grand_type','grand_name']] as [$typeKey,$nameKey]) {
            if (($asset[$typeKey] ?? null) === 'district' && trim((string) ($asset[$nameKey] ?? '')) !== '') {
                return trim((string) $asset[$nameKey]);
            }
        }
        return '';
    }

    private function status(?array $inventory, ?array $listing, ?array $publication): string
    {
        $status = (string) ($inventory['status'] ?? '');
        if ($status === 'reserved') return 'reserved';
        if (in_array($status, ['sold','rented'], true)) return 'sold';
        if (in_array($status, ['withdrawn','off_market'], true)) return 'archived';
        if (($publication['state'] ?? null) === 'published' || ($listing['status'] ?? null) === 'published') return 'published';
        return 'draft';
    }

    private function publicId(string $organizationId, string $assetId, ?int $legacyId): string
    {
        if ($legacyId !== null) {
            $row = $this->one(
                'SELECT public_id FROM tn_properties WHERE id=:id AND organization_id=:organization_id LIMIT 1',
                ['id' => $legacyId, 'organization_id' => $organizationId],
            );
            if ($row !== null && trim((string) $row['public_id']) !== '') return (string) $row['public_id'];
        }
        return 'CP-' . strtoupper(substr(sha1($organizationId . ':' . $assetId), 0, 16));
    }

    private function uniqueSlug(string $slug, ?int $legacyId, string $organizationId, string $assetId): string
    {
        $slug = mb_substr($this->slug($slug), 0, 160);
        if ($slug === '') $slug = 'property-' . substr(sha1($assetId), 0, 12);
        $row = $this->one('SELECT id FROM tn_properties WHERE slug=:slug LIMIT 1', ['slug' => $slug]);
        if ($row === null || ($legacyId !== null && (int) $row['id'] === $legacyId)) return $slug;
        return mb_substr($slug, 0, 148) . '-' . substr(sha1($organizationId . ':' . $assetId), 0, 10);
    }

    private function slug(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\pL\pN]+/u', '-', $value) ?? '';
        return trim($value, '-');
    }

    private function json(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function nullable(mixed $value): ?string
    {
        if ($value === null) return null;
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function one(string $sql, array $params): ?array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function exec(string $sql, array $params): void
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
    }
}

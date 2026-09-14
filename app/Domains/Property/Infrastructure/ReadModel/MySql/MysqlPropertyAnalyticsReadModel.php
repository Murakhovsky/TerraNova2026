<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\ReadModel\MySql;

use Domains\Property\Application\Contract\PropertyAnalyticsReadModelInterface;
use PDO;

final readonly class MysqlPropertyAnalyticsReadModel implements PropertyAnalyticsReadModelInterface
{
    public function __construct(private PDO $connection) {}

    public function summary(string $organizationId, int $days = 30): array
    {
        $segments = $this->inventorySegments($organizationId);
        $totalAssets = (int) ($this->one(
            'SELECT COUNT(*) AS total FROM tn_property_assets WHERE organization_id=:organization_id',
            ['organization_id' => $organizationId],
        )['total'] ?? 0);

        $counts = ['available' => 0, 'reserved' => 0, 'sold' => 0];
        $askingByCurrency = [];
        $ppmByCurrency = [];
        $inventoryAge = [];
        $dom = [];

        foreach ($segments as $row) {
            $status = (string) ($row['status'] ?? '');
            if (isset($counts[$status])) $counts[$status]++;
            if (in_array($status, ['available', 'reserved', 'under_offer'], true)) {
                $currency = strtoupper((string) ($row['price_currency'] ?? ''));
                $price = $row['price_amount'] !== null ? (float) $row['price_amount'] : null;
                $area = $row['area_total'] !== null ? (float) $row['area_total'] : null;
                if ($currency !== '' && $price !== null) {
                    $askingByCurrency[$currency][] = $price;
                    if ($area !== null && $area > 0) $ppmByCurrency[$currency][] = $price / $area;
                }
            }
            if ($row['inventory_age_days'] !== null) $inventoryAge[] = (float) $row['inventory_age_days'];
            if ($row['days_on_market'] !== null) $dom[] = (float) $row['days_on_market'];
        }

        $askingAverages = $this->averages($askingByCurrency);
        $ppmAverages = $this->averages($ppmByCurrency);
        $singleCurrency = count($askingAverages) === 1 ? array_key_first($askingAverages) : null;

        return [
            'total_assets' => $totalAssets,
            'available_inventory' => $counts['available'],
            'reserved_inventory' => $counts['reserved'],
            'sold_inventory' => $counts['sold'],
            'average_asking_price' => $singleCurrency !== null ? $askingAverages[$singleCurrency] : null,
            'average_asking_price_currency' => $singleCurrency,
            'average_asking_price_by_currency' => $askingAverages,
            'average_price_per_sqm' => $singleCurrency !== null ? ($ppmAverages[$singleCurrency] ?? null) : null,
            'average_price_per_sqm_by_currency' => $ppmAverages,
            'average_inventory_age_days' => $this->average($inventoryAge),
            'average_days_on_market' => $this->average($dom),
            'changes' => $this->changes($organizationId, $days),
        ];
    }

    public function inventorySegments(string $organizationId): array
    {
        $rows = $this->all('SELECT
                i.inventory_id,i.asset_id,i.transaction_type,i.status,i.price_amount,i.price_currency,i.price_period,
                i.available_from,i.available_until,i.created_at AS inventory_created_at,i.updated_at AS inventory_updated_at,
                a.legacy_property_id,a.kind,a.type_code,a.lifecycle,a.location_node_id,a.address_id,
                address.locality_node_id,address.formatted_address,
                COALESCE(rs.total_area,cs.total_area,bs.gross_area,ls.land_area) AS area_total,
                rs.rooms,rs.bedrooms,rs.bathrooms,
                bs.floors,bs.built_year
            FROM tn_property_inventory_items i
            INNER JOIN tn_property_assets a ON a.organization_id=i.organization_id AND a.asset_id=i.asset_id
            LEFT JOIN tn_addresses address ON address.id=a.address_id
            LEFT JOIN tn_property_residential_specs rs ON rs.organization_id=a.organization_id AND rs.asset_id=a.asset_id
            LEFT JOIN tn_property_commercial_specs cs ON cs.organization_id=a.organization_id AND cs.asset_id=a.asset_id
            LEFT JOIN tn_property_building_specs bs ON bs.organization_id=a.organization_id AND bs.asset_id=a.asset_id
            LEFT JOIN tn_property_land_specs ls ON ls.organization_id=a.organization_id AND ls.asset_id=a.asset_id
            WHERE i.organization_id=:organization_id
            ORDER BY i.updated_at DESC,i.inventory_id', ['organization_id' => $organizationId]);

        if ($rows === []) return [];

        $assetRows = $this->all('SELECT asset_id,kind,type_code FROM tn_property_assets WHERE organization_id=:organization_id', ['organization_id' => $organizationId]);
        $assetKinds = [];
        foreach ($assetRows as $asset) $assetKinds[(string) $asset['asset_id']] = (string) $asset['kind'];

        $relations = $this->all('SELECT source_asset_id,target_asset_id,relation_type
            FROM tn_property_asset_relations
            WHERE organization_id=:organization_id AND relation_type="contains"', ['organization_id' => $organizationId]);
        $parent = [];
        foreach ($relations as $relation) $parent[(string) $relation['target_asset_id']] = (string) $relation['source_asset_id'];

        $nodes = $this->all('SELECT id,parent_id,node_type,canonical_key,name FROM tn_location_nodes', []);
        $locationNodes = [];
        foreach ($nodes as $node) $locationNodes[(int) $node['id']] = $node;

        $publishedRows = $this->all('SELECT l.inventory_id,MIN(p.published_at) AS first_published_at
            FROM tn_property_listings l
            INNER JOIN tn_property_publications p ON p.organization_id=l.organization_id AND p.listing_id=l.listing_id
            WHERE l.organization_id=:organization_id AND p.published_at IS NOT NULL
            GROUP BY l.inventory_id', ['organization_id' => $organizationId]);
        $firstPublished = [];
        foreach ($publishedRows as $row) $firstPublished[(string) $row['inventory_id']] = $row['first_published_at'];

        $soldRows = $this->all('SELECT inventory_id,MIN(effective_at) AS sold_at
            FROM tn_property_inventory_status_history
            WHERE organization_id=:organization_id AND status="sold"
            GROUP BY inventory_id', ['organization_id' => $organizationId]);
        $soldAt = [];
        foreach ($soldRows as $row) $soldAt[(string) $row['inventory_id']] = $row['sold_at'];

        $today = new \DateTimeImmutable('now');
        foreach ($rows as &$row) {
            $assetId = (string) $row['asset_id'];
            $row['development_asset_id'] = $this->ancestorOfKind($assetId, 'development', $parent, $assetKinds);
            $row['building_asset_id'] = $this->ancestorOfKind($assetId, 'building', $parent, $assetKinds);

            $locationNodeId = (int) ($row['location_node_id'] ?: $row['locality_node_id'] ?: 0);
            $location = $this->locality($locationNodeId, $locationNodes);
            $row['location_key'] = $location['canonical_key'] ?? null;
            $row['location_name'] = $location['name'] ?? ($row['formatted_address'] ?? null);
            $row['location_type'] = $location['node_type'] ?? null;

            $area = $row['area_total'] !== null ? (float) $row['area_total'] : null;
            $price = $row['price_amount'] !== null ? (float) $row['price_amount'] : null;
            $row['area_bucket'] = $this->areaBucket($area);
            $row['price_range'] = $this->priceRange($price);
            $row['price_per_sqm'] = ($price !== null && $area !== null && $area > 0) ? round($price / $area, 2) : null;

            $start = $row['available_from'] ?: $row['inventory_created_at'] ?: null;
            $end = $soldAt[(string) $row['inventory_id']] ?? null;
            $row['inventory_age_days'] = $this->daysBetween($start, $end, $today);
            $publishedAt = $firstPublished[(string) $row['inventory_id']] ?? null;
            $row['first_published_at'] = $publishedAt;
            $row['sold_at'] = $end;
            $row['days_on_market'] = $this->daysBetween($publishedAt, $end, $today);
        }
        unset($row);

        return $rows;
    }

    public function stock(string $organizationId): array
    {
        $segments = $this->inventorySegments($organizationId);
        return [
            'location' => $this->group($segments, 'location_key', 'location_name'),
            'type' => $this->group($segments, 'type_code', 'type_code'),
            'development' => $this->group($segments, 'development_asset_id', 'development_asset_id'),
            'building' => $this->group($segments, 'building_asset_id', 'building_asset_id'),
            'price_range' => $this->group($segments, 'price_range', 'price_range'),
            'area' => $this->group($segments, 'area_bucket', 'area_bucket'),
        ];
    }

    public function changes(string $organizationId, int $days = 30): array
    {
        $days = max(1, min(3650, $days));
        $price = $this->one('SELECT COUNT(*) AS total FROM tn_property_inventory_price_history
            WHERE organization_id=:organization_id AND effective_at>=DATE_SUB(NOW(),INTERVAL ' . $days . ' DAY)
              AND event_id IS NOT NULL', ['organization_id' => $organizationId]);
        $status = $this->one('SELECT COUNT(*) AS total FROM tn_property_inventory_status_history
            WHERE organization_id=:organization_id AND effective_at>=DATE_SUB(NOW(),INTERVAL ' . $days . ' DAY)
              AND event_id IS NOT NULL', ['organization_id' => $organizationId]);
        return [
            'price_changes' => (int) ($price['total'] ?? 0),
            'availability_changes' => (int) ($status['total'] ?? 0),
        ];
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function group(array $rows, string $keyField, string $labelField): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row[$keyField] ?? ''));
            if ($key === '') $key = 'unknown';
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'label' => trim((string) ($row[$labelField] ?? '')) ?: $key,
                    'total' => 0,
                    'available' => 0,
                    'reserved' => 0,
                    'sold' => 0,
                    '_prices' => [],
                    '_ppm' => [],
                ];
            }
            $groups[$key]['total']++;
            $status = (string) ($row['status'] ?? '');
            if (isset($groups[$key][$status]) && in_array($status, ['available','reserved','sold'], true)) $groups[$key][$status]++;
            if ($row['price_amount'] !== null) $groups[$key]['_prices'][] = (float) $row['price_amount'];
            if ($row['price_per_sqm'] !== null) $groups[$key]['_ppm'][] = (float) $row['price_per_sqm'];
        }
        foreach ($groups as &$group) {
            $group['average_price'] = $this->average($group['_prices']);
            $group['average_price_per_sqm'] = $this->average($group['_ppm']);
            unset($group['_prices'], $group['_ppm']);
        }
        unset($group);
        usort($groups, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        return array_values($groups);
    }

    /** @param array<string,string> $parent @param array<string,string> $assetKinds */
    private function ancestorOfKind(string $assetId, string $kind, array $parent, array $assetKinds): ?string
    {
        $cursor = $assetId;
        $seen = [];
        for ($i = 0; $i < 20; $i++) {
            if (isset($seen[$cursor])) return null;
            $seen[$cursor] = true;
            if (($assetKinds[$cursor] ?? null) === $kind) return $cursor;
            if (!isset($parent[$cursor])) return null;
            $cursor = $parent[$cursor];
        }
        return null;
    }

    /** @param array<int,array<string,mixed>> $nodes @return array<string,mixed>|null */
    private function locality(int $nodeId, array $nodes): ?array
    {
        $seen = [];
        while ($nodeId > 0 && isset($nodes[$nodeId]) && !isset($seen[$nodeId])) {
            $seen[$nodeId] = true;
            $node = $nodes[$nodeId];
            if (in_array((string) $node['node_type'], ['city','settlement','district','city_district'], true)) return $node;
            $nodeId = (int) ($node['parent_id'] ?? 0);
        }
        return null;
    }

    private function areaBucket(?float $area): string
    {
        if ($area === null) return 'unknown';
        return match (true) {
            $area < 40 => '<40',
            $area < 55 => '40-55',
            $area < 70 => '55-70',
            $area < 90 => '70-90',
            $area < 120 => '90-120',
            default => '120+',
        };
    }

    private function priceRange(?float $price): string
    {
        if ($price === null) return 'unknown';
        return match (true) {
            $price < 50000 => '<50k',
            $price < 100000 => '50-100k',
            $price < 150000 => '100-150k',
            $price < 250000 => '150-250k',
            $price < 500000 => '250-500k',
            default => '500k+',
        };
    }

    private function daysBetween(mixed $start, mixed $end, \DateTimeImmutable $today): ?int
    {
        if ($start === null || trim((string) $start) === '') return null;
        try {
            $from = new \DateTimeImmutable((string) $start);
            $to = ($end !== null && trim((string) $end) !== '') ? new \DateTimeImmutable((string) $end) : $today;
            return max(0, (int) $from->diff($to)->format('%a'));
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,list<float>> $values @return array<string,float> */
    private function averages(array $values): array
    {
        $result = [];
        foreach ($values as $currency => $items) {
            $average = $this->average($items);
            if ($average !== null) $result[$currency] = $average;
        }
        ksort($result);
        return $result;
    }

    /** @param list<float|int> $values */
    private function average(array $values): ?float
    {
        if ($values === []) return null;
        return round(array_sum($values) / count($values), 2);
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

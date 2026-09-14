<?php
declare(strict_types=1);

namespace Domains\Property\Application\Service;

final class PropertyComparableSelector
{
    /**
     * @param array<string,mixed> $target
     * @param list<array<string,mixed>> $inventory
     * @return list<array<string,mixed>>
     */
    public function select(array $target, array $inventory, int $limit = 10): array
    {
        $limit = max(1, min(30, $limit));
        $targetAsset = (string) ($target['asset_id'] ?? '');
        $targetArea = $this->number($target['area_total'] ?? null);
        $targetRooms = $this->number($target['rooms'] ?? null);
        $targetCurrency = strtoupper((string) ($target['price_currency'] ?? ''));
        $result = [];

        foreach ($inventory as $row) {
            if ((string) ($row['asset_id'] ?? '') === $targetAsset) continue;
            if (($row['price_amount'] ?? null) === null || ($row['area_total'] ?? null) === null) continue;
            if ((string) ($row['transaction_type'] ?? '') !== (string) ($target['transaction_type'] ?? '')) continue;
            $currency = strtoupper((string) ($row['price_currency'] ?? ''));
            if ($targetCurrency !== '' && $currency !== $targetCurrency) continue;

            $score = 0.0;
            $reasons = [];
            if (($row['type_code'] ?? null) === ($target['type_code'] ?? null)) { $score += 35; $reasons[] = 'same_type'; }
            if (($row['location_key'] ?? null) !== null && ($row['location_key'] ?? null) === ($target['location_key'] ?? null)) { $score += 25; $reasons[] = 'same_location'; }
            if (($row['development_asset_id'] ?? null) !== null && ($row['development_asset_id'] ?? null) === ($target['development_asset_id'] ?? null)) { $score += 10; $reasons[] = 'same_development'; }
            if ($targetRooms !== null && $this->number($row['rooms'] ?? null) === $targetRooms) { $score += 10; $reasons[] = 'same_rooms'; }

            $area = $this->number($row['area_total'] ?? null);
            if ($targetArea !== null && $targetArea > 0 && $area !== null) {
                $distance = abs($area - $targetArea) / $targetArea;
                $score += max(0.0, 20.0 * (1.0 - min(1.0, $distance / 0.35)));
                if ($distance <= 0.15) $reasons[] = 'similar_area';
            }
            if ($score < 35) continue;

            $result[] = [
                'asset_id' => (string) $row['asset_id'],
                'inventory_id' => $row['inventory_id'] ?? null,
                'similarity_score' => round(min(100, $score), 2),
                'price_amount' => (float) $row['price_amount'],
                'price_currency' => $currency !== '' ? $currency : null,
                'area_total' => (float) $row['area_total'],
                'price_per_sqm' => $row['price_per_sqm'] !== null ? (float) $row['price_per_sqm'] : null,
                'status' => $row['status'] ?? null,
                'reasons' => $reasons,
            ];
        }

        usort($result, static fn (array $a, array $b): int => $b['similarity_score'] <=> $a['similarity_score']);
        return array_slice($result, 0, $limit);
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}

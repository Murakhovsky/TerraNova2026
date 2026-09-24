<?php

declare(strict_types=1);

namespace App\Web\Property;

use App\Web\Property\ViewModel\PropertyMapViewModel;

final class PropertyMapPresenter
{
    /** @param array<string,mixed> $data */
    public function present(array $data, ?string $error = null): PropertyMapViewModel
    {
        $properties = $this->list($data['properties'] ?? null);
        $geo = array_values(array_filter(
            $properties,
            static fn (array $property): bool =>
                is_numeric($property['latitude'] ?? null)
                && is_numeric($property['longitude'] ?? null),
        ));

        $latitudes = array_map(static fn (array $property): float => (float) $property['latitude'], $geo);
        $longitudes = array_map(static fn (array $property): float => (float) $property['longitude'], $geo);
        $minLat = $latitudes !== [] ? min($latitudes) : 0.0;
        $maxLat = $latitudes !== [] ? max($latitudes) : 0.0;
        $minLng = $longitudes !== [] ? min($longitudes) : 0.0;
        $maxLng = $longitudes !== [] ? max($longitudes) : 0.0;
        $latRange = max(0.000001, $maxLat - $minLat);
        $lngRange = max(0.000001, $maxLng - $minLng);

        $points = [];
        foreach ($geo as $property) {
            $lat = (float) $property['latitude'];
            $lng = (float) $property['longitude'];
            if (count($geo) === 1) {
                $x = 50.0;
                $y = 50.0;
            } else {
                $x = 8 + (84 * (($lng - $minLng) / $lngRange));
                $y = 8 + (84 * (($maxLat - $lat) / $latRange));
            }

            $points[] = [
                'id' => (int) ($property['id'] ?? 0),
                'publicId' => (string) ($property['public_id'] ?? ''),
                'title' => trim((string) ($property['title'] ?? '')) ?: 'Об’єкт',
                'city' => (string) ($property['city'] ?? ''),
                'address' => (string) ($property['address'] ?? ''),
                'coordinates' => number_format($lat, 5, '.', '') . ', ' . number_format($lng, 5, '.', ''),
                'price' => (string) ($property['price_label'] ?? 'Ціна за запитом'),
                'href' => (string) ($property['url'] ?? ('/property/show/' . rawurlencode((string) ($property['slug'] ?? '')))),
                'x' => max(4.0, min(96.0, $x)),
                'y' => max(4.0, min(96.0, $y)),
            ];
        }

        $total = (int) (($data['pagination']['total'] ?? null) ?? count($properties));

        return new PropertyMapViewModel(
            points: $points,
            total: max($total, count($properties)),
            mapped: count($points),
            unmapped: max(0, count($properties) - count($points)),
            error: $error,
        );
    }

    /** @return list<array<string,mixed>> */
    private function list(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }
}

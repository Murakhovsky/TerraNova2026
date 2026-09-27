<?php

declare(strict_types=1);

namespace App\Web\Property;

use App\Web\Property\ViewModel\PublicPropertyGroupPresentationViewModel;

final class PublicPropertyGroupPresentationPresenter
{
    /** @param array<string,mixed> $group @param list<array<string,mixed>> $properties */
    public function present(array $group, array $properties, string $baseUrl): PublicPropertyGroupPresentationViewModel
    {
        $slug = (string) ($group['slug'] ?? '');
        $items = [];

        foreach ($properties as $property) {
            if (!is_array($property)) {
                continue;
            }

            $itemSlug = (string) ($property['slug'] ?? '');
            $items[] = [
                'public_id' => (string) ($property['public_id'] ?? ''),
                'title' => (string) ($property['title'] ?? 'Обʼєкт'),
                'short_description' => (string) ($property['short_description'] ?? ''),
                'cover_url' => (string) ($property['cover_url'] ?? ''),
                'deal_label' => $this->dealLabel((string) ($property['deal_type'] ?? '')),
                'type_name' => (string) ($property['type_name'] ?? ''),
                'city' => (string) ($property['city'] ?? ''),
                'area_label' => $this->number($property['area_total'] ?? null),
                'rooms_label' => $this->number($property['rooms'] ?? null),
                'price_label' => $this->price(
                    $property['price_amount'] ?? null,
                    (string) ($property['price_currency'] ?? 'USD'),
                    (string) ($property['price_period'] ?? 'total'),
                ),
                'url' => '/property/presentation/' . rawurlencode($itemSlug),
                'image_count' => (int) ($property['image_count'] ?? 0),
                'is_featured' => (bool) ($property['is_featured'] ?? false),
                'has_3d_tour' => (bool) ($property['has_3d_tour'] ?? false),
                'status' => (string) ($property['status'] ?? ''),
                'price_amount' => is_numeric($property['price_amount'] ?? null) ? (float) $property['price_amount'] : null,
                'price_currency' => (string) ($property['price_currency'] ?? 'USD'),
            ];
        }

        $location = trim(implode(', ', array_filter([
            trim((string) ($group['city'] ?? '')),
            trim((string) ($group['region'] ?? '')),
        ])));
        $address = trim((string) ($group['address'] ?? ''));
        if ($address !== '') {
            $location .= ($location !== '' ? ' · ' : '') . $address;
        }

        return new PublicPropertyGroupPresentationViewModel(
            slug: $slug,
            title: (string) ($group['title'] ?? 'Презентація обʼєктів'),
            description: (string) ($group['description'] ?? ''),
            location: $location,
            properties: $items,
            canonicalUrl: rtrim($baseUrl, '/') . '/property/presentation/' . rawurlencode($slug),
        );
    }

    private function dealLabel(string $dealType): string
    {
        return match ($dealType) {
            'sale' => 'Продаж',
            'rent' => 'Оренда',
            'investment' => 'Інвестиція',
            default => $dealType,
        };
    }

    private function price(mixed $amount, string $currency, string $period): string
    {
        if ($amount === null || $amount === '' || !is_numeric($amount)) {
            return 'Ціна за запитом';
        }

        $suffix = $period === 'month' ? ' / міс.' : ($period === 'day' ? ' / день' : '');

        return number_format((float) $amount, 0, '.', ' ') . ' ' . $currency . $suffix;
    }

    private function number(mixed $value): string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return '';
        }

        return rtrim(rtrim(number_format((float) $value, 1, '.', ' '), '0'), '.');
    }
}

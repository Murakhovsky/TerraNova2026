<?php

declare(strict_types=1);

namespace App\Web\Property;

use App\Web\Property\ViewModel\PublicPropertyCatalogViewModel;

final class PublicPropertyCatalogPresenter
{
    private const DEAL_LABELS = [
        'sale' => 'Продаж',
        'rent' => 'Оренда',
        'investment' => 'Інвестиція',
    ];

    /** @param array<string,mixed> $data */
    public function present(
        array $data,
        string $baseUrl,
        ?string $notice = null,
        ?string $error = null,
        string $pagePath = '/property/catalog',
        string $pageName = 'Каталог нерухомості Terra Nova CLUB',
    ): PublicPropertyCatalogViewModel {
        $filters = $this->array($data['filters'] ?? null);
        $types = $this->list($data['types'] ?? null);
        $locations = $this->list($data['locations'] ?? null);
        $properties = $this->list($data['properties'] ?? null);
        $stats = $this->array($data['stats'] ?? null);
        $pagination = $this->array($data['pagination'] ?? null);

        $activeFilters = [];
        if (($filters['q'] ?? '') !== '') {
            $activeFilters[] = 'Пошук: ' . $filters['q'];
        }
        if (($filters['deal_type'] ?? '') !== '') {
            $activeFilters[] = self::DEAL_LABELS[$filters['deal_type']] ?? (string) $filters['deal_type'];
        }
        foreach ($types as $type) {
            if (($filters['type'] ?? '') === ($type['code'] ?? null)) {
                $activeFilters[] = (string) ($type['name_uk'] ?? $type['code'] ?? '');
            }
        }
        foreach ($locations as $location) {
            if (($filters['location'] ?? '') === ($location['slug'] ?? null)) {
                $activeFilters[] = (string) ($location['city'] ?? $location['slug'] ?? '');
            }
        }
        if (($filters['price_min'] ?? null) !== null || ($filters['price_max'] ?? null) !== null) {
            $activeFilters[] = 'Бюджет';
        }
        if (($filters['area_min'] ?? null) !== null) {
            $activeFilters[] = 'Від ' . $this->number($filters['area_min']) . ' м²';
        }
        if (($filters['rooms_min'] ?? null) !== null) {
            $activeFilters[] = 'Від ' . $this->number($filters['rooms_min']) . ' кімн.';
        }

        $primaryLocation = 'Україна';
        foreach ($locations as $location) {
            if (($filters['location'] ?? '') === ($location['slug'] ?? null)) {
                $primaryLocation = (string) ($location['city'] ?? 'Україна');
                break;
            }
        }

        $itemList = [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $pageName,
            'numberOfItems' => count($properties),
            'itemListElement' => [],
        ];
        foreach ($properties as $index => $property) {
            $itemList['itemListElement'][] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'url' => rtrim($baseUrl, '/') . (string) ($property['url'] ?? ''),
                'name' => (string) ($property['title'] ?? ''),
            ];
        }

        $breadcrumbItems = [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Головна', 'item' => rtrim($baseUrl, '/') . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Каталог', 'item' => rtrim($baseUrl, '/') . '/property/catalog'],
        ];
        if ($pagePath !== '/property/catalog') {
            $breadcrumbItems[] = [
                '@type' => 'ListItem',
                'position' => 3,
                'name' => $pageName,
                'item' => rtrim($baseUrl, '/') . $pagePath,
            ];
        }
        $breadcrumb = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $breadcrumbItems,
        ];

        return new PublicPropertyCatalogViewModel(
            filters: $filters,
            types: $types,
            locations: $locations,
            properties: $properties,
            stats: $stats,
            pagination: $pagination,
            activeFilters: $activeFilters,
            breadcrumbSchema: $breadcrumb,
            itemListSchema: $itemList,
            dealLabel: self::DEAL_LABELS[$filters['deal_type'] ?? ''] ?? 'Усі обʼєкти',
            primaryLocation: $primaryLocation,
            previousUrl: $this->pageUrl($filters, (int) ($pagination['previous_page'] ?? 1), $pagePath),
            nextUrl: $this->pageUrl($filters, (int) ($pagination['next_page'] ?? 1), $pagePath),
            notice: $notice,
            error: $error,
        );
    }

    /** @param array<string,mixed> $filters */
    private function pageUrl(array $filters, int $page, string $pagePath): string
    {
        $query = array_filter([
            'q' => $filters['q'] ?? '',
            'deal_type' => $filters['deal_type'] ?? '',
            'type' => $filters['type'] ?? '',
            'location' => $filters['location'] ?? '',
            'price_min' => $filters['price_min'] ?? null,
            'price_max' => $filters['price_max'] ?? null,
            'area_min' => $filters['area_min'] ?? null,
            'rooms_min' => $filters['rooms_min'] ?? null,
            'sort' => $filters['sort'] ?? '',
            'per_page' => ($filters['per_page'] ?? 60) !== 60 ? ($filters['per_page'] ?? 60) : null,
            'page' => $page > 1 ? $page : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return $pagePath . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    private function number(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return rtrim(rtrim(number_format((float) $value, 1, '.', ' '), '0'), '.');
    }

    /** @return array<string,mixed> */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
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

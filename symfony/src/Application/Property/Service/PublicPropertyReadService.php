<?php
declare(strict_types=1);

namespace App\Application\Property\Service;

use Domains\Property\Application\Contract\PublicPropertyReadRepositoryInterface;

final readonly class PublicPropertyReadService
{
    public function __construct(
        private PublicPropertyReadRepositoryInterface $properties,
        private string $organizationId,
    ) {
    }

    /** @param array<string,mixed> $query @return array<string,mixed> */
    public function catalog(array $query): array
    {
        $filters = $this->filtersFromQuery($query);
        $total = $this->properties->count($this->organizationId, $filters);
        $pagination = $this->pagination($filters, $total);
        $filters['page'] = $pagination['page'];
        $filters['per_page'] = $pagination['per_page'];

        return [
            'filters' => $filters,
            'properties' => array_map(
                [$this, 'propertyCardPayload'],
                $this->properties->search($this->organizationId, $filters),
            ),
            'pagination' => $pagination,
            'stats' => $this->properties->stats($this->organizationId, $filters),
        ];
    }

    /** @return array{properties:list<array<string,mixed>>} */
    public function featured(int $limit): array
    {
        return [
            'properties' => array_map(
                [$this, 'propertyCardPayload'],
                $this->properties->featured($this->organizationId, max(1, min($limit, 12))),
            ),
        ];
    }

    /** @return array<string,mixed>|null */
    public function show(string $slug): ?array
    {
        $property = $this->properties->findBySlug($this->organizationId, $slug);
        if ($property === null) {
            return null;
        }

        return [
            'property' => $this->propertyDetailPayload($property),
            'images' => $this->properties->images($this->organizationId, (int) $property['id']),
            'features' => $this->properties->features($this->organizationId, (int) $property['id']),
            'related' => array_map(
                [$this, 'propertyCardPayload'],
                $this->properties->related($this->organizationId, $property, 3),
            ),
            'grouped' => array_map(
                [$this, 'propertyCardPayload'],
                $this->properties->grouped($this->organizationId, $property, 8),
            ),
        ];
    }

    /** @param array<string,mixed> $query @return array<string,mixed> */
    private function filtersFromQuery(array $query): array
    {
        return [
            'q' => trim((string) ($query['q'] ?? '')),
            'deal_type' => $this->allowed((string) ($query['deal_type'] ?? ''), ['sale', 'rent', 'investment']),
            'type' => trim((string) ($query['type'] ?? '')),
            'location' => trim((string) ($query['location'] ?? '')),
            'price_min' => $this->positiveNumber($query['price_min'] ?? null),
            'price_max' => $this->positiveNumber($query['price_max'] ?? null),
            'area_min' => $this->positiveNumber($query['area_min'] ?? null),
            'rooms_min' => $this->positiveNumber($query['rooms_min'] ?? null),
            'sort' => $this->allowed((string) ($query['sort'] ?? ''), ['newest', 'price_asc', 'price_desc', 'area_desc']),
            'page' => $this->positiveInt($query['page'] ?? null) ?? 1,
            'per_page' => $this->allowedInt($query['per_page'] ?? null, [12, 24, 60], 60),
        ];
    }

    /** @return array{page:int,per_page:int,total:int,total_pages:int,has_previous:bool,has_next:bool,previous_page:int,next_page:int} */
    private function pagination(array $filters, int $total): array
    {
        $perPage = $this->allowedInt($filters['per_page'] ?? null, [12, 24, 60], 60);
        $totalPages = max(1, (int) ceil(max(0, $total) / $perPage));
        $currentPage = min(max(1, (int) ($filters['page'] ?? 1)), $totalPages);

        return [
            'page' => $currentPage,
            'per_page' => $perPage,
            'total' => max(0, $total),
            'total_pages' => $totalPages,
            'has_previous' => $currentPage > 1,
            'has_next' => $currentPage < $totalPages,
            'previous_page' => max(1, $currentPage - 1),
            'next_page' => min($totalPages, $currentPage + 1),
        ];
    }

    /** @return array<string,mixed> */
    private function propertyCardPayload(array $property): array
    {
        return [
            'id' => (int) ($property['id'] ?? 0),
            'public_id' => (string) ($property['public_id'] ?? ''),
            'slug' => (string) ($property['slug'] ?? ''),
            'title' => (string) ($property['title'] ?? ''),
            'url' => '/property/show/' . rawurlencode((string) ($property['slug'] ?? '')),
            'deal_type' => (string) ($property['deal_type'] ?? ''),
            'deal_label' => $this->dealLabel((string) ($property['deal_type'] ?? '')),
            'status' => (string) ($property['status'] ?? ''),
            'source_type' => (string) ($property['source_type'] ?? ''),
            'type_name' => (string) ($property['type_name'] ?? ''),
            'city' => (string) ($property['city'] ?? ''),
            'region' => (string) ($property['region'] ?? ''),
            'address' => (string) ($property['address'] ?? ''),
            'latitude' => is_numeric($property['latitude'] ?? null) ? (float) $property['latitude'] : null,
            'longitude' => is_numeric($property['longitude'] ?? null) ? (float) $property['longitude'] : null,
            'short_description' => (string) ($property['short_description'] ?? ''),
            'cover_url' => (string) ($property['cover_url'] ?? ''),
            'price_amount' => $property['price_amount'] ?? null,
            'price_currency' => (string) ($property['price_currency'] ?? 'USD'),
            'price_period' => (string) ($property['price_period'] ?? 'total'),
            'price_label' => $this->moneyLabel(
                $property['price_amount'] ?? null,
                (string) ($property['price_currency'] ?? 'USD'),
                (string) ($property['price_period'] ?? 'total'),
            ),
            'area_total' => $property['area_total'] ?? null,
            'area_label' => $this->numberLabel($property['area_total'] ?? null),
            'rooms' => $property['rooms'] ?? null,
            'rooms_label' => $this->numberLabel($property['rooms'] ?? null),
            'image_count' => (int) ($property['image_count'] ?? 0),
            'is_featured' => (int) ($property['is_featured'] ?? 0),
            'has_3d_tour' => (int) ($property['has_3d_tour'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private function propertyDetailPayload(array $property): array
    {
        return $this->propertyCardPayload($property) + [
            'description' => (string) ($property['description'] ?? ''),
            'meta_title' => (string) ($property['meta_title'] ?? ''),
            'meta_description' => (string) ($property['meta_description'] ?? ''),
            'land_area' => $property['land_area'] ?? null,
            'area_living' => $property['area_living'] ?? null,
            'group_title' => (string) ($property['group_title'] ?? ''),
            'bedrooms' => $property['bedrooms'] ?? null,
            'bathrooms' => $property['bathrooms'] ?? null,
            'floor' => $property['floor'] ?? null,
            'floors' => $property['floors'] ?? null,
            'built_year' => $property['built_year'] ?? null,
            'tour_url' => (string) ($property['tour_url'] ?? ''),
            'video_url' => (string) ($property['video_url'] ?? ''),
            'agent_name' => (string) ($property['agent_name'] ?? ''),
            'agent_role' => (string) ($property['agent_role'] ?? ''),
            'agent_phone' => (string) ($property['agent_phone'] ?? ''),
            'agent_email' => (string) ($property['agent_email'] ?? ''),
            'agent_telegram' => (string) ($property['agent_telegram'] ?? ''),
            'agent_avatar' => (string) ($property['agent_avatar'] ?? ''),
        ];
    }

    private function allowed(string $value, array $allowed): string
    {
        return in_array($value, $allowed, true) ? $value : '';
    }

    private function positiveNumber(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function allowedInt(mixed $value, array $allowed, int $default): int
    {
        $value = is_numeric($value) ? (int) $value : $default;
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function dealLabel(string $dealType): string
    {
        return ['sale' => 'Продаж', 'rent' => 'Оренда', 'investment' => 'Інвестиція'][$dealType] ?? $dealType;
    }

    private function moneyLabel(mixed $amount, string $currency, string $period): string
    {
        if ($amount === null || $amount === '') {
            return 'Ціна за запитом';
        }

        $suffix = $period === 'month' ? ' / міс.' : ($period === 'day' ? ' / день' : '');
        return number_format((float) $amount, 0, '.', ' ') . ' ' . $currency . $suffix;
    }

    private function numberLabel(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return rtrim(rtrim(number_format((float) $value, 1, '.', ' '), '0'), '.');
    }
}

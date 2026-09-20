<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use Domains\Property\Infrastructure\ReadModel\MySql\CatalogService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

final readonly class PublicPropertyController
{
    public function __construct(private CatalogService $catalog)
    {
    }

    public function catalog(Request $request): JsonResponse
    {
        try {
            $filters = $this->catalog->filtersFromQuery($request->query->all());
            $total = $this->catalog->catalogCount($filters);
            $pagination = $this->catalog->catalogPagination($filters, $total);
            $filters['page'] = $pagination['page'];
            $filters['per_page'] = $pagination['per_page'];

            return $this->ok([
                'filters' => $filters,
                'properties' => array_map([$this, 'propertyCardPayload'], $this->catalog->catalogProperties($filters)),
                'pagination' => $pagination,
                'stats' => $this->catalog->catalogStats($filters),
            ]);
        } catch (Throwable) {
            return $this->error(503, 'property_catalog_unavailable', 'Каталог тимчасово недоступний.');
        }
    }

    public function featured(Request $request): JsonResponse
    {
        try {
            $limit = max(1, min((int) $request->query->get('limit', 4), 12));

            return $this->ok([
                'properties' => array_map([$this, 'propertyCardPayload'], $this->catalog->featuredProperties($limit)),
            ]);
        } catch (Throwable) {
            return $this->error(503, 'property_featured_unavailable', 'Об’єкти тимчасово недоступні.');
        }
    }

    public function show(string $slug): JsonResponse
    {
        try {
            $property = $this->catalog->propertyBySlug($slug);
            if ($property === null || !in_array((string) ($property['status'] ?? ''), ['published', 'active'], true)) {
                return $this->error(404, 'property_not_found', 'Об’єкт не знайдено.');
            }

            return $this->ok([
                'property' => $this->propertyDetailPayload($property),
                'images' => $this->catalog->propertyImages((int) $property['id']),
                'features' => $this->catalog->propertyFeatures((int) $property['id']),
                'related' => array_map([$this, 'propertyCardPayload'], $this->catalog->relatedProperties($property)),
                'grouped' => array_map([$this, 'propertyCardPayload'], $this->catalog->groupedProperties($property)),
            ]);
        } catch (Throwable) {
            return $this->error(503, 'property_read_unavailable', 'Сторінка об’єкта тимчасово недоступна.');
        }
    }

    private function ok(array $data): JsonResponse
    {
        return new JsonResponse(['ok' => true, 'data' => $data]);
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $code, 'message' => $message], $status);
    }

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
            'short_description' => (string) ($property['short_description'] ?? ''),
            'cover_url' => (string) ($property['cover_url'] ?? ''),
            'price_amount' => $property['price_amount'] ?? null,
            'price_currency' => (string) ($property['price_currency'] ?? 'USD'),
            'price_period' => (string) ($property['price_period'] ?? 'total'),
            'price_label' => $this->moneyLabel($property['price_amount'] ?? null, (string) ($property['price_currency'] ?? 'USD'), (string) ($property['price_period'] ?? 'total')),
            'area_total' => $property['area_total'] ?? null,
            'area_label' => $this->numberLabel($property['area_total'] ?? null),
            'rooms' => $property['rooms'] ?? null,
            'rooms_label' => $this->numberLabel($property['rooms'] ?? null),
            'image_count' => (int) ($property['image_count'] ?? 0),
            'is_featured' => (int) ($property['is_featured'] ?? 0),
            'has_3d_tour' => (int) ($property['has_3d_tour'] ?? 0),
        ];
    }

    private function propertyDetailPayload(array $property): array
    {
        return $this->propertyCardPayload($property) + [
            'description' => (string) ($property['description'] ?? ''),
            'meta_title' => (string) ($property['meta_title'] ?? ''),
            'meta_description' => (string) ($property['meta_description'] ?? ''),
            'land_area' => $property['land_area'] ?? null,
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

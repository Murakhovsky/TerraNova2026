<?php
declare(strict_types=1);

namespace Interfaces\Web\Controller;

use Throwable;

class ApiController extends ControllerBase
{
    public function catalogAction(): \Phalcon\Http\ResponseInterface
    {
        $this->view->disable();

        try {
            $filters = $this->catalogService()->filtersFromQuery((array) $this->request->getQuery());
            $total = $this->catalogService()->catalogCount($filters);
            $pagination = $this->catalogService()->catalogPagination($filters, $total);
            $filters['page'] = $pagination['page'];
            $filters['per_page'] = $pagination['per_page'];

            return $this->json([
                'ok' => true,
                'data' => [
                    'filters' => $filters,
                    'properties' => array_map([$this, 'propertyCardPayload'], $this->catalogService()->catalogProperties($filters)),
                    'pagination' => $pagination,
                    'stats' => $this->catalogService()->catalogStats($filters),
                ],
            ]);
        } catch (Throwable $e) {
            $this->logFrontendError('api-catalog', $e);

            return $this->json([
                'ok' => false,
                'message' => 'Каталог тимчасово недоступний.',
            ], 503);
        }
    }

    public function featuredAction(): \Phalcon\Http\ResponseInterface
    {
        $this->view->disable();

        try {
            $limit = max(1, min((int) $this->request->getQuery('limit', 'int', 4), 12));

            return $this->json([
                'ok' => true,
                'data' => [
                    'properties' => array_map([$this, 'propertyCardPayload'], $this->catalogService()->featuredProperties($limit)),
                ],
            ]);
        } catch (Throwable $e) {
            $this->logFrontendError('api-featured', $e);

            return $this->json([
                'ok' => false,
                'message' => 'Об’єкти тимчасово недоступні.',
            ], 503);
        }
    }

    public function showAction(?string $slug = null): \Phalcon\Http\ResponseInterface
    {
        $this->view->disable();
        $slug = $slug ?: (string) $this->dispatcher->getParam('params');

        try {
            $property = $this->catalogService()->propertyBySlug($slug);
            if (!$property) {
                return $this->json([
                    'ok' => false,
                    'message' => 'Об’єкт не знайдено.',
                ], 404);
            }

            return $this->json([
                'ok' => true,
                'data' => [
                    'property' => $this->propertyDetailPayload($property),
                    'images' => $this->catalogService()->propertyImages((int) $property['id']),
                    'features' => $this->catalogService()->propertyFeatures((int) $property['id']),
                    'related' => array_map([$this, 'propertyCardPayload'], $this->catalogService()->relatedProperties($property)),
                    'grouped' => array_map([$this, 'propertyCardPayload'], $this->catalogService()->groupedProperties($property)),
                ],
            ]);
        } catch (Throwable $e) {
            $this->logFrontendError('api-property-show', $e);

            return $this->json([
                'ok' => false,
                'message' => 'Сторінка об’єкта тимчасово недоступна.',
            ], 503);
        }
    }

    private function propertyCardPayload(array $property): array
    {
        return [
            'id' => (int) ($property['id'] ?? 0),
            'public_id' => (string) ($property['public_id'] ?? ''),
            'slug' => (string) ($property['slug'] ?? ''),
            'title' => (string) ($property['title'] ?? ''),
            'url' => $this->url->get('property/show/' . (string) ($property['slug'] ?? '')),
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
        return [
            'sale' => 'Продаж',
            'rent' => 'Оренда',
            'investment' => 'Інвестиція',
        ][$dealType] ?? $dealType;
    }

    private function moneyLabel(mixed $amount, string $currency = 'USD', string $period = 'total'): string
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


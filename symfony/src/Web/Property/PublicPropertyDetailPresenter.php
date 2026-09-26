<?php

declare(strict_types=1);

namespace App\Web\Property;

use App\Web\Property\ViewModel\PublicPropertyDetailViewModel;

final class PublicPropertyDetailPresenter
{
    /** @param array<string,mixed> $data */
    public function present(
        array $data,
        string $baseUrl,
        ?string $notice = null,
        ?string $error = null,
    ): PublicPropertyDetailViewModel {
        $property = $this->array($data['property'] ?? null);
        $images = [];
        foreach ($this->list($data['images'] ?? null) as $image) {
            $url = trim((string) ($image['image_url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $images[] = [
                'url' => $url,
                'alt' => trim((string) ($image['alt_text'] ?? '')) ?: (string) ($property['title'] ?? 'Terra Nova CLUB'),
            ];
        }

        $coverUrl = $images[0]['url'] ?? trim((string) ($property['cover_url'] ?? ''));
        $dealLabel = (string) ($property['deal_label'] ?? $property['deal_type'] ?? '');
        $locationLabel = trim((string) ($property['city'] ?? ''));
        $region = trim((string) ($property['region'] ?? ''));
        if ($region !== '') {
            $locationLabel .= ($locationLabel !== '' ? ', ' : '') . $region;
        }

        $planning = array_values(array_filter([
            ($property['area_total'] ?? null) ? $this->number($property['area_total']) . ' м²' : null,
            ($property['rooms'] ?? null) ? $this->number($property['rooms']) . ' кімн.' : null,
            ($property['bedrooms'] ?? null) ? (int) $property['bedrooms'] . ' спал.' : null,
        ]));

        $summaryFacts = array_values(array_filter([
            [
                'label' => 'Формат',
                'value' => trim(((string) ($property['type_name'] ?? 'Обʼєкт')) . ($dealLabel !== '' ? ' / ' . $dealLabel : '')),
            ],
            $locationLabel !== '' ? ['label' => 'Локація', 'value' => $locationLabel] : null,
            $planning !== [] ? ['label' => 'Планування', 'value' => implode(' / ', $planning)] : null,
            ($property['floor'] ?? null) || ($property['floors'] ?? null)
                ? ['label' => 'Поверх', 'value' => (string) ($property['floor'] ?: '-') . ' / ' . (string) ($property['floors'] ?: '-')]
                : null,
        ]));

        $characteristicGroups = [];
        foreach ([
            'Основне' => [
                ['label' => 'Тип', 'value' => (string) ($property['type_name'] ?? '')],
                ['label' => 'Операція', 'value' => $dealLabel],
                ['label' => 'Ціна', 'value' => (string) ($property['price_label'] ?? 'Ціна за запитом')],
                ['label' => 'ID', 'value' => (string) ($property['public_id'] ?? '')],
            ],
            'Площа і планування' => [
                ['label' => 'Загальна площа', 'value' => ($property['area_total'] ?? null) ? $this->number($property['area_total']) . ' м²' : ''],
                ['label' => 'Житлова площа', 'value' => ($property['area_living'] ?? null) ? $this->number($property['area_living']) . ' м²' : ''],
                ['label' => 'Кімнати', 'value' => ($property['rooms'] ?? null) ? $this->number($property['rooms']) : ''],
                ['label' => 'Спальні', 'value' => ($property['bedrooms'] ?? null) ? (string) $property['bedrooms'] : ''],
                ['label' => 'Санвузли', 'value' => ($property['bathrooms'] ?? null) ? (string) $property['bathrooms'] : ''],
            ],
            'Будинок / ділянка' => [
                ['label' => 'Поверх', 'value' => ($property['floor'] ?? null) || ($property['floors'] ?? null)
                    ? (string) ($property['floor'] ?: '-') . ' / ' . (string) ($property['floors'] ?: '-')
                    : ''],
                ['label' => 'Рік побудови', 'value' => ($property['built_year'] ?? null) ? (string) $property['built_year'] : ''],
                ['label' => 'Ділянка', 'value' => ($property['land_area'] ?? null) ? $this->number($property['land_area']) . ' сот.' : ''],
            ],
            'Медіа' => [
                ['label' => 'Фото', 'value' => $images !== [] ? count($images) . ' фото' : 'Фото готуються'],
                ['label' => '3D-тур', 'value' => ((int) ($property['has_3d_tour'] ?? 0) === 1 || (string) ($property['tour_url'] ?? '') !== '') ? 'Є' : ''],
                ['label' => 'Відео', 'value' => (string) ($property['video_url'] ?? '') !== '' ? 'Є' : ''],
            ],
            'Локація' => [
                ['label' => 'Місто', 'value' => $locationLabel],
                ['label' => 'Адреса', 'value' => (string) ($property['address'] ?? '')],
                ['label' => 'Група', 'value' => (string) ($property['group_title'] ?? '')],
            ],
        ] as $title => $items) {
            $items = array_values(array_filter(
                $items,
                static fn (array $item): bool => trim($item['value']) !== '',
            ));
            if ($items !== []) {
                $characteristicGroups[] = ['title' => $title, 'items' => $items];
            }
        }

        $features = [];
        foreach ($this->list($data['features'] ?? null) as $feature) {
            $label = trim((string) ($feature['name'] ?? $feature['feature_name'] ?? $feature['label'] ?? ''));
            $value = trim((string) ($feature['value'] ?? $feature['feature_value'] ?? ''));
            if ($label === '' && $value === '') {
                continue;
            }
            $features[] = [
                'label' => $label !== '' ? $label : 'Особливість',
                'value' => $value !== '' ? $value : 'Є',
            ];
        }

        $fitHighlights = array_values(array_filter([
            ($property['price_amount'] ?? null)
                ? 'Є зрозумілий бюджет і валюта для швидкого порівняння.'
                : 'Ціну варто уточнити перед плануванням перегляду.',
            $locationLabel !== '' ? 'Локація винесена окремо, щоб швидко оцінити район і маршрут.' : null,
            $planning !== [] ? 'Площа та кімнати вже зібрані в короткий підсумок.' : null,
            ((int) ($property['has_3d_tour'] ?? 0) === 1
                || (string) ($property['tour_url'] ?? '') !== ''
                || (string) ($property['video_url'] ?? '') !== '')
                ? 'Можна почати з дистанційного знайомства з обʼєктом.'
                : null,
        ]));

        $phone = trim((string) ($property['agent_phone'] ?? ''));
        $phoneHref = $phone !== '' ? preg_replace('/[^0-9+]/', '', $phone) : '';
        $telegram = trim((string) ($property['agent_telegram'] ?? ''));
        $telegramHref = $telegram === ''
            ? ''
            : (str_starts_with($telegram, 'http') ? $telegram : 'https://t.me/' . ltrim($telegram, '@'));

        $slug = (string) ($property['slug'] ?? '');
        $canonicalUrl = rtrim($baseUrl, '/') . '/property/show/' . rawurlencode($slug);
        $title = (string) ($property['title'] ?? 'Обʼєкт');
        $metaDescription = trim((string) ($property['meta_description'] ?? ''))
            ?: (trim((string) ($property['short_description'] ?? '')) ?: 'Картка обʼєкта Terra Nova CLUB.');

        $breadcrumbSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Головна', 'item' => rtrim($baseUrl, '/') . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Каталог', 'item' => rtrim($baseUrl, '/') . '/property/catalog'],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $title, 'item' => $canonicalUrl],
            ],
        ];

        $propertySchema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $title,
            'description' => $metaDescription,
            'image' => $images !== [] ? array_column($images, 'url') : array_values(array_filter([$coverUrl])),
            'sku' => (string) ($property['public_id'] ?? ''),
            'url' => $canonicalUrl,
            'category' => (string) ($property['type_name'] ?? ''),
            'offers' => [
                '@type' => 'Offer',
                'availability' => 'https://schema.org/InStock',
                'price' => $property['price_amount'] ?? 0,
                'priceCurrency' => (string) ($property['price_currency'] ?? 'USD'),
                'url' => $canonicalUrl,
            ],
        ];
        if ($locationLabel !== '') {
            $propertySchema['areaServed'] = $locationLabel;
        }
        if ((string) ($property['agent_name'] ?? '') !== '') {
            $propertySchema['seller'] = [
                '@type' => 'RealEstateAgent',
                'name' => (string) $property['agent_name'],
                'telephone' => $phone,
                'email' => (string) ($property['agent_email'] ?? ''),
            ];
        }

        return new PublicPropertyDetailViewModel(
            id: (int) ($property['id'] ?? 0),
            slug: $slug,
            publicId: (string) ($property['public_id'] ?? ''),
            title: $title,
            shortDescription: (string) ($property['short_description'] ?? ''),
            description: (string) ($property['description'] ?? ''),
            dealLabel: $dealLabel,
            dealType: (string) ($property['deal_type'] ?? ''),
            typeName: (string) ($property['type_name'] ?? ''),
            locationLabel: $locationLabel,
            priceLabel: (string) ($property['price_label'] ?? 'Ціна за запитом'),
            priceAmount: is_numeric($property['price_amount'] ?? null) ? (float) $property['price_amount'] : null,
            priceCurrency: (string) ($property['price_currency'] ?? 'USD'),
            coverUrl: $coverUrl,
            images: $images,
            summaryFacts: $summaryFacts,
            characteristicGroups: $characteristicGroups,
            features: $features,
            fitHighlights: $fitHighlights,
            groupedProperties: $this->list($data['grouped'] ?? null),
            groupTitle: (string) ($property['group_title'] ?? ''),
            relatedProperties: $this->list($data['related'] ?? null),
            agent: [
                'name' => (string) ($property['agent_name'] ?? ''),
                'role' => (string) ($property['agent_role'] ?? ''),
                'phone' => $phone,
                'email' => (string) ($property['agent_email'] ?? ''),
                'telegram' => $telegram,
                'phoneHref' => $phoneHref !== '' ? 'tel:' . $phoneHref : '',
                'telegramHref' => $telegramHref,
            ],
            presentationUrl: '/property/presentation/' . rawurlencode($slug),
            pdfUrl: '/property/pdf/' . rawurlencode($slug),
            tourUrl: (string) ($property['tour_url'] ?? ''),
            videoUrl: (string) ($property['video_url'] ?? ''),
            breadcrumbSchema: $breadcrumbSchema,
            productSchema: $propertySchema,
            metaTitle: (trim((string) ($property['meta_title'] ?? '')) ?: $title) . ' | Terra Nova CLUB',
            metaDescription: $metaDescription,
            metaImage: $coverUrl,
            canonicalUrl: $canonicalUrl,
            notice: $notice,
            error: $error,
        );
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

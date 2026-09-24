<?php

declare(strict_types=1);

namespace App\Web\Spatial;

use App\Web\Spatial\ViewModel\SpatialManageViewModel;

final class SpatialManagePresenter
{
    private const STATUS_LABELS = [
        'draft' => 'Чернетка',
        'processing' => 'Обробка',
        'review' => 'Перевірка',
        'published' => 'Опубліковано',
        'failed' => 'Помилка',
        'archived' => 'Архів',
    ];

    private const TYPE_LABELS = [
        'model' => '3D-модель',
        'digital_twin' => 'Digital twin',
        'roomplan' => 'RoomPlan',
        'panorama' => '360°',
        'gaussian_splat' => 'Gaussian splat',
        'mixed' => 'Змішана',
    ];

    /** @param array<string,mixed> $data */
    public function present(
        array $data,
        string $statusMessage = '',
        ?string $error = null,
    ): SpatialManageViewModel {
        $items = [];
        foreach ($this->list($data['scenes'] ?? null) as $scene) {
            $status = strtolower(trim((string) ($scene['status'] ?? '')));
            $sceneType = (string) ($scene['scene_type'] ?? '');
            $title = trim((string) ($scene['title'] ?? '')) ?: 'Spatial scene';
            $property = trim((string) ($scene['property_title'] ?? '')) ?: 'не прив’язано';

            $items[] = [
                'id' => (int) ($scene['id'] ?? 0),
                'title' => $title,
                'subtitle' => trim(implode(' · ', array_filter([
                    (string) ($scene['public_id'] ?? ''),
                    self::TYPE_LABELS[$sceneType] ?? $sceneType,
                    (string) ($scene['viewer_type'] ?? ''),
                    (string) ($scene['provider'] ?? ''),
                ]))),
                'meta' => sprintf(
                    '%s · assets %d/%d · hotspots %d',
                    $property,
                    (int) ($scene['ready_asset_count'] ?? 0),
                    (int) ($scene['asset_count'] ?? 0),
                    (int) ($scene['hotspot_count'] ?? 0),
                ),
                'statusLabel' => self::STATUS_LABELS[$status] ?? ($status !== '' ? $status : '—'),
                'statusTone' => $this->tone($status),
                'href' => '/spatial/edit/' . (int) ($scene['id'] ?? 0),
            ];
        }

        $stats = [];
        foreach ((array) ($data['stats'] ?? []) as $key => $value) {
            if (is_scalar($value)) {
                $stats[(string) $key] = (int) $value;
            }
        }

        $filters = [
            'q' => trim((string) (($data['filters']['q'] ?? ''))),
            'status' => trim((string) (($data['filters']['status'] ?? ''))),
        ];

        return new SpatialManageViewModel(
            scenes: $items,
            stats: $stats,
            filters: $filters,
            statusOptions: self::STATUS_LABELS,
            statusMessage: trim($statusMessage),
            error: $error,
        );
    }

    private function tone(string $status): string
    {
        return match ($status) {
            'published' => 'positive',
            'processing', 'review' => 'warning',
            'failed' => 'danger',
            default => 'neutral',
        };
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

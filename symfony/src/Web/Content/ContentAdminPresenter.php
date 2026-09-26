<?php

declare(strict_types=1);

namespace App\Web\Content;

use App\Web\Content\ViewModel\ContentAdministrationGridViewModel;
use App\Web\Content\ViewModel\ContentAdministrationViewModel;
use App\Web\Content\ViewModel\ContentEditorViewModel;
use App\Web\Experience\Data\DataGridColumn;
use App\Web\Experience\Data\DataGridPage;
use App\Web\Experience\Data\DataGridQuery;
use App\Web\Experience\Data\DataGridState;

final class ContentAdminPresenter
{
    /** @var array<string,string> */
    private const TYPE_LABELS = [
        'blog_post' => 'Стаття',
        'seo_landing' => 'SEO-лендінг',
    ];

    /** @var array<string,string> */
    private const STATUS_LABELS = [
        'draft' => 'Чернетка',
        'review' => 'На перевірці',
        'published' => 'Опубліковано',
        'archived' => 'Архів',
    ];

    /** @param array<string,mixed> $data */
    public function manage(array $data, ?string $error = null): ContentAdministrationViewModel
    {
        $stats = $this->array($data['stats'] ?? null);
        $integration = $this->array($data['integration_stats'] ?? null);
        $filters = $this->array($data['filters'] ?? null);
        $rows = [];

        foreach ($this->list($data['items'] ?? null) as $item) {
            $id = (int) ($item['id'] ?? 0);
            $status = (string) ($item['status'] ?? '');
            $type = (string) ($item['content_type'] ?? '');
            $score = (int) ($item['seo_score'] ?? 0);

            $rows[] = [
                '_href' => $id > 0 ? '/admin/content/edit/' . $id : null,
                'material' => trim((string) ($item['title'] ?? '') . ' · ' . (string) ($item['slug'] ?? ''), ' ·'),
                'type' => self::TYPE_LABELS[$type] ?? $type,
                'status' => self::STATUS_LABELS[$status] ?? $status,
                'seo' => $score . '%',
                'source' => (string) ($item['source'] ?? ''),
                'updated' => (string) ($item['updated_at'] ?? ''),
            ];
        }

        $deliveries = [];
        foreach ($this->list($data['deliveries'] ?? null) as $delivery) {
            $deliveries[] = [
                'title' => (string) ($delivery['event_type'] ?? 'Webhook'),
                'subtitle' => trim((string) (($delivery['direction'] ?? '') . ' / ' . ($delivery['status'] ?? '')), ' /'),
                'meta' => trim((string) (($delivery['created_at'] ?? '') . (($delivery['error_message'] ?? '') !== '' ? ' · ' . $delivery['error_message'] : '')), ' ·'),
            ];
        }

        return new ContentAdministrationViewModel(
            filters: [
                'q' => (string) ($filters['q'] ?? ''),
                'type' => (string) ($filters['type'] ?? ''),
                'status' => (string) ($filters['status'] ?? ''),
            ],
            kpis: [
                ['label' => 'Чернетки', 'value' => (string) $this->countStatus($stats, 'draft'), 'hint' => 'ще не публічні'],
                ['label' => 'На перевірці', 'value' => (string) $this->countStatus($stats, 'review'), 'hint' => 'потребують рішення'],
                ['label' => 'Опубліковано', 'value' => (string) $this->countStatus($stats, 'published'), 'hint' => 'видно в sitemap'],
                ['label' => 'n8n у черзі', 'value' => (string) (int) ($integration['pending'] ?? 0), 'hint' => (string) (int) ($integration['failed'] ?? 0) . ' помилок'],
            ],
            typeLabels: self::TYPE_LABELS,
            statusLabels: self::STATUS_LABELS,
            grid: $this->contentGrid($rows),
            deliveries: $deliveries,
            sentDeliveries: (int) ($integration['sent'] ?? 0),
            error: $error,
        );
    }

    /** @param array<string,mixed> $data */
    public function editor(array $data, string $actionStatus = ''): ContentEditorViewModel
    {
        $item = is_array($data['item'] ?? null) ? $data['item'] : [
            'id' => 0,
            'content_type' => 'blog_post',
            'status' => 'draft',
            'robots' => 'index,follow',
            'body_html' => '',
            'seo_score' => 0,
        ];

        foreach (['published_at', 'scheduled_at'] as $field) {
            $item[$field . '_input'] = $this->datetimeLocal($item[$field] ?? null);
        }

        $revisions = [];
        foreach ($this->list($data['revisions'] ?? null) as $revision) {
            $revisions[] = [
                'title' => (string) (($revision['user_name'] ?? '') ?: ($revision['source'] ?? 'Revision')),
                'subtitle' => (string) ($revision['source'] ?? ''),
                'meta' => (string) ($revision['created_at'] ?? ''),
            ];
        }

        return new ContentEditorViewModel(
            item: $item,
            revisions: $revisions,
            actionStatus: $actionStatus,
            notFound: (bool) ($data['not_found'] ?? false),
        );
    }

    /** @param list<array<string,mixed>> $rows */
    private function contentGrid(array $rows): ContentAdministrationGridViewModel
    {
        $count = count($rows);

        return new ContentAdministrationGridViewModel(
            query: new DataGridQuery(perPage: max(10, $count)),
            page: new DataGridPage($rows, $count, 1, max(1, $count)),
            columns: [
                new DataGridColumn('material', 'Матеріал', mobilePriority: 10),
                new DataGridColumn('type', 'Тип', mobilePriority: 20),
                new DataGridColumn('status', 'Статус', mobilePriority: 30),
                new DataGridColumn('seo', 'SEO', mobilePriority: 40, align: 'end'),
                new DataGridColumn('source', 'Джерело', mobilePriority: 50),
                new DataGridColumn('updated', 'Оновлено', mobilePriority: 60),
            ],
            state: $count === 0 ? DataGridState::Empty : DataGridState::Ready,
        );
    }

    /** @param array<string,mixed> $stats */
    private function countStatus(array $stats, string $status): int
    {
        return (int) ($this->array($stats['blog_post'] ?? null)[$status] ?? 0)
            + (int) ($this->array($stats['seo_landing'] ?? null)[$status] ?? 0);
    }

    private function datetimeLocal(mixed $value): string
    {
        if (!is_scalar($value) || trim((string) $value) === '') return '';
        $timestamp = strtotime((string) $value);
        return $timestamp === false ? '' : date('Y-m-d\\TH:i', $timestamp);
    }

    /** @return array<string,mixed> */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<array<string,mixed>> */
    private function list(mixed $value): array
    {
        if (!is_array($value)) return [];
        return array_values(array_filter($value, 'is_array'));
    }
}

<?php

declare(strict_types=1);

namespace App\Web\Sales;

use App\Web\Sales\ViewModel\SalesTodayViewModel;

final class SalesTodayPresenter
{
    /** @var array<string,array{label:string,description:string,anchor:string,eyebrow:string}> */
    private const SECTIONS = [
        'needs_approval' => [
            'label' => 'Needs My Approval',
            'description' => 'Дії COS, які чекають рішення.',
            'anchor' => 'intelligence',
            'eyebrow' => 'COS intelligence',
        ],
        'must_do' => [
            'label' => 'Must Do',
            'description' => 'Що потрібно зробити сьогодні.',
            'anchor' => 'work',
            'eyebrow' => 'Operational queue',
        ],
        'ai_recommended' => [
            'label' => 'AI Recommended',
            'description' => 'Пропозиції COS, які потребують уваги.',
            'anchor' => 'intelligence',
            'eyebrow' => 'COS intelligence',
        ],
        'overdue' => [
            'label' => 'Overdue',
            'description' => 'Прострочені наступні контакти.',
            'anchor' => 'work',
            'eyebrow' => 'Operational queue',
        ],
        'new_replies' => [
            'label' => 'New Replies',
            'description' => 'Нові вхідні відповіді.',
            'anchor' => 'communications',
            'eyebrow' => 'Operational queue',
        ],
        'meetings' => [
            'label' => 'Meetings',
            'description' => 'Зустрічі на сьогодні.',
            'anchor' => 'work',
            'eyebrow' => 'Operational queue',
        ],
        'followups' => [
            'label' => 'Follow-ups',
            'description' => 'Заплановані follow-up.',
            'anchor' => 'work',
            'eyebrow' => 'Operational queue',
        ],
        'waiting_for_client' => [
            'label' => 'Waiting for Client',
            'description' => 'Угоди без активного next action.',
            'anchor' => 'work',
            'eyebrow' => 'Operational queue',
        ],
    ];

    /** @param array<string,list<array<string,mixed>>> $data */
    public function present(array $data, string $scope, ?string $error = null): SalesTodayViewModel
    {
        $sections = [];
        $total = 0;

        foreach (self::SECTIONS as $key => $definition) {
            $items = [];

            foreach ($this->list($data[$key] ?? null) as $item) {
                $dealId = (int) ($item['id'] ?? $item['deal_id'] ?? 0);
                $attention = $this->reason((string) ($item['attention_reason'] ?? ''));

                $items[] = [
                    'dealId' => $dealId,
                    'activityId' => (int) ($item['activity_id'] ?? 0),
                    'approvalId' => (string) ($item['approval_id'] ?? ''),
                    'title' => (string) (
                        $item['customer']
                        ?? $item['type']
                        ?? $item['action_type']
                        ?? 'Action'
                    ),
                    'subtitle' => (string) (
                        $item['activity_title']
                        ?? $item['title']
                        ?? $item['approval_reason']
                        ?? $item['reason']
                        ?? $item['detail']
                        ?? $item['status']
                        ?? ''
                    ),
                    'meta' => (string) (
                        $item['due_at']
                        ?? $item['next_contact_at']
                        ?? $item['occurred_at']
                        ?? $item['created_at']
                        ?? ''
                    ),
                    'statusLabel' => $attention !== '' ? $attention : null,
                    'statusTone' => $attention !== '' ? 'warning' : 'neutral',
                    'href' => $dealId > 0
                        ? '/sales/deals/' . $dealId . '#' . $definition['anchor']
                        : null,
                ];
            }

            $total += count($items);
            $sections[] = [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'eyebrow' => $definition['eyebrow'],
                'items' => $items,
            ];
        }

        return new SalesTodayViewModel(
            scope: $scope === 'team' ? 'team' : 'mine',
            sections: $sections,
            total: $total,
            error: $error,
        );
    }

    private function reason(string $reason): string
    {
        return match ($reason) {
            'FOLLOW_UP_OVERDUE' => 'Follow-up прострочений',
            'HIGH_PRIORITY' => 'Високий пріоритет',
            'NO_ACTIVITY', 'NO_ACTIVITY_48H' => 'Немає активності',
            'NO_NEXT_ACTION' => 'Не задана наступна дія',
            'UNASSIGNED' => 'Без відповідального',
            'NEW_LEAD' => 'Новий lead',
            default => $reason,
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

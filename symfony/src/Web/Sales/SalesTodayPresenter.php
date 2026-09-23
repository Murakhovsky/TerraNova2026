<?php

declare(strict_types=1);

namespace App\Web\Sales;

use App\Web\Sales\ViewModel\SalesTodayViewModel;

final class SalesTodayPresenter
{
    /** @var array<string,array{0:string,1:string,2:string,3:string}> */
    private const SECTIONS = [
        'needs_approval' => ['Needs My Approval', 'Дії COS, які чекають рішення', 'intelligence', 'COS intelligence'],
        'must_do' => ['Must Do', 'Що потрібно зробити сьогодні', 'work', 'Operational queue'],
        'ai_recommended' => ['AI Recommended', 'Пропозиції COS, які потребують уваги', 'intelligence', 'COS intelligence'],
        'overdue' => ['Overdue', 'Прострочені наступні контакти', 'work', 'Operational queue'],
        'new_replies' => ['New Replies', 'Нові вхідні відповіді', 'communications', 'Operational queue'],
        'meetings' => ['Meetings', 'Зустрічі на сьогодні', 'work', 'Operational queue'],
        'followups' => ['Follow-ups', 'Заплановані follow-up', 'work', 'Operational queue'],
        'waiting_for_client' => ['Waiting for Client', 'Угоди без активного next action', 'work', 'Operational queue'],
    ];

    /**
     * @param array<string,list<array<string,mixed>>> $data
     */
    public function present(
        array $data,
        string $scope,
        ?string $error = null,
    ): SalesTodayViewModel {
        $sections = [];
        $total = 0;

        foreach (self::SECTIONS as $key => [$label, $description, $anchor, $eyebrow]) {
            $items = [];
            foreach ($this->list($data[$key] ?? null) as $item) {
                $items[] = $this->item($item, $anchor);
            }

            $total += count($items);
            $sections[] = [
                'key' => $key,
                'label' => $label,
                'description' => $description,
                'eyebrow' => $eyebrow,
                'items' => $items,
            ];
        }

        return new SalesTodayViewModel(
            scope: $scope === 'team' ? 'team' : 'mine',
            total: $total,
            sections: $sections,
            error: $error,
        );
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function item(array $item, string $anchor): array
    {
        $dealId = (int) ($item['id'] ?? $item['deal_id'] ?? 0);
        $activityId = (int) ($item['activity_id'] ?? 0);
        $approvalId = trim((string) ($item['approval_id'] ?? ''));
        $attention = trim((string) ($item['attention_reason'] ?? ''));

        $title = $this->first(
            $item['customer'] ?? null,
            $item['type'] ?? null,
            $item['action_type'] ?? null,
            'Action',
        );
        $subtitle = $this->first(
            $item['activity_title'] ?? null,
            $item['title'] ?? null,
            $item['approval_reason'] ?? null,
            $item['reason'] ?? null,
            $item['detail'] ?? null,
            $item['status'] ?? null,
            '',
        );
        $due = $this->first(
            $item['due_at'] ?? null,
            $item['next_contact_at'] ?? null,
            $item['occurred_at'] ?? null,
            $item['created_at'] ?? null,
            '',
        );

        return [
            'dealId' => $dealId,
            'activityId' => $activityId > 0 ? $activityId : null,
            'approvalId' => $approvalId !== '' ? $approvalId : null,
            'title' => $title,
            'subtitle' => $subtitle,
            'meta' => $due,
            'status' => $attention !== '' ? $this->reason($attention) : null,
            'tone' => $this->tone($attention),
            'href' => $dealId > 0 ? '/sales/deals/' . $dealId . '#' . $anchor : null,
        ];
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

    private function tone(string $reason): string
    {
        return match ($reason) {
            'FOLLOW_UP_OVERDUE' => 'danger',
            'HIGH_PRIORITY', 'NO_ACTIVITY', 'NO_ACTIVITY_48H', 'NO_NEXT_ACTION' => 'warning',
            'NEW_LEAD' => 'info',
            default => 'neutral',
        };
    }

    private function first(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return '';
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

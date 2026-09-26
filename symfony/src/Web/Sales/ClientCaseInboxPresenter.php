<?php

declare(strict_types=1);

namespace App\Web\Sales;

use App\Web\Sales\ViewModel\ClientCaseInboxViewModel;

final class ClientCaseInboxPresenter
{
    /** @var array<string,string> */
    private const STATUS = [
        'new' => 'Нова',
        'contacted' => 'Контакт був',
        'qualified' => 'Кваліфікована',
        'viewing_planned' => 'Перегляд заплановано',
        'viewing' => 'Перегляд',
        'negotiation' => 'Переговори',
        'won' => 'Успіх',
        'lost' => 'Втрачена',
        'spam' => 'Спам',
        'closed' => 'Закрита',
    ];

    /** @var array<string,string> */
    private const INTENT = [
        'general_contact' => 'Загальний контакт',
        'presentation' => 'Презентація',
        'viewing' => 'Перегляд',
        'similar_search' => 'Підібрати схожий',
    ];

    /** @var array<string,string> */
    private const ACTIVITY = [
        'note' => 'Нотатка',
        'call' => 'Дзвінок',
        'message' => 'Повідомлення',
        'status_change' => 'Зміна статусу',
        'task' => 'Задача',
        'viewing' => 'Перегляд',
    ];

    /** @var array<string,string> */
    private const PRIORITY = [
        'low' => 'Низький',
        'normal' => 'Звичайний',
        'high' => 'Високий',
        'urgent' => 'Терміново',
    ];

    /** @param array<string,mixed> $data */
    public function present(array $data, ?string $error = null): ClientCaseInboxViewModel
    {
        $filters = is_array($data['filters'] ?? null) ? $data['filters'] : [];
        $stats = is_array($data['stats'] ?? null) ? $data['stats'] : [];
        $statusStats = is_array($stats['status'] ?? null) ? $stats['status'] : [];
        $intentStats = is_array($stats['intent'] ?? null) ? $stats['intent'] : [];

        $managerOptions = [];
        foreach ($this->list($data['managers'] ?? null) as $manager) {
            $id = (int) ($manager['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $label = trim((string) ($manager['full_name'] ?? ''));
            if ($label === '') {
                $label = trim((string) ($manager['email'] ?? ''));
            }
            $managerOptions[] = [
                'id' => $id,
                'label' => $label !== '' ? $label : ('#' . $id),
            ];
        }

        $openCaseOptions = [];
        foreach ($this->list($data['open_cases'] ?? null) as $case) {
            $id = (int) ($case['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $publicId = trim((string) ($case['public_id'] ?? ''));
            $person = trim((string) ($case['full_name'] ?? ''));
            $openCaseOptions[] = [
                'id' => $id,
                'label' => trim($publicId . ($publicId !== '' && $person !== '' ? ' / ' : '') . $person) ?: ('#' . $id),
            ];
        }

        $requests = [];
        foreach ($this->list($data['requests'] ?? null) as $request) {
            $id = (int) ($request['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $status = (string) ($request['status'] ?? 'new');
            $intent = (string) ($request['request_intent'] ?? 'general_contact');
            $caseId = (int) ($request['client_case_id'] ?? 0);
            $propertySlug = trim((string) ($request['property_slug'] ?? ''));
            $propertyTitle = trim((string) ($request['property_title'] ?? ''));
            $propertyPublicId = trim((string) ($request['property_public_id'] ?? ''));
            $casePublicId = trim((string) ($request['case_public_id'] ?? ''));
            $caseTitle = trim((string) ($request['case_title'] ?? ''));

            $requests[] = [
                'id' => $id,
                'intentLabel' => self::INTENT[$intent] ?? $intent,
                'name' => (string) ($request['full_name'] ?? 'Без імені'),
                'contact' => trim((string) ($request['phone'] ?? ''))
                    ?: (trim((string) ($request['email'] ?? '')) ?: 'контакт не вказано'),
                'createdAt' => (string) ($request['created_at'] ?? ''),
                'status' => $status,
                'statusLabel' => self::STATUS[$status] ?? $status,
                'statusTone' => $this->statusTone($status),
                'propertyHref' => $propertySlug !== '' ? '/property/show/' . $propertySlug : null,
                'propertyLabel' => $propertyTitle !== ''
                    ? trim(($propertyPublicId !== '' ? $propertyPublicId . ' / ' : '') . $propertyTitle)
                    : 'без об’єкта',
                'caseId' => $caseId,
                'caseHref' => $caseId > 0 ? '/client-case/show/' . $caseId : null,
                'caseLabel' => $caseId > 0
                    ? trim(($casePublicId !== '' ? $casePublicId : 'Кейс') . ($caseTitle !== '' ? ' / ' . $caseTitle : ''))
                    : 'не прив’язано',
                'manager' => trim((string) ($request['manager_name'] ?? '')) ?: 'не призначено',
                'nextContact' => trim((string) ($request['next_contact_at'] ?? '')) ?: 'не задано',
                'nextContactInput' => $this->datetimeLocal($request['next_contact_at'] ?? null),
                'activityCount' => (int) ($request['activity_count'] ?? 0),
                'message' => trim((string) ($request['message'] ?? '')),
                'managerNote' => trim((string) ($request['manager_note'] ?? '')),
                'assignedUserId' => (int) ($request['assigned_user_id'] ?? 0),
            ];
        }

        $statusTabs = [[
            'key' => 'all',
            'label' => 'Усі',
            'href' => '/client-case/inbox',
            'active' => (($filters['status'] ?? '') === ''),
        ]];
        foreach (self::STATUS as $code => $label) {
            $statusTabs[] = [
                'key' => $code,
                'label' => $label . ' · ' . (int) ($statusStats[$code] ?? 0),
                'href' => '/client-case/inbox?status=' . rawurlencode($code),
                'active' => (($filters['status'] ?? '') === $code),
            ];
        }

        $newCount = (int) ($statusStats['new'] ?? $stats['new'] ?? 0);

        return new ClientCaseInboxViewModel(
            filters: [
                'q' => (string) ($filters['q'] ?? ''),
                'request_intent' => (string) ($filters['request_intent'] ?? ''),
                'status' => (string) ($filters['status'] ?? ''),
                'assigned_user_id' => (int) ($filters['assigned_user_id'] ?? 0),
                'has_case' => (string) ($filters['has_case'] ?? ''),
                'sort' => (string) ($filters['sort'] ?? 'newest'),
            ],
            metrics: [
                ['label' => 'Всього', 'value' => (string) ((int) ($stats['total'] ?? 0)), 'hint' => 'усі заявки', 'href' => '/client-case/inbox', 'tone' => 'neutral'],
                ['label' => 'Нові', 'value' => (string) $newCount, 'hint' => 'потребують першої дії', 'href' => '/client-case/inbox?status=new', 'tone' => $newCount > 0 ? 'warning' : 'neutral'],
                ['label' => 'Презентації', 'value' => (string) ((int) ($intentStats['presentation'] ?? 0)), 'hint' => 'запит презентації', 'href' => '/client-case/inbox?request_intent=presentation', 'tone' => 'neutral'],
                ['label' => 'Без кейса', 'value' => (string) ((int) ($stats['no_case'] ?? 0)), 'hint' => 'створити або прив’язати', 'href' => '/client-case/inbox?has_case=no', 'tone' => ((int) ($stats['no_case'] ?? 0)) > 0 ? 'warning' : 'neutral'],
                ['label' => 'Контакт', 'value' => (string) ((int) ($stats['due'] ?? 0)), 'hint' => 'прострочено або сьогодні', 'href' => '/client-case/inbox?sort=next_contact', 'tone' => ((int) ($stats['due'] ?? 0)) > 0 ? 'danger' : 'neutral'],
            ],
            statusTabs: $statusTabs,
            requests: $requests,
            statusOptions: self::STATUS,
            intentOptions: self::INTENT,
            activityOptions: self::ACTIVITY,
            priorityOptions: self::PRIORITY,
            caseOptions: ['' => 'Усі', 'yes' => 'Є кейс', 'no' => 'Без кейса'],
            sortOptions: ['newest' => 'Нові', 'next_contact' => 'Наступний контакт', 'status' => 'За статусом'],
            managerOptions: $managerOptions,
            openCaseOptions: $openCaseOptions,
            newCount: $newCount,
            error: $error,
        );
    }

    private function statusTone(string $status): string
    {
        return match ($status) {
            'new', 'viewing_planned', 'negotiation' => 'warning',
            'qualified', 'won' => 'positive',
            'viewing', 'contacted' => 'info',
            'lost' => 'danger',
            default => 'neutral',
        };
    }

    private function datetimeLocal(mixed $value): string
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return '';
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? '' : date('Y-m-d\\TH:i', $timestamp);
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

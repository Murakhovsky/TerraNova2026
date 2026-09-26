<?php

declare(strict_types=1);

namespace App\Web\Administration;

use App\Web\Administration\ViewModel\AdministrationUsersGridViewModel;
use App\Web\Administration\ViewModel\AdministrationUsersViewModel;
use App\Web\Experience\Data\DataGridColumn;
use App\Web\Experience\Data\DataGridPage;
use App\Web\Experience\Data\DataGridQuery;
use App\Web\Experience\Data\DataGridState;

final class AdministrationUsersPresenter
{
    /** @var array<string,string> */
    private const ROLE_LABELS = [
        'buyer' => 'Покупець',
        'seller' => 'Власник',
        'investor' => 'Інвестор',
        'realtor' => 'Рієлтор',
        'developer' => 'Забудовник',
        'partner' => 'Партнер',
        'manager' => 'Менеджер',
        'admin' => 'Адмін',
    ];

    /** @var array<string,string> */
    private const STATUS_LABELS = [
        'active' => 'Активний',
        'pending' => 'Очікує',
        'blocked' => 'Заблокований',
    ];

    /** @param array<string,mixed> $data */
    public function present(array $data, string $actionStatus = '', ?string $error = null): AdministrationUsersViewModel
    {
        $filters = $this->array($data['filters'] ?? null);
        $stats = $this->array($data['stats'] ?? null);
        $roles = $this->array($stats['roles'] ?? null);
        $users = [];

        foreach ($this->list($data['users'] ?? null) as $user) {
            $role = (string) ($user['role'] ?? '');
            $status = (string) ($user['status'] ?? '');

            $users[] = [
                'id' => (int) ($user['id'] ?? 0),
                'name' => (string) ($user['full_name'] ?? ''),
                'email' => (string) ($user['email'] ?? ''),
                'phone' => (string) ($user['phone'] ?? ''),
                'role' => $role,
                'roleLabel' => self::ROLE_LABELS[$role] ?? $role,
                'status' => $status,
                'statusLabel' => self::STATUS_LABELS[$status] ?? $status,
                'statusTone' => match ($status) {
                    'active' => 'positive',
                    'blocked' => 'danger',
                    'pending' => 'warning',
                    default => 'neutral',
                },
                'lastLogin' => (string) (($user['last_login_at'] ?? '') ?: 'ще не входив'),
            ];
        }

        return new AdministrationUsersViewModel(
            filters: [
                'q' => (string) ($filters['q'] ?? ''),
                'role' => (string) ($filters['role'] ?? ''),
                'status' => (string) ($filters['status'] ?? ''),
                'sort' => (string) ($filters['sort'] ?? 'newest'),
            ],
            kpis: [
                ['label' => 'Всього', 'value' => (string) (int) ($stats['total'] ?? 0), 'hint' => 'усі акаунти'],
                ['label' => 'Активні', 'value' => (string) (int) ($stats['active'] ?? 0), 'hint' => 'можуть входити'],
                ['label' => 'Менеджери', 'value' => (string) (int) ($roles['manager'] ?? 0), 'hint' => 'робочий доступ'],
                ['label' => 'Адміни', 'value' => (string) (int) ($roles['admin'] ?? 0), 'hint' => 'повний доступ'],
                ['label' => 'Блок', 'value' => (string) (int) ($stats['blocked'] ?? 0), 'hint' => 'доступ закрито'],
            ],
            roleLabels: self::ROLE_LABELS,
            statusLabels: self::STATUS_LABELS,
            capabilities: $this->capabilities(),
            users: $users,
            actionStatus: $actionStatus,
            error: $error,
        );
    }

    private function capabilities(): AdministrationUsersGridViewModel
    {
        $rows = [];
        foreach (self::ROLE_LABELS as $role => $label) {
            $capabilities = self::roleCapabilities()[$role] ?? [];
            $rows[] = [
                'role' => $label . ' · ' . $role,
                'catalog' => $this->yesNo($capabilities['catalog'] ?? false),
                'cabinet' => $this->yesNo($capabilities['cabinet'] ?? false),
                'submit' => $this->yesNo($capabilities['submit_property'] ?? false),
                'listing' => $this->yesNo($capabilities['listing'] ?? false),
                'crm' => $this->yesNo($capabilities['crm'] ?? false),
                'admin' => $this->yesNo($capabilities['admin'] ?? false),
            ];
        }

        $count = count($rows);

        return new AdministrationUsersGridViewModel(
            query: new DataGridQuery(perPage: max(10, $count)),
            page: new DataGridPage($rows, $count, 1, max(1, $count)),
            columns: [
                new DataGridColumn('role', 'Роль', mobilePriority: 10),
                new DataGridColumn('catalog', 'Каталог', mobilePriority: 20),
                new DataGridColumn('cabinet', 'Кабінет', mobilePriority: 30),
                new DataGridColumn('submit', 'Подати об’єкт', mobilePriority: 40),
                new DataGridColumn('listing', 'Listing', mobilePriority: 50),
                new DataGridColumn('crm', 'CRM', mobilePriority: 60),
                new DataGridColumn('admin', 'Admin', mobilePriority: 70),
            ],
            state: $count === 0 ? DataGridState::Empty : DataGridState::Ready,
        );
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'так' : 'ні';
    }

    /** @return array<string,array<string,bool>> */
    private static function roleCapabilities(): array
    {
        return [
            'buyer' => ['catalog'=>true,'cabinet'=>true,'submit_property'=>false,'listing'=>false,'crm'=>false,'admin'=>false],
            'seller' => ['catalog'=>true,'cabinet'=>true,'submit_property'=>true,'listing'=>false,'crm'=>false,'admin'=>false],
            'investor' => ['catalog'=>true,'cabinet'=>true,'submit_property'=>false,'listing'=>false,'crm'=>false,'admin'=>false],
            'realtor' => ['catalog'=>true,'cabinet'=>true,'submit_property'=>true,'listing'=>true,'crm'=>false,'admin'=>false],
            'developer' => ['catalog'=>true,'cabinet'=>true,'submit_property'=>true,'listing'=>true,'crm'=>false,'admin'=>false],
            'partner' => ['catalog'=>true,'cabinet'=>true,'submit_property'=>true,'listing'=>true,'crm'=>false,'admin'=>false],
            'manager' => ['catalog'=>true,'cabinet'=>true,'submit_property'=>true,'listing'=>true,'crm'=>true,'admin'=>false],
            'admin' => ['catalog'=>true,'cabinet'=>true,'submit_property'=>true,'listing'=>true,'crm'=>true,'admin'=>true],
        ];
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

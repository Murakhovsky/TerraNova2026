<?php

declare(strict_types=1);

namespace App\Web\Identity;

use App\Web\Experience\Data\DataGridColumn;
use App\Web\Experience\Data\DataGridPage;
use App\Web\Experience\Data\DataGridQuery;
use App\Web\Experience\Data\DataGridState;
use App\Web\Identity\ViewModel\UserAdministrationViewModel;

final class UserAdministrationPresenter
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

    /** @var array<string,array<string,bool>> */
    private const ROLE_CAPABILITIES = [
        'buyer' => ['catalog'=>true,'cabinet'=>true,'submit_property'=>false,'listing'=>false,'crm'=>false,'admin'=>false],
        'seller' => ['catalog'=>true,'cabinet'=>true,'submit_property'=>true,'listing'=>false,'crm'=>false,'admin'=>false],
        'investor' => ['catalog'=>true,'cabinet'=>true,'submit_property'=>false,'listing'=>false,'crm'=>false,'admin'=>false],
        'realtor' => ['catalog'=>true,'cabinet'=>true,'submit_property'=>true,'listing'=>true,'crm'=>false,'admin'=>false],
        'developer' => ['catalog'=>true,'cabinet'=>true,'submit_property'=>true,'listing'=>true,'crm'=>false,'admin'=>false],
        'partner' => ['catalog'=>true,'cabinet'=>true,'submit_property'=>true,'listing'=>true,'crm'=>false,'admin'=>false],
        'manager' => ['catalog'=>true,'cabinet'=>true,'submit_property'=>true,'listing'=>true,'crm'=>true,'admin'=>false],
        'admin' => ['catalog'=>true,'cabinet'=>true,'submit_property'=>true,'listing'=>true,'crm'=>true,'admin'=>true],
    ];

    /** @param array<string,mixed> $data */
    public function present(
        array $data,
        string $actionStatus = '',
        ?string $error = null,
    ): UserAdministrationViewModel {
        $filters = $this->array($data['filters'] ?? null);
        $stats = $this->array($data['stats'] ?? null);

        $users = [];
        foreach ($this->list($data['users'] ?? null) as $user) {
            $role = (string) ($user['role'] ?? '');
            $status = (string) ($user['status'] ?? '');
            $users[] = [
                'id' => (int) ($user['id'] ?? 0),
                'email' => (string) ($user['email'] ?? ''),
                'fullName' => (string) ($user['full_name'] ?? ''),
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

        $capabilityRows = [];
        foreach (self::ROLE_LABELS as $role => $label) {
            $capabilities = self::ROLE_CAPABILITIES[$role] ?? [];
            $capabilityRows[] = [
                'role' => $label,
                'catalog' => !empty($capabilities['catalog']) ? 'так' : 'ні',
                'cabinet' => !empty($capabilities['cabinet']) ? 'так' : 'ні',
                'submit_property' => !empty($capabilities['submit_property']) ? 'так' : 'ні',
                'listing' => !empty($capabilities['listing']) ? 'так' : 'ні',
                'crm' => !empty($capabilities['crm']) ? 'так' : 'ні',
                'admin' => !empty($capabilities['admin']) ? 'так' : 'ні',
            ];
        }
        $count = count($capabilityRows);

        return new UserAdministrationViewModel(
            filters: [
                'q' => (string) ($filters['q'] ?? ''),
                'role' => (string) ($filters['role'] ?? ''),
                'status' => (string) ($filters['status'] ?? ''),
                'sort' => (string) ($filters['sort'] ?? 'newest'),
            ],
            kpis: [
                ['label'=>'Всього','value'=>(string)(int)($stats['total'] ?? 0),'hint'=>'усі акаунти','href'=>'/admin/users'],
                ['label'=>'Активні','value'=>(string)(int)($stats['active'] ?? 0),'hint'=>'можуть входити','href'=>'/admin/users?status=active'],
                ['label'=>'Менеджери','value'=>(string)(int)($this->array($stats['roles'] ?? null)['manager'] ?? 0),'hint'=>'робочий доступ','href'=>'/admin/users?role=manager'],
                ['label'=>'Адміни','value'=>(string)(int)($this->array($stats['roles'] ?? null)['admin'] ?? 0),'hint'=>'повний доступ','href'=>'/admin/users?role=admin'],
                ['label'=>'Блок','value'=>(string)(int)($stats['blocked'] ?? 0),'hint'=>'доступ закрито','href'=>'/admin/users?status=blocked'],
            ],
            roleOptions: ['' => 'Усі'] + self::ROLE_LABELS,
            statusOptions: ['' => 'Усі'] + self::STATUS_LABELS,
            sortOptions: [
                'newest' => 'Нові',
                'name' => 'Імʼя',
                'role' => 'Роль',
                'last_login' => 'Останній вхід',
            ],
            users: $users,
            capabilityQuery: new DataGridQuery(perPage: max(10, $count)),
            capabilityPage: new DataGridPage($capabilityRows, $count, 1, max(1, $count)),
            capabilityColumns: [
                new DataGridColumn('role', 'Роль', mobilePriority: 10),
                new DataGridColumn('catalog', 'Каталог', mobilePriority: 20),
                new DataGridColumn('cabinet', 'Кабінет', mobilePriority: 30),
                new DataGridColumn('submit_property', 'Подати об’єкт', mobilePriority: 40),
                new DataGridColumn('listing', 'Listing', mobilePriority: 50),
                new DataGridColumn('crm', 'CRM', mobilePriority: 60),
                new DataGridColumn('admin', 'Admin', mobilePriority: 70),
            ],
            capabilityState: $count === 0 ? DataGridState::Empty : DataGridState::Ready,
            actionStatus: trim($actionStatus),
            error: $error,
        );
    }

    /** @return array<string,mixed> */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<array<string,mixed>> */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}

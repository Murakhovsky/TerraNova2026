<?php
declare(strict_types=1);

namespace App\Web\Portal;

use App\Web\Portal\ViewModel\CabinetSubmissionStatusViewModel;
use App\Web\Portal\ViewModel\CabinetViewModel;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;

final readonly class CabinetPresenter
{
    public function __construct(private ActiveModuleResolver $modules)
    {
    }

    public function present(TenantContext $tenant): CabinetViewModel
    {
        $organizationId = $tenant->organizationId()->value();
        $primaryActions = [
            ['label' => 'Каталог нерухомості', 'description' => 'Переглянути актуальні об’єкти.', 'href' => '/property/catalog'],
            ['label' => 'Обране', 'description' => 'Повернутися до збережених об’єктів.', 'href' => '/property/favour'],
            ['label' => 'Запропонувати об’єкт', 'description' => 'Передати нерухомість на модерацію та продаж.', 'href' => '/property/submit'],
        ];

        $workspaces = [
            ['label' => 'Мій кабінет', 'description' => 'Поточний профіль, роль та організація.', 'href' => '/cabinet'],
            ['label' => 'Activity Center', 'description' => 'Сповіщення, jobs та agent activity.', 'href' => '/workspace/activity-center'],
            ['label' => 'COS AI', 'description' => 'Контекст, доступні AI actions та останні agent runs.', 'href' => '/workspace/ai'],
        ];

        if ($tenant->isManager()) {
            array_unshift(
                $primaryActions,
                ['label' => 'Sales Today', 'description' => 'Операційний inbox менеджера на сьогодні.', 'href' => '/sales/today'],
                ['label' => 'Property Workspace', 'description' => 'Inventory, listing та модерація об’єктів.', 'href' => '/property/manage'],
            );
            $workspaces[] = ['label' => 'Sales', 'description' => 'Ліди, угоди, pipeline та аналітика.', 'href' => '/sales'];
            $workspaces[] = ['label' => 'Client Cases', 'description' => 'Клієнтські кейси, активності та наступні дії.', 'href' => '/client-case'];
            $workspaces[] = ['label' => 'Spatial', 'description' => '3D-сцени, captures та assets.', 'href' => '/spatial/manage'];

            try {
                if ($this->modules->isEnabled($organizationId, 'growth')) {
                    $workspaces[] = ['label' => 'Growth', 'description' => 'Prospect intelligence, signals та experiments.', 'href' => '/growth'];
                }
            } catch (\Throwable) {
                // Cabinet remains usable even if an optional module snapshot is temporarily unavailable.
            }
        }

        if ($tenant->isAdmin()) {
            array_unshift(
                $workspaces,
                ['label' => 'Executive Workspace', 'description' => 'Операційний стан компанії та ключові сигнали.', 'href' => '/admin'],
                ['label' => 'Engineering', 'description' => 'Автономні engineering workflows та human gates.', 'href' => '/admin/engineering'],
            );
            $workspaces[] = ['label' => 'Users', 'description' => 'Користувачі, ролі та доступ.', 'href' => '/admin/users'];
            $workspaces[] = ['label' => 'COS Control Center', 'description' => 'Events, decisions, actions, approvals та audit.', 'href' => '/cos/control-center'];
        }

        return new CabinetViewModel(
            userId: $tenant->userId()->value(),
            role: $tenant->role()->value(),
            organizationId: $organizationId,
            permissions: array_map(static fn ($permission): string => $permission->value(), $tenant->permissions()),
            primaryActions: $primaryActions,
            workspaces: $workspaces,
            manager: $tenant->isManager(),
            admin: $tenant->isAdmin(),
        );
    }

    public function retiredSubmission(int $submissionId): CabinetSubmissionStatusViewModel
    {
        return new CabinetSubmissionStatusViewModel(
            submissionId: $submissionId,
            title: 'Старий редактор заявки закрито',
            message: 'Маршрут заявки #' . $submissionId . ' належав legacy Web runtime і більше не використовується.',
        );
    }
}

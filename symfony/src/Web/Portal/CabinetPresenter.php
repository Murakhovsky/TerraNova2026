<?php
declare(strict_types=1);

namespace App\Web\Portal;

use App\Web\Portal\ViewModel\CabinetSubmissionStatusViewModel;
use App\Web\Portal\ViewModel\CabinetViewModel;
use Kernel\Tenant\Model\TenantContext;

final class CabinetPresenter
{
    public function present(TenantContext $tenant): CabinetViewModel
    {
        return new CabinetViewModel(
            userId: $tenant->userId()->value(),
            role: $tenant->role()->value(),
            organizationId: $tenant->organizationId()->value(),
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

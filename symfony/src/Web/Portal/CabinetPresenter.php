<?php
declare(strict_types=1);

namespace App\Web\Portal;

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
}

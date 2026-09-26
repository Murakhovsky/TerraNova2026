<?php

declare(strict_types=1);

namespace App\Web\Sales;

use App\Web\Sales\ViewModel\SalesAdminControlViewModel;

final class SalesAdminControlPresenter
{
    /** @param array<string,mixed> $data */
    public function present(
        string $kind,
        array $data,
        ?string $error = null,
    ): SalesAdminControlViewModel {
        return new SalesAdminControlViewModel(
            kind: $kind,
            data: $data,
            error: $error,
        );
    }
}

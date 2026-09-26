<?php
declare(strict_types=1);

namespace App\Web\Portal\ViewModel;

final readonly class CabinetViewModel
{
    public function __construct(
        public string $userId,
        public string $role,
        public string $organizationId,
    ) {}
}

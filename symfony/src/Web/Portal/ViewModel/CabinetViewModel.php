<?php
declare(strict_types=1);

namespace App\Web\Portal\ViewModel;

final readonly class CabinetViewModel
{
    /**
     * @param list<string> $permissions
     * @param list<array{label:string,description:string,href:string}> $primaryActions
     * @param list<array{label:string,description:string,href:string}> $workspaces
     */
    public function __construct(
        public string $userId,
        public string $role,
        public string $organizationId,
        public array $permissions,
        public array $primaryActions,
        public array $workspaces,
        public bool $manager,
        public bool $admin,
    ) {}
}

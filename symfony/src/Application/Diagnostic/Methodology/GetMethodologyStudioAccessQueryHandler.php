<?php

declare(strict_types=1);

namespace App\Application\Diagnostic\Methodology;

use Domains\Diagnostic\Application\Service\DiagnosticMethodologyAccess;
use Kernel\Application\Query\QueryHandlerInterface;
use Kernel\Module\ActiveModuleResolver;

final readonly class GetMethodologyStudioAccessQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ActiveModuleResolver $modules,
        private DiagnosticMethodologyAccess $access,
    ) {
    }

    /** @return array{enabled:bool,allowed:bool} */
    public function __invoke(GetMethodologyStudioAccessQuery $query): array
    {
        $organizationId = $query->organizationId->value();

        return [
            'enabled' => $this->modules->isEnabled($organizationId, 'diagnostic'),
            'allowed' => $this->access->allows(
                $organizationId,
                $query->userId,
                DiagnosticMethodologyAccess::VIEW,
            ),
        ];
    }
}

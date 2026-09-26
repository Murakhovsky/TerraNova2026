<?php

declare(strict_types=1);

namespace App\Application\Diagnostic\Report;

use Domains\Diagnostic\Application\Service\DiagnosticMethodologyAccess;
use Domains\Diagnostic\Application\Service\DiagnosticRuntimeService;
use Kernel\Application\Query\QueryHandlerInterface;
use Kernel\Module\ActiveModuleResolver;

final readonly class GetDiagnosticReportQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ActiveModuleResolver $modules,
        private DiagnosticMethodologyAccess $access,
        private DiagnosticRuntimeService $runtime,
    ) {
    }

    /** @return array{enabled:bool,allowed:bool,envelope?:array<string,mixed>} */
    public function __invoke(GetDiagnosticReportQuery $query): array
    {
        $organizationId = $query->organizationId->value();
        $enabled = $this->modules->isEnabled($organizationId, 'diagnostic');
        $allowed = $enabled && $this->access->allows(
            $organizationId,
            $query->userId,
            DiagnosticMethodologyAccess::VIEW,
        );

        if (!$enabled || !$allowed) {
            return ['enabled' => $enabled, 'allowed' => $allowed];
        }

        return [
            'enabled' => true,
            'allowed' => true,
            'envelope' => $this->runtime->report($organizationId, $query->sessionId),
        ];
    }
}

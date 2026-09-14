<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Application\DTO;

use Domains\Diagnostic\Model\DiagnosticTarget;

final readonly class StartDiagnosticSessionCommand
{
    public function __construct(
        public string $organizationId,
        public string $sessionId,
        public string $packId,
        public int $packVersion,
        public DiagnosticTarget $target,
    ) {
    }
}

<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Application\Contract;

use Domains\Diagnostic\Model\DiagnosticSession;

interface DiagnosticSessionRepositoryInterface
{
    public function save(string $organizationId, DiagnosticSession $session, ?int $expectedLockVersion = null): void;

    public function get(string $organizationId, string $sessionId): ?DiagnosticSession;
}

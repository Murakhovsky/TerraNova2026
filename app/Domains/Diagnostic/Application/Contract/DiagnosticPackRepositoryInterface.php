<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Application\Contract;

use Domains\Diagnostic\Model\DiagnosticPack;

interface DiagnosticPackRepositoryInterface
{
    public function save(string $organizationId, DiagnosticPack $pack, ?int $expectedLockVersion = null): void;

    public function get(string $organizationId, string $packId, int $version): ?DiagnosticPack;
}

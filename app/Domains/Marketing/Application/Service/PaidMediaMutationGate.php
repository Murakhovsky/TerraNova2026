<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;

use DomainException;

/**
 * Finance F1 and canonical Workflow are not yet proven. No provider write method
 * is wired, including activate, budget raise or create-paused.
 */
final class PaidMediaMutationGate
{
    public function assertAllowed(string $operation): never
    {
        throw new DomainException('Paid Media mutation '.$operation.' is blocked until Finance F1, approval and canonical Workflow acceptance.');
    }
}

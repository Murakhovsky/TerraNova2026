<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;

interface InboundLeadAssignment
{
    /** Called only inside the new public intake transaction, after idempotency claim. */
    public function allocate(string $org): ?int;
}

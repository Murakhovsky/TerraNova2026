<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;

/** Only callers that have resolved live Sales scope may supply a population. */
interface ScopedClientCaseReadModelFactoryInterface
{
    public function forOwner(string $organizationId,?int $ownerId):ClientCaseReadModelInterface;
}

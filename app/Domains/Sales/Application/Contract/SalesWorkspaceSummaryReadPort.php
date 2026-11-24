<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;

/** Complete current open-pipeline projection; never computed from a paginated deal list. */
interface SalesWorkspaceSummaryReadPort
{
    public function summary(string $organizationId,?int $ownerId=null,?string $pipelineId=null):array;
}

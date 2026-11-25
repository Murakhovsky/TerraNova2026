<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;

/** Internal bounded placement-cohort candidates, to be authorized by Sales application service. */
interface SalesOrderReportReadPort
{
    public function reportCandidates(string $org,?int $owner,string $from,string $to):array;
}

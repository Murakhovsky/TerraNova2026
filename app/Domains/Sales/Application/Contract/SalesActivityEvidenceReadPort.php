<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;

interface SalesActivityEvidenceReadPort
{
    public function activity(string $org,int $deal,int $id):?array;
    public function propertyMatch(string $org,int $deal,int $property):?array;
}

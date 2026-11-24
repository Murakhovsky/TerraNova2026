<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;

interface SalesTeamScopeInterface
{
    /** @return list<int> Authorized active members of one explicit team. */
    public function members(string $org,int $actor,string $team):array;
}

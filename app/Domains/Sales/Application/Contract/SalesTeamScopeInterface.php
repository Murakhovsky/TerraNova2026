<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;

interface SalesTeamScopeInterface
{
    /** @return list<int> Authorized active members of one explicit team. */
    public function members(string $org,int $actor,string $team):array;
    /** @return list<int> Union of active members of all teams led by actor, including self. */
    public function ledMembers(string $org,int $actor):array;
}

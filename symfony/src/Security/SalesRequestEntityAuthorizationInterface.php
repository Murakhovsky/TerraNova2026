<?php
declare(strict_types=1);
namespace App\Security;

/** Shared Workspace entity projections must verify the live request actor. */
interface SalesRequestEntityAuthorizationInterface
{
    public function entity(string $org,string $kind,int $id):bool;
}

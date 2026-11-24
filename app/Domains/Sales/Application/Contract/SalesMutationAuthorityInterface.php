<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;

/** Transaction-bound authorization. Implementations must lock authoritative rows until commit. */
interface SalesMutationAuthorityInterface
{
    public function actor(string $org,int $actor,string $permission='sales.workspace.use'):void;
    public function entity(string $org,int $actor,string $kind,int $id):void;
    public function targetOwner(string $org,int $actor,int $owner):void;
    public function contact(string $org,int $actor,?string $email,?string $phone):void;
    public function casePerson(string $org,int $actor,int $case):void;
    public function leadContact(string $org,int $actor,int $lead):void;
    public function propertyMatchDeal(string $org,int $match):int;
    public function offerDeal(string $org,string $offer):int;
}

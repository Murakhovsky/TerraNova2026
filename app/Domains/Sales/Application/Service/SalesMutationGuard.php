<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Service;

use DomainException;
use Domains\Sales\Application\Contract\SalesMutationAuthorityInterface;
use Kernel\Transaction\Contract\TransactionManagerInterface;

/** Reuse the native transaction manager; authorization and operation share one commit boundary. */
final readonly class SalesMutationGuard
{
    public function __construct(private TransactionManagerInterface $transactions,private SalesMutationAuthorityInterface $authority) {}

    public function run(string $org,int $actor,callable $operation,?string $kind=null,?int $id=null,
        ?int $targetOwner=null,string $permission='sales.workspace.use',?string $offer=null):mixed
    {
        return $this->transactions->transactional(function()use($org,$actor,$operation,$kind,$id,$targetOwner,$permission,$offer):mixed{
            try {
            $this->authority->actor($org,$actor,$permission);
            if ($offer!==null) {
                $deal=$this->authority->offerDeal($org,$offer);
                if ($id!==null&&($kind!=='deal'||$deal!==$id)) throw new DomainException('Sales entity unavailable.');
                $kind='deal';$id=$deal;
            }
            if ($kind!==null) $this->authority->entity($org,$actor,$kind,$id??0);
            if ($targetOwner!==null) $this->authority->targetOwner($org,$actor,$targetOwner);
            } catch (DomainException $error) {
                throw new SalesMutationDenied('Sales mutation denied.',0,$error);
            }
            return $operation();
        });
    }
}

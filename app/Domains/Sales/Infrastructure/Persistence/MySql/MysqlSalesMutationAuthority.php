<?php
declare(strict_types=1);
namespace Domains\Sales\Infrastructure\Persistence\MySql;

use DomainException;
use LogicException;
use PDO;
use Domains\Sales\Application\Contract\SalesMutationAuthorityInterface;
use Domains\Sales\Application\Contract\SalesWorkspaceScopeInterface;
use Domains\Sales\Application\Contract\SalesAccessControlInterface;

/** No detached read snapshot can authorize a write. All authority checks use current locking reads. */
final readonly class MysqlSalesMutationAuthority implements SalesMutationAuthorityInterface
{
    public function __construct(private PDO $connection,private SalesWorkspaceScopeInterface $scope,
        private SalesAccessControlInterface $access) {}

    public function actor(string $org,int $actor,string $permission='sales.workspace.use'):void
    {
        $this->transaction();
        $this->scope->resolve($org,$actor,'self',null);
        if (!$this->access->hasCapability($org,$actor,$permission)) throw new DomainException('Sales mutation denied.');
    }

    public function entity(string $org,int $actor,string $kind,int $id):void
    {
        $this->transaction();
        if ($id<1||!in_array($kind,['lead','deal'],true)) throw new DomainException('Sales entity unavailable.');
        $table=$kind==='lead'?'tn_leads':'tn_client_cases';
        $fields=$kind==='lead'?'assigned_user_id,client_case_id':'assigned_user_id';
        $q=$this->connection->prepare('SELECT '.$fields.' FROM '.$table.' WHERE organization_id=? AND id=? FOR UPDATE');
        $q->execute([$org,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new DomainException('Sales entity unavailable.');
        $owner=(int)($row['assigned_user_id']??0);
        try {
            $this->scope->resolve($org,$actor,$owner>0?'owner':'tenant',$owner>0?$owner:null);
        } catch (DomainException) {
            try {$this->scope->resolve($org,$actor,'tenant',null);}
            catch (DomainException) {throw new DomainException('Sales entity unavailable.');}
        }
        if ($kind==='lead'&&(int)($row['client_case_id']??0)>0) $this->entity($org,$actor,'deal',(int)$row['client_case_id']);
    }

    public function targetOwner(string $org,int $actor,int $owner):void
    {
        $this->transaction();
        $this->scope->resolve($org,$actor,'owner',$owner);
        if ($owner!==$actor&&!$this->access->hasCapability($org,$actor,'sales.deal.assign'))
            throw new DomainException('Sales assignment denied.');
    }

    public function propertyMatchDeal(string $org,int $match):int
    {
        $this->transaction();
        $q=$this->connection->prepare('SELECT client_case_id FROM tn_client_case_property_matches WHERE organization_id=? AND id=? FOR UPDATE');
        $q->execute([$org,$match]);$deal=(int)$q->fetchColumn();
        if ($deal<1) throw new \Domains\Sales\Application\Service\SalesMutationDenied('Sales mutation denied.');
        return $deal;
    }

    public function offerDeal(string $org,string $offer):int
    {
        $this->transaction();
        $q=$this->connection->prepare('SELECT deal_id FROM sales_offers WHERE organization_id=? AND offer_id=? FOR UPDATE');
        $q->execute([$org,$offer]);$deal=(int)$q->fetchColumn();
        if ($deal<1) throw new DomainException('Sales entity unavailable.');
        return $deal;
    }

    private function transaction():void
    {
        if (!$this->connection->inTransaction()) throw new LogicException('Sales write authorization requires an active transaction.');
    }
}

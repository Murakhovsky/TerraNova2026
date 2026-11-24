<?php
declare(strict_types=1);
namespace App\Application\Sales;

use App\Application\Capability\Contract\CapabilityInvocationPolicyInterface;
use DomainException;
use Domains\Sales\Application\Contract\SalesAccessControlInterface;
use Domains\Sales\Application\Contract\SalesOfferRepositoryInterface;
use Domains\Sales\Application\Service\SalesEntityScopeService;
use Domains\Sales\Model\SalesCapability;
use Kernel\Module\CapabilityContract;
use Kernel\Tenant\Model\TenantContext;

final readonly class SalesDirectInvocationPolicy implements CapabilityInvocationPolicyInterface
{
    public function __construct(private SalesEntityScopeService $scope,private SalesAccessControlInterface $access,
        private SalesOfferRepositoryInterface $offers) {}
    public function supports(string $owner):bool {return $owner==='sales';}

    public function authorize(TenantContext $actor,CapabilityContract $contract,array $input):void
    {
        $org=$actor->organizationId()->value();$raw=$actor->userId()->value();
        if (!ctype_digit($raw)||(int)$raw<1||(string)(int)$raw!==$raw) throw new DomainException('Sales access denied.');
        $user=(int)$raw;$this->scope->resolve($org,$user);
        $id=$contract->id;
        $can=fn(SalesCapability $permission):bool=>$this->access->hasCapability($org,$user,$permission->value);
        // The scoped aggregate queries enforce their own live owner/tenant rules.
        if (str_starts_with($id,'sales.workspace.')||str_starts_with($id,'sales.analytics.')||$id==='sales.reports.data.read') return;
        if ($id==='sales.team.manage'||$id==='sales.team.read') {
            if (!$can(SalesCapability::AdminTeamsManage)) throw new DomainException('Sales team administration denied.');
            return;
        }
        if (str_starts_with($id,'sales.crm.inbox.')) {
            if (!$can(SalesCapability::AdminIntegrationsManage)) throw new DomainException('Sales integration administration denied.');
            return;
        }
        if ($contract->kind==='command'&&!$can(SalesCapability::WorkspaceUse)) throw new DomainException('Sales mutation denied.');
        if (in_array($id,['sales.lead.create','sales.lead.intake'],true)) return;
        if (isset($input['offer_id'])) {
            $offer=$this->offers->get($org,(string)$input['offer_id']);
            $deal=(int)($offer['deal_id']??0);
            if ($offer===null||$deal<1||(isset($input['deal_id'])&&(int)$input['deal_id']!==$deal)) throw new DomainException('Sales entity unavailable.');
            $this->scope->requireEntity($org,$user,'deal',$deal);
            return;
        }
        if (isset($input['deal_id'])) {
            $this->scope->requireEntity($org,$user,'deal',(int)$input['deal_id']);
            return;
        }
        if (isset($input['lead_id'])) {
            $this->scope->requireEntity($org,$user,'lead',(int)$input['lead_id']);
            return;
        }
        if ($id==='sales.activity.overdue.read') {
            $this->scope->resolve($org,$user,'tenant');
            return;
        }
        throw new DomainException('Sales direct invocation has no scoped authorization policy.');
    }
}

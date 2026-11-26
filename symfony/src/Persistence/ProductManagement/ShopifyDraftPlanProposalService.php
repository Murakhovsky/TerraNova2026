<?php
declare(strict_types=1);
namespace App\Persistence\ProductManagement;

use App\Persistence\Federation\FederationCapabilityBindingResolver;
use App\Persistence\Federation\FederationGoalStore;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\ProductManagement\Automation\Action\ShopifyDraftWritebackHandler;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyDraftWritebackService;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;
use Platform\Orchestration\Goal\GoalSpecification;

final readonly class ShopifyDraftPlanProposalService
{
    public const CRITERION='product_management.shopify_draft_verified';
    public function __construct(private Connection $db,private FederationGoalStore $goals,
        private FederationCapabilityBindingResolver $bindings,private ShopifyDraftWritebackService $writer) {}
    public function propose(TenantContext $actor,string $conn,string $product,string $digest):array
    {
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!$this->bindings->isTenantModuleEnabled($actor,'federation'))throw new DomainException('Enabled tenant manager required.');
        $org=$actor->organizationId()->value();$cap=$this->bindings->requireExecutable($actor,ShopifyDraftWritebackHandler::TYPE);
        $input=['target_type'=>'shopify_draft_writeback','target_id'=>$product,'parameters'=>['connection_id'=>$conn,'payload_digest'=>$digest]];
        (new CapabilityJsonInputValidator())->validate($cap,$input);
        if(!hash_equals($digest,ShopifyDraftWritebackService::digest($this->writer->preview($org,$conn,$product))))
            throw new DomainException('Preview changed; independent approval must use current pinned payload.');
        $suffix=substr(hash('sha256',$org."\0shopify-draft\0".$conn."\0".$product."\0".$digest),0,24);
        $goal='goal-'.$suffix;$plan='plan-'.$suffix;
        return $this->db->transactional(function()use($actor,$org,$goal,$plan,$cap,$input,$conn,$product):array{
            if($this->db->fetchOne('SELECT 1 FROM pm_shopify_outbound_receipts WHERE organization_id=? AND connection_id=? AND product_id=?',[$org,$conn,$product])!==false)
                throw new DomainException('Prior provider claim exists; reconcile instead of proposing again.');
            $spec=new GoalSpecification($goal,$org,$actor->userId()->value(),'Create and independently verify one Shopify draft',
                [['id'=>self::CRITERION,'operator'=>'equals','expected'=>1]],[$cap->id],1,null,null,'',[],['independent_plan','independent_action'],'always');
            $this->goals->createGoal($actor,$spec);
            $this->goals->proposePlan($actor,$plan,$goal,[['id'=>'shopify-draft-writeback','capability_id'=>$cap->id,'capability_version'=>$cap->version,'input'=>$input]]);
            return ['goal_id'=>$goal,'plan_id'=>$plan,'state'=>'proposed','approval_required'=>true,'execution_started'=>false];
        });
    }
}

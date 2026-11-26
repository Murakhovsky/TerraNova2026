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
    public const PUBLICATION_CRITERION='product_management.shopify_publication_verified';
    public function __construct(private Connection $db,private FederationGoalStore $goals,
        private FederationCapabilityBindingResolver $bindings,private ShopifyDraftWritebackService $writer) {}
    public function propose(TenantContext $actor,string $conn,string $product,string $digest,array $collections=[],?string $publication=null,?string $resync=null):array
    {
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!$this->bindings->isTenantModuleEnabled($actor,'federation'))throw new DomainException('Enabled tenant manager required.');
        $org=$actor->organizationId()->value();$cap=$this->bindings->requireExecutable($actor,$publication===null?ShopifyDraftWritebackHandler::TYPE:ShopifyDraftWritebackHandler::PUBLISH);
        $input=['target_type'=>$publication===null?'shopify_draft_writeback':'shopify_channel_publish','target_id'=>$product,'parameters'=>['connection_id'=>$conn,'payload_digest'=>$digest]];
        if($resync!==null)$input['parameters']['resync_id']=$resync;
        if($publication!==null)$input['parameters']['publication_gid']=$publication;
        if($collections!==[])$input['parameters']['collection_gids']=$collections;
        (new CapabilityJsonInputValidator())->validate($cap,$input);
        if(!hash_equals($digest,ShopifyDraftWritebackService::digest($this->writer->preview($org,$conn,$product,$collections,$publication,$resync))))
            throw new DomainException('Preview changed; independent approval must use current pinned payload.');
        $suffix=substr(hash('sha256',$org."\0shopify-draft\0".$conn."\0".$product."\0".$digest),0,24);
        $goal='goal-'.$suffix;$plan='plan-'.$suffix;
        return $this->db->transactional(function()use($actor,$org,$goal,$plan,$cap,$input,$conn,$product,$digest,$publication):array{
            $this->writer->assertAvailable($org,$conn,$product,$digest);
            $spec=new GoalSpecification($goal,$org,$actor->userId()->value(),$publication===null?'Write and independently verify one Shopify draft revision':'Independently publish and verify one active Shopify Product',
                [['id'=>$publication===null?self::CRITERION:self::PUBLICATION_CRITERION,'operator'=>'equals','expected'=>1]],[$cap->id],1,null,null,'',[],['independent_plan','independent_action'],'always');
            $this->goals->createGoal($actor,$spec);
            $this->goals->proposePlan($actor,$plan,$goal,[['id'=>'shopify-draft-writeback','capability_id'=>$cap->id,'capability_version'=>$cap->version,'input'=>$input]]);
            return ['goal_id'=>$goal,'plan_id'=>$plan,'state'=>'proposed','approval_required'=>true,'execution_started'=>false];
        });
    }
}

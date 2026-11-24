<?php
declare(strict_types=1);

namespace App\Persistence\ProductManagement;

use App\Persistence\Federation\FederationGoalStore;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyImportApplyService;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyObservationStore;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\GoalSpecification;

/**
 * Canonical one-preview/one-Goal/Plan proposal; NEVER approves or dispatches.
 * Shopify observation ownership is unchanged until separately authorized.
 */
final readonly class ShopifyImportPlanProposalService
{
    public const CAPABILITY='product_management.shopify.import.apply';
    public const CRITERION='product_management.shopify_variants_verified';

    public function __construct(
        private Connection $db,
        private ShopifyObservationStore $observations,
        private FederationGoalStore $goals,
        private ActiveModuleResolver $modules,
    ) {}

    /** @return array<string,mixed> */
    public function propose(TenantContext $actor,string $previewId,string $sourceHash,string $digest):array
    {
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::MANAGE)
            ||!preg_match('/^[a-f0-9]{32}$/D',$previewId)
            ||!preg_match('/^[a-f0-9]{64}$/D',$sourceHash)
            ||!preg_match('/^[a-f0-9]{64}$/D',$digest)) {
            throw new DomainException('Shopify Plan requires a tenant manager and immutable candidate hashes.');
        }
        $org=$actor->organizationId()->value();
        if(!$this->modules->isEnabled($org,'product_management')) {
            throw new DomainException('Product Management inactive for tenant.');
        }
        return $this->db->transactional(function()use($actor,$org,$previewId,$sourceHash,$digest):array{
            $row=$this->db->fetchAssociative(
                'SELECT * FROM pm_shopify_import_previews WHERE organization_id=:org AND preview_id=:preview FOR UPDATE',
                ['org'=>$org,'preview'=>$previewId]);
            if(!$row||$row['state']!=='staged'||$row['plan_id']!==null
                ||!hash_equals((string)$row['source_hash'],$sourceHash)
                ||!hash_equals((string)$row['normalized_digest'],$digest)) {
                throw new DomainException('Shopify import candidate was already planned or changed.');
            }
            // Business preflight gives a clear reconciliation reason. A
            // MySQL UNIQUE generated-column claim also fences the race
            // between two concurrent proposals of different snapshots.
            $other=$this->db->fetchOne(
                'SELECT preview_id FROM pm_shopify_import_previews
                 WHERE organization_id=:org AND connection_id=:conn AND product_gid=:gid
                   AND preview_id<>:preview AND plan_id IS NOT NULL LIMIT 1',
                ['org'=>$org,'conn'=>$row['connection_id'],
                 'gid'=>$row['product_gid'],'preview'=>$previewId]);
            if($other!==false) {
                throw new DomainException('Shopify Product identity is already claimed by a different planned preview; manual reconciliation required.');
            }
            $candidate=ShopifyImportApplyService::candidate($row);
            if(count($candidate['variants'])<1||count($candidate['variants'])>100) {
                throw new DomainException('Invalid Shopify Plan Variant count.');
            }
            $connection=$this->observations->connection($org,(string)$row['connection_id']);
            if(!in_array($connection['mode'],['shopify_authoritative','observation_only'],true)) {
                throw new DomainException('Shopify import requires a read-only connection.');
            }
            $observed=$this->observations->productSnapshot($org,(string)$row['connection_id'],(string)$row['product_gid']);
            if($observed===null||(int)$observed['is_complete']!==1
                ||!hash_equals((string)$observed['payload_hash'],$sourceHash)) {
                throw new DomainException('Shopify source changed after preview; create a new candidate.');
            }
            if($this->observations->mapping($org,(string)$row['connection_id'],'product',(string)$row['product_gid'])!==null) {
                throw new DomainException('Shopify source already mapped to a native Product.');
            }
            foreach($candidate['variants'] as $variant) {
                if($this->observations->mapping($org,(string)$row['connection_id'],'variant',$variant['gid'])!==null
                    ||$this->db->fetchOne('SELECT variant_id FROM pm_variants WHERE organization_id=:org AND sku=:sku',
                        ['org'=>$org,'sku'=>$variant['sku']])!==false) {
                    throw new DomainException('Shopify candidate SKU or Variant mapping conflicts with canonical state.');
                }
            }
            $suffix=substr(hash('sha256',$org."\0shopify-import\0".$previewId),0,24);
            $goalId='goal-'.$suffix;$planId='plan-'.$suffix;
            $count=count($candidate['variants']);
            $goal=new GoalSpecification($goalId,$org,$actor->userId()->value(),
                'Verify '.$count.' native Shopify Variants from approved preview '.$previewId,
                [['id'=>self::CRITERION,'operator'=>'at_least','expected'=>$count]],
                [self::CAPABILITY],1,null,null,'',
                [],['independent_plan','independent_action'],'always');
            $this->goals->createGoal($actor,$goal);
            $this->goals->proposePlan($actor,$planId,$goalId,[
                ['id'=>'shopify-product-import-apply',
                    'capability_id'=>self::CAPABILITY,'capability_version'=>'1.0.0',
                    'input'=>['target_type'=>'shopify_import_preview','target_id'=>$previewId,
                        'parameters'=>['source_hash'=>$sourceHash,'normalized_digest'=>$digest]]],
            ]);
            $updated=$this->db->executeStatement(
                'UPDATE pm_shopify_import_previews SET goal_id=:goal,plan_id=:plan
                 WHERE organization_id=:org AND preview_id=:preview AND state=:staged
                   AND goal_id IS NULL AND plan_id IS NULL',
                ['goal'=>$goalId,'plan'=>$planId,'org'=>$org,'preview'=>$previewId,'staged'=>'staged']);
            if($updated!==1)throw new DomainException('Competing Shopify import Plan was rejected.');
            return ['preview_id'=>$previewId,'goal_id'=>$goalId,'plan_id'=>$planId,
                'ready_variants'=>$count,'state'=>'proposed',
                'requires'=>'independent_plan_and_action_approvals'];
        });
    }
}

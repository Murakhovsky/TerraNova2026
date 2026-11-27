<?php
declare(strict_types=1);

namespace App\Web\Experience\Extension\Provider;

use App\Web\Experience\Extension\Contract\EntityLinkProviderInterface;
use App\Web\Experience\Extension\Model\EntityLink;
use App\Web\Experience\Unified\Business\BusinessRecordProvider;
use App\Web\Experience\Unified\Business\WorkspaceCapabilityRead;
use App\Web\Experience\Model\EntityRef;
use App\Web\Experience\Extension\Contract\CommandProviderInterface;
use App\Web\Experience\Extension\Contract\NavigationProviderInterface;
use App\Web\Experience\Extension\Contract\SearchProviderInterface;
use App\Web\Experience\Extension\Contract\WorkspaceProviderInterface;
use App\Web\Experience\Extension\Model\NavigationContribution;
use App\Web\Experience\Extension\Model\SearchResult;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\Model\WorkspaceDefinition;
use App\Web\Experience\Search\SearchResultMatcher;
use App\Web\Experience\Shell\ShellCommandItem;

/** Navigation is published only for an active Product Management tenant module. */
final readonly class ProductManagementWebProvider implements
    NavigationProviderInterface, SearchProviderInterface, CommandProviderInterface, WorkspaceProviderInterface, BusinessRecordProvider, EntityLinkProviderInterface
{
    public function __construct(private SearchResultMatcher $matcher, private WorkspaceCapabilityRead $read) {}

    public function serviceId():string { return 'productManagementNavigationContributor'; }

    public function navigation(WebExtensionContext $context):array
    {
        return [
            new NavigationContribution('product-management','Product Management','/product-management','PM',35),
            new NavigationContribution('pm-catalog','Каталог','/product-management',priority:10,parentKey:'product-management'),
            new NavigationContribution('pm-categories','Категорії','/product-management/categories',priority:20,parentKey:'product-management'),
            new NavigationContribution('pm-stock','Склади','/product-management/stock',priority:30,parentKey:'product-management'),
            new NavigationContribution('pm-import','Імпорт CSV','/product-management/import',priority:40,parentKey:'product-management'),
        ];
    }

    public function search(WebExtensionContext $context,string $query,int $limit=10):array
    {
        $limit=max(1,min(50,$limit));
        $navigation=$this->matcher->match([
            new SearchResult('pm.catalog','Product catalog','/product-management','workspace','Native COS Product and SKU'),
            new SearchResult('pm.categories','Product categories','/product-management/categories','workspace','Tenant category hierarchy'),
            new SearchResult('pm.stock','Product stock','/product-management/stock','workspace','Ledger and native balances'),
            new SearchResult('pm.import','CSV import preview','/product-management/import','workspace','Immutable staged rows and independent Plan approval'),
        ], $query, $limit);
        if (trim($query)==='') return $navigation;
        try {
            $page=$this->records($context,'pm.catalog',trim($query),1);
            return array_slice([...$page['items'],...$navigation],0,$limit);
        } catch (\Throwable $e) {
            // Unavailable or unauthorized entity reads must not break navigation.
            error_log('cos.workspace.pm.catalog.search_unavailable '.$e::class);
            return $navigation;
        }
    }

    public function commands(WebExtensionContext $context):array
    {
        return [new ShellCommandItem('pm.open','Open Product Catalog','/product-management','navigation','Product Management')];
    }

    public function workspaces(WebExtensionContext $context):array
    {
        return [
            new WorkspaceDefinition('pm.catalog','Product catalog','/product-management','product_management.product',10),
            new WorkspaceDefinition('pm.categories','Product categories','/product-management/categories',null,20),
            new WorkspaceDefinition('pm.stock','Stock workspace','/product-management/stock',null,30),
            new WorkspaceDefinition('pm.import','CSV import','/product-management/import',null,40),
            new WorkspaceDefinition('pm.dashboard','Product analytics','/product-management/dashboard',null,50),
            new WorkspaceDefinition('pm.prices','Price lists','/product-management/price-lists',null,60),
            new WorkspaceDefinition('pm.attributes','Product attributes','/product-management/attributes',null,70),
        ];
    }

    public function supportsRecords(string $workspaceId): bool { return $workspaceId==='pm.catalog'; }

    public function records(WebExtensionContext $context,string $workspaceId,string $query,int $page):array
    {
        if (!$this->supportsRecords($workspaceId)) throw new \InvalidArgumentException('Unsupported workspace.');
        if (preg_match('/^[a-f0-9]{32}$/D',$query)) {
            $detail=$this->record($context,$workspaceId,new EntityRef('product_management.product',$query));
            return ['items'=>$detail===null?[]:[$detail['hit']],'has_more'=>false,'notice'=>null];
        }
        // A product may have multiple SKU rows. Preserve independent row IDs.
        $data=$this->read->query($context,'product_management.product.search',['q'=>$query,'limit'=>21,'offset'=>($page-1)*20]);
        $rows=$data['items']??[];
        $hits=[];
        foreach (array_slice($rows,0,20) as $row) {
            $id=(string)($row['product_id']??'');
            if (!preg_match('/^[a-f0-9]{32}$/D',$id)) continue;
            $hits[]=WorkspaceCapabilityRead::hit('product_management.product',$id,(string)$row['name'],
                '/product-management/products/'.$id,(string)$row['sku'].' · '.(string)$row['status'],(string)$row['variant_id']);
        }
        return ['items'=>$hits,'has_more'=>count($rows)>20,'notice'=>null];
    }

    public function record(WebExtensionContext $context,string $workspaceId,EntityRef $entity):?array
    {
        if (!$this->supportsRecords($workspaceId) || $entity->type!=='product_management.product'
            || !preg_match('/^[a-f0-9]{32}$/D',$entity->id)) return null;
        $data=$this->read->query($context,'product_management.product.get',['product_id'=>$entity->id]);
        $row=$data['product']??null;
        if (($data['found']??false)!==true || !is_array($row) || ($row['product_id']??null)!==$entity->id) return null;
        return [
            'hit'=>WorkspaceCapabilityRead::hit($entity->type,$entity->id,(string)$row['name'],'/product-management/products/'.$entity->id,(string)$row['status']),
            'facts'=>WorkspaceCapabilityRead::facts($row,['status'=>'Status','product_type'=>'Product type','name'=>'Name']),
            'tables'=>[WorkspaceCapabilityRead::table('SKU / variants',['sku'=>'SKU','status'=>'Status','uom'=>'Unit'],$row['variants']??[],'First 50 variants; manage prices, stock and media in the Product workspace.')],
        ];
    }

    public function link(WebExtensionContext $context,EntityRef $entity):?EntityLink
    {
        if ($entity->type!=='product_management.product'||!preg_match('/^[a-f0-9]{32}$/D',$entity->id)) return null;
        return new EntityLink('Product','/product-management/products/'.$entity->id);
    }

}

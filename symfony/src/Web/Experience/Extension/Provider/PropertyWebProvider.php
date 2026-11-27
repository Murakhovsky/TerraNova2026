<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Provider;

use App\Web\Experience\Extension\Contract\EntityLinkProviderInterface;
use App\Web\Experience\Extension\Model\EntityLink;
use App\Web\Experience\Unified\Business\BusinessRecordProvider;
use App\Web\Experience\Unified\Business\WorkspaceCapabilityRead;
use App\Web\Experience\Extension\Contract\CommandProviderInterface;
use App\Web\Experience\Extension\Contract\NavigationProviderInterface;
use App\Web\Experience\Extension\Contract\SearchProviderInterface;
use App\Web\Experience\Extension\Contract\WorkspaceProviderInterface;
use App\Web\Experience\Extension\Model\NavigationContribution;
use App\Web\Experience\Extension\Model\SearchResult;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\Model\WorkspaceDefinition;
use App\Web\Experience\Model\EntityRef;
use App\Web\Experience\Search\SearchResultMatcher;
use App\Web\Experience\Shell\ShellCommandItem;
use App\Application\Experience\Search\Contract\PropertyEntitySearchInterface;

final class PropertyWebProvider implements NavigationProviderInterface, SearchProviderInterface, CommandProviderInterface, WorkspaceProviderInterface, BusinessRecordProvider, EntityLinkProviderInterface
{
    public function __construct(
        private readonly SearchResultMatcher $matcher,
        private readonly PropertyEntitySearchInterface $entitySearch,
        private readonly WorkspaceCapabilityRead $read,
    ) {
    }

    public function serviceId(): string
    {
        return 'propertyNavigationContributor';
    }

    public function navigation(WebExtensionContext $context): array
    {
        return [
            new NavigationContribution('properties', 'Properties', '/property/manage', 'RE', 40),
            new NavigationContribution('objects', 'Inventory', '/property/manage', priority: 10, parentKey: 'properties'),
            new NavigationContribution('listing', 'Listing', '/property/listing', priority: 20, parentKey: 'properties'),
            new NavigationContribution('locations', 'Locations', '/property/map', priority: 30, parentKey: 'properties'),
            new NavigationContribution('submissions', 'Moderation', '/property/submissions', priority: 40, parentKey: 'properties'),
            new NavigationContribution('spatial', '3D / Spatial', '/spatial/manage', priority: 50, parentKey: 'properties'),
            new NavigationContribution('catalog', 'Public Catalog', '/property/catalog', priority: 60, parentKey: 'properties'),
        ];
    }

    public function search(WebExtensionContext $context, string $query, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $navigation = $this->matcher->match([
            new SearchResult('property.search.inventory', 'Property Inventory', '/property/manage', 'workspace', 'Properties'),
            new SearchResult('property.search.listing', 'Property Listing', '/property/listing', 'workspace', 'Listings'),
            new SearchResult('property.search.locations', 'Property Locations', '/property/map', 'workspace', 'Map'),
            new SearchResult('property.search.moderation', 'Property Moderation', '/property/submissions', 'workspace', 'Submissions'),
            new SearchResult('property.search.spatial', 'Spatial Workspace', '/spatial/manage', 'workspace', '3D / Spatial'),
            new SearchResult('property.search.catalog', 'Public Property Catalog', '/property/catalog', 'workspace', 'Catalog'),
        ], $query, $limit);

        $query = trim($query);
        if ($query === '') {
            return $navigation;
        }

        $entities = array_map(
            static fn ($hit): SearchResult => new SearchResult(
                id: $hit->id,
                label: $hit->label,
                path: $hit->path,
                kind: 'entity',
                subtitle: $hit->subtitle,
                entity: new EntityRef($hit->entityType, $hit->entityId),
                score: $hit->score,
            ),
            $this->entitySearch->search($context->organizationId, $query, $limit),
        );

        return array_slice([...$entities, ...$navigation], 0, $limit);
    }

    public function commands(WebExtensionContext $context): array
    {
        return [
            new ShellCommandItem('property.open', 'Open Property Inventory', '/property/manage', 'navigation', 'Property'),
            new ShellCommandItem('property.map', 'Open Property Locations', '/property/map', 'navigation', 'Property'),
            new ShellCommandItem('property.spatial', 'Open Spatial Workspace', '/spatial/manage', 'navigation', 'Property'),
        ];
    }

    public function workspaces(WebExtensionContext $context): array
    {
        return [
            new WorkspaceDefinition('property.asset', 'Property Workspace', '/property/manage', 'property.asset', 10),
            new WorkspaceDefinition('property.listing','Property listings','/property/listing',null,15),
            new WorkspaceDefinition('property.submission', 'Property Submission', '/property/submissions', 'property.submission', 20),
            new WorkspaceDefinition('property.spatial', 'Spatial Workspace', '/spatial/manage', 'spatial.scene', 30),
        ];
    }

    public function supportsRecords(string $workspaceId):bool { return $workspaceId==='property.asset'; }

    public function records(WebExtensionContext $context,string $workspaceId,string $query,int $page):array
    {
        if (!$this->supportsRecords($workspaceId)) throw new \InvalidArgumentException('Unsupported workspace.');
        if (preg_match('/^PROP-[A-F0-9]{20}$/D',$query)) {
            $detail=$this->record($context,$workspaceId,new EntityRef('property.asset',$query));
            return ['items'=>$detail===null?[]:[$detail['hit']],'has_more'=>false,'notice'=>null];
        }
        $data=$this->read->query($context,'property.asset.search',['q'=>$query,'page'=>$page,'per_page'=>20]);
        $hits=[];
        foreach ($data['items']??[] as $row) {
            $hit=$this->assetHit($row);
            if ($hit!==null) $hits[]=$hit;
        }
        return ['items'=>$hits,'has_more'=>(int)($data['pagination']['total']??0)>$page*20,'notice'=>null];
    }

    public function record(WebExtensionContext $context,string $workspaceId,EntityRef $entity):?array
    {
        if (!$this->supportsRecords($workspaceId)||$entity->type!=='property.asset'||!preg_match('/^PROP-[A-F0-9]{20}$/D',$entity->id)) return null;
        $row=$this->read->query($context,'property.asset.get',['reference'=>$entity->id]);
        $hit=$this->assetHit($row);
        if ($hit===null||$hit->entity?->id!==$entity->id) return null;
        return ['hit'=>$hit,'facts'=>[
            ...WorkspaceCapabilityRead::facts($row['property']??[],['type_code'=>'Type','formatted_address'=>'Address','lifecycle'=>'Lifecycle']),
            ...WorkspaceCapabilityRead::facts($row['inventory']??[],['status'=>'Inventory status']),
            ...WorkspaceCapabilityRead::facts($row['listing']??[],['title'=>'Listing','status'=>'Listing status']),
        ],'tables'=>[]];
    }

    private function assetHit(array $row):?SearchResult
    {
        $property=$row['property']??[];
        $id=(string)($property['asset_id']??'');
        if (!preg_match('/^PROP-[A-F0-9]{20}$/D',$id)) return null;
        $label=(string)($row['listing']['title']??$property['formatted_address']??$id);
        return WorkspaceCapabilityRead::hit('property.asset',$id,$label?:$id,
            '/workspace/unified/property?reference='.rawurlencode($id),(string)($property['type_code']??''));
    }

    public function link(WebExtensionContext $context,EntityRef $entity):?EntityLink
    {
        if ($entity->type!=='property.asset'||!preg_match('/^PROP-[A-F0-9]{20}$/D',$entity->id)) return null;
        return new EntityLink('Property','/workspace/unified/property?reference='.rawurlencode($entity->id));
    }

}

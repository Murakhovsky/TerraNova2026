<?php
declare(strict_types=1);

namespace App\Web\Experience\Extension\Provider;

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
    NavigationProviderInterface, SearchProviderInterface, CommandProviderInterface, WorkspaceProviderInterface
{
    public function __construct(private SearchResultMatcher $matcher) {}

    public function serviceId():string { return 'productManagementNavigationContributor'; }

    public function navigation(WebExtensionContext $context):array
    {
        return [
            new NavigationContribution('product-management','Product Management','/product-management','PM',35),
            new NavigationContribution('pm-catalog','Каталог','/product-management',priority:10,parentKey:'product-management'),
            new NavigationContribution('pm-categories','Категорії','/product-management/categories',priority:20,parentKey:'product-management'),
        ];
    }

    public function search(WebExtensionContext $context,string $query,int $limit=10):array
    {
        return $this->matcher->match([
            new SearchResult('pm.catalog','Product catalog','/product-management','workspace','Native COS Product and SKU'),
            new SearchResult('pm.categories','Product categories','/product-management/categories','workspace','Tenant category hierarchy'),
        ],$query,$limit);
    }

    public function commands(WebExtensionContext $context):array
    {
        return [new ShellCommandItem('pm.open','Open Product Catalog','/product-management','navigation','Product Management')];
    }

    public function workspaces(WebExtensionContext $context):array
    {
        return [
            new WorkspaceDefinition('pm.catalog','Product catalog','/product-management',null,10),
            new WorkspaceDefinition('pm.categories','Product categories','/product-management/categories',null,20),
        ];
    }
}

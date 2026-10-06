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

final readonly class CapitalMarketsWebProvider implements NavigationProviderInterface,SearchProviderInterface,CommandProviderInterface,WorkspaceProviderInterface
{
    public function __construct(private SearchResultMatcher $matcher){}

    public function serviceId():string{return 'capitalMarketsNavigationContributor';}

    public function navigation(WebExtensionContext $context):array
    {
        return [
            new NavigationContribution('capital-markets','Capital Markets','/capital-markets','CM',35),
            new NavigationContribution('capital-markets-overview','Overview','/capital-markets',priority:10,parentKey:'capital-markets'),
            new NavigationContribution('capital-markets-instruments','Instruments','/capital-markets/instruments',priority:20,parentKey:'capital-markets'),
            new NavigationContribution('capital-markets-relationships','Relationships','/capital-markets/relationships',priority:30,parentKey:'capital-markets'),
            new NavigationContribution('capital-markets-venues','Venues','/capital-markets/venues',priority:40,parentKey:'capital-markets'),
            new NavigationContribution('capital-markets-market-data','Market Data','/capital-markets/market-data',priority:50,parentKey:'capital-markets'),
        ];
    }

    public function search(WebExtensionContext $context,string $query,int $limit=10):array
    {
        return $this->matcher->match([
            new SearchResult('capital_markets.search.overview','Capital Markets','/capital-markets','workspace','Financial instrument foundation'),
            new SearchResult('capital_markets.search.instruments','Capital Markets Instruments','/capital-markets/instruments','workspace','Instrument identity registry'),
            new SearchResult('capital_markets.search.relationships','Capital Markets Relationships','/capital-markets/relationships','workspace','Economic relationship graph'),
            new SearchResult('capital_markets.search.venues','Capital Markets Venues','/capital-markets/venues','workspace','Venue registry and capabilities'),
            new SearchResult('capital_markets.search.market_data','Capital Markets Market Data','/capital-markets/market-data','workspace','Sources, subscriptions, health and trusted market state'),
        ],$query,$limit);
    }

    public function commands(WebExtensionContext $context):array
    {
        return [
            new ShellCommandItem('capital_markets.open','Open Capital Markets','/capital-markets','navigation','Capital Markets'),
            new ShellCommandItem('capital_markets.instruments','Open Instruments','/capital-markets/instruments','navigation','Capital Markets'),
            new ShellCommandItem('capital_markets.relationships','Open Relationships','/capital-markets/relationships','navigation','Capital Markets'),
            new ShellCommandItem('capital_markets.venues','Open Venues','/capital-markets/venues','navigation','Capital Markets'),
            new ShellCommandItem('capital_markets.market_data','Open Market Data','/capital-markets/market-data','navigation','Capital Markets'),
        ];
    }

    public function workspaces(WebExtensionContext $context):array
    {
        return [
            new WorkspaceDefinition('capital_markets.overview','Capital Markets','/capital-markets',null,10),
            new WorkspaceDefinition('capital_markets.instruments','Capital Markets Instruments','/capital-markets/instruments',null,20),
            new WorkspaceDefinition('capital_markets.instrument','Capital Markets Instrument','/capital-markets/instruments','capital_markets.instrument',30),
            new WorkspaceDefinition('capital_markets.relationships','Capital Markets Relationships','/capital-markets/relationships',null,40),
            new WorkspaceDefinition('capital_markets.venues','Capital Markets Venues','/capital-markets/venues',null,50),
            new WorkspaceDefinition('capital_markets.market_data','Capital Markets Market Data','/capital-markets/market-data',null,60),
        ];
    }
}

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
            new NavigationContribution('capital-markets-opportunities','Opportunities','/capital-markets/opportunities',priority:20,parentKey:'capital-markets'),
            new NavigationContribution('capital-markets-markets','Markets','/capital-markets/markets',priority:30,parentKey:'capital-markets'),
            new NavigationContribution('capital-markets-research','Research','/capital-markets/research',priority:40,parentKey:'capital-markets'),
            new NavigationContribution('capital-markets-strategies','Strategies','/capital-markets/strategies',priority:50,parentKey:'capital-markets'),
            new NavigationContribution('capital-markets-portfolio','Portfolio','/capital-markets/portfolio',priority:60,parentKey:'capital-markets'),
            new NavigationContribution('capital-markets-allocation','Allocation','/capital-markets/allocation',priority:70,parentKey:'capital-markets'),
            new NavigationContribution('capital-markets-execution','Execution','/capital-markets/execution',priority:80,parentKey:'capital-markets'),
            new NavigationContribution('capital-markets-risk','Risk','/capital-markets/risk',priority:90,parentKey:'capital-markets'),
            new NavigationContribution('capital-markets-performance','Performance','/capital-markets/performance',priority:100,parentKey:'capital-markets'),
            new NavigationContribution('capital-markets-agents','Agents','/capital-markets/agents',priority:110,parentKey:'capital-markets'),
            new NavigationContribution('capital-markets-data-quality','Data Quality','/capital-markets/data-quality',priority:120,parentKey:'capital-markets'),
        ];
    }

    public function search(WebExtensionContext $context,string $query,int $limit=10):array
    {
        return $this->matcher->match([
            new SearchResult('capital_markets.search.overview','Capital Markets Overview','/capital-markets','workspace','Capital, profit, risk, opportunities and recommended action'),
            new SearchResult('capital_markets.search.opportunities','Opportunity Board','/capital-markets/opportunities','workspace','Portfolio-adjusted opportunities and expected net economics'),
            new SearchResult('capital_markets.search.markets','Market Explorer','/capital-markets/markets','workspace','Instrument, relationship and venue market views'),
            new SearchResult('capital_markets.search.research','Capital Markets Research','/capital-markets/research','workspace','Hypotheses, experiments, OOS, paper and rejected research'),
            new SearchResult('capital_markets.search.strategies','Capital Markets Strategies','/capital-markets/strategies','workspace','Strategy versions, scorecards and promotion gates'),
            new SearchResult('capital_markets.search.portfolio','Portfolio Command Center','/capital-markets/portfolio','workspace','Capital location, gross/net exposure and allocation'),
            new SearchResult('capital_markets.search.allocation','Allocation Workspace','/capital-markets/allocation','workspace','Constrained capital allocation and rebalance recommendations'),
            new SearchResult('capital_markets.search.execution','Execution Cockpit','/capital-markets/execution','workspace','Paper execution groups, hedge state and recovery evidence'),
            new SearchResult('capital_markets.search.risk','Risk Center','/capital-markets/risk','workspace','Risk state, limits, headroom and stress results'),
            new SearchResult('capital_markets.search.performance','Performance Center','/capital-markets/performance','workspace','Net performance, attribution, costs and edge funnel'),
            new SearchResult('capital_markets.search.agents','Capital Markets Agents','/capital-markets/agents','workspace','Research and Portfolio agent authority and recent runs'),
            new SearchResult('capital_markets.search.data_quality','Capital Markets Data Quality','/capital-markets/data-quality','workspace','Source health, stale data and market trust'),
            new SearchResult('capital_markets.search.instruments','Capital Markets Instruments','/capital-markets/instruments','workspace','Canonical instrument registry'),
            new SearchResult('capital_markets.search.relationships','Raw Economic Relationships','/capital-markets/relationships','workspace','Canonical economic relationship registry'),
            new SearchResult('capital_markets.search.venues','Capital Markets Venues','/capital-markets/venues','workspace','Canonical venue registry and capabilities'),
            new SearchResult('capital-markets-market-data','Market Data Administration','/capital-markets/market-data','workspace','Market source configuration and polling'),
            new SearchResult('capital_markets.search.tokenized_equity','Tokenized Equity Vertical Slice','/capital-markets/tokenized-equities','workspace','H1/H2 operator surface and paper execution'),
            new SearchResult('capital_markets.search.crypto_spot_perpetual','Crypto Spot / Perpetual Vertical Slice','/capital-markets/crypto-spot-perpetual','workspace','H4/H5/H6 operator surface and paper execution'),
        ],$query,$limit);
    }

    public function commands(WebExtensionContext $context):array
    {
        return [
            new ShellCommandItem('capital_markets.open','Open Capital Markets','/capital-markets','navigation','Decision Workspace'),
            new ShellCommandItem('capital_markets.opportunities','Open Opportunities','/capital-markets/opportunities','navigation','Capital Markets'),
            new ShellCommandItem('capital_markets.markets','Open Markets','/capital-markets/markets','navigation','Capital Markets'),
            new ShellCommandItem('capital_markets.research','Open Research','/capital-markets/research','navigation','Capital Markets'),
            new ShellCommandItem('capital_markets.strategies','Open Strategies','/capital-markets/strategies','navigation','Capital Markets'),
            new ShellCommandItem('capital_markets.portfolio','Open Portfolio','/capital-markets/portfolio','navigation','Capital Markets'),
            new ShellCommandItem('capital_markets.allocation','Open Allocation','/capital-markets/allocation','navigation','Capital Markets'),
            new ShellCommandItem('capital_markets.execution','Open Execution','/capital-markets/execution','navigation','Capital Markets'),
            new ShellCommandItem('capital_markets.risk','Open Risk','/capital-markets/risk','navigation','Capital Markets'),
            new ShellCommandItem('capital_markets.performance','Open Performance','/capital-markets/performance','navigation','Capital Markets'),
            new ShellCommandItem('capital_markets.agents','Open Agents','/capital-markets/agents','navigation','Capital Markets'),
            new ShellCommandItem('capital_markets.data_quality','Open Data Quality','/capital-markets/data-quality','navigation','Capital Markets'),
            new ShellCommandItem('capital_markets.instruments','Open Instrument Registry','/capital-markets/instruments','navigation','Capital Markets · Advanced'),
            new ShellCommandItem('capital_markets.market_data_admin','Open Market Data Admin','/capital-markets/market-data','navigation','Capital Markets · Advanced'),
        ];
    }

    public function workspaces(WebExtensionContext $context):array
    {
        return [
            new WorkspaceDefinition('capital_markets.overview','Capital Markets Overview','/capital-markets',null,10),
            new WorkspaceDefinition('capital_markets.opportunities','Opportunity Board','/capital-markets/opportunities',null,20),
            new WorkspaceDefinition('capital_markets.opportunity','Opportunity','/capital-markets/opportunities','capital_markets.opportunity',30),
            new WorkspaceDefinition('capital_markets.markets','Market Explorer','/capital-markets/markets',null,40),
            new WorkspaceDefinition('capital_markets.research','Capital Markets Research','/capital-markets/research',null,50),
            new WorkspaceDefinition('capital_markets.hypothesis','Research Hypothesis','/capital-markets/research/hypotheses','capital_markets.hypothesis',60),
            new WorkspaceDefinition('capital_markets.strategies','Strategies','/capital-markets/strategies',null,70),
            new WorkspaceDefinition('capital_markets.strategy','Strategy','/capital-markets/strategies','capital_markets.strategy',80),
            new WorkspaceDefinition('capital_markets.portfolio','Portfolio Command Center','/capital-markets/portfolio',null,90),
            new WorkspaceDefinition('capital_markets.allocation','Allocation Workspace','/capital-markets/allocation',null,100),
            new WorkspaceDefinition('capital_markets.execution','Execution Cockpit','/capital-markets/execution',null,110),
            new WorkspaceDefinition('capital_markets.execution_detail','Execution','/capital-markets/execution','capital_markets.execution',120),
            new WorkspaceDefinition('capital_markets.risk','Risk Center','/capital-markets/risk',null,130),
            new WorkspaceDefinition('capital_markets.performance','Performance Center','/capital-markets/performance',null,140),
            new WorkspaceDefinition('capital_markets.agents','Agent Center','/capital-markets/agents',null,150),
            new WorkspaceDefinition('capital_markets.data_quality','Data Quality Center','/capital-markets/data-quality',null,160),
            new WorkspaceDefinition('capital_markets.instruments','Capital Markets Instruments','/capital-markets/instruments',null,200),
            new WorkspaceDefinition('capital_markets.instrument','Capital Markets Instrument','/capital-markets/instruments','capital_markets.instrument',210),
            new WorkspaceDefinition('capital_markets.relationships','Raw Economic Relationships','/capital-markets/relationships',null,220),
            new WorkspaceDefinition('capital_markets.venues','Capital Markets Venues','/capital-markets/venues',null,230),
            new WorkspaceDefinition('capital_markets.market_data','Market Data Administration','/capital-markets/market-data',null,240),
            new WorkspaceDefinition('capital_markets.tokenized_equity','Tokenized Equity Vertical Slice','/capital-markets/tokenized-equities',null,250),
            new WorkspaceDefinition('capital_markets.crypto_spot_perpetual','Crypto Spot / Perpetual Vertical Slice','/capital-markets/crypto-spot-perpetual',null,260),
        ];
    }
}

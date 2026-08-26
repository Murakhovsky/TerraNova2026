<?php
declare(strict_types=1);

use Domains\Sales\Automation\Agent\SalesIntelligenceAgent;
use Domains\Sales\Automation\Policy\SalesPolicyCatalog;
use Domains\Sales\Automation\Rule\SalesRuleCatalog;
use Domains\Sales\Bootstrap\SalesDomainModule;

$di->setShared('salesDomainModule', fn (): SalesDomainModule => new SalesDomainModule(
    $this->getShared('cosCrmGateway'),
    $this->getShared('salesDealRepository'),
    $this->getShared('salesMessageGateway'),
    $this->getShared('salesFollowupRepository'),
    $this->getShared('salesRuleContextProvider'),
    $this->getShared('salesAgentContextBuilder'),
));

// Compatibility service names for existing CLI/UI consumers during the modular migration.
$di->setShared('salesDeterministicProcessCatalog', fn (): SalesRuleCatalog => new SalesRuleCatalog());
$di->setShared('salesPolicyCatalog', fn (): SalesPolicyCatalog => new SalesPolicyCatalog());
$di->setShared('salesIntelligenceAgent', fn () => SalesIntelligenceAgent::definition());

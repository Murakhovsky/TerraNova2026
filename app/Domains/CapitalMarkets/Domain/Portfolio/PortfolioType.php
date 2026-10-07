<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Portfolio;
enum PortfolioType:string { case Research='RESEARCH'; case Paper='PAPER'; case Live='LIVE'; case Master='MASTER'; case Strategy='STRATEGY'; case UserTenant='USER_TENANT'; }

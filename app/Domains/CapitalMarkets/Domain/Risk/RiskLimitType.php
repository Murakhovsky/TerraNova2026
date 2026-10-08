<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Risk;
enum RiskLimitType:string { case Absolute='ABSOLUTE'; case PercentOfEquity='PERCENT_OF_EQUITY'; case PercentOfCapital='PERCENT_OF_CAPITAL'; case Dynamic='DYNAMIC'; case Soft='SOFT'; case Hard='HARD'; }

<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Opportunity;
enum SpreadDirection:string { case BuyA_SellB='BUY_A_SELL_B'; case BuyB_SellA='BUY_B_SELL_A'; }

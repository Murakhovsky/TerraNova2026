<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Risk;
enum ValuationSource:string { case MarketMid='MARKET_MID'; case ExecutableBidAsk='EXECUTABLE_BID_ASK'; case MarkPrice='MARK_PRICE'; case ReferencePrice='REFERENCE_PRICE'; case Nav='NAV'; }
